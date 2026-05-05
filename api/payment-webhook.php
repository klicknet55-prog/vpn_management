<?php
/**
 * Duitku Payment Webhook Handler
 *
 * Duitku mengirim callback POST ke endpoint ini.
 * Endpoint: POST /api/payment-webhook.php
 *
 * Field penting callback:
 * - merchantCode
 * - amount
 * - merchantOrderId (invoice number kita)
 * - resultCode (00=success, 01=pending, 02=failed/expired)
 * - reference
 * - signature
 */

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../system/models/Payment.php';
require_once __DIR__ . '/../system/models/Invoice.php';
require_once __DIR__ . '/../system/models/PaymentNotification.php';
require_once __DIR__ . '/../system/services/SubscriptionService.php';
require_once __DIR__ . '/../system/services/NotificationService.php';

if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

$rawBody = file_get_contents('php://input');
$payload = $_POST;
if (empty($payload)) {
    $decoded = json_decode((string) $rawBody, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    } else {
        parse_str((string) $rawBody, $payload);
    }
}

$merchantCode   = trim((string) ($payload['merchantCode'] ?? ''));
$amountRaw      = trim((string) ($payload['amount'] ?? '0'));
$amountPaid     = (int) round((float) $amountRaw);
$invoiceNumber  = trim((string) ($payload['merchantOrderId'] ?? ''));
$resultCode     = trim((string) ($payload['resultCode'] ?? ''));
$reference      = trim((string) ($payload['reference'] ?? ''));
$incomingSig    = trim((string) ($payload['signature'] ?? ''));

if ($merchantCode === '' || $invoiceNumber === '' || $incomingSig === '') {
    error_log('payment-webhook reject: missing fields merchant=' . $merchantCode . ' invoice=' . $invoiceNumber);
    http_response_code(400);
    echo 'Missing required fields';
    exit;
}

try {
    $db = getDB();
    $pgCfg = getPaymentGatewayConfig($db);

    if ($merchantCode !== (string) ($pgCfg['merchant_code'] ?? '')) {
        error_log('payment-webhook reject: invalid merchant code incoming=' . $merchantCode);
        http_response_code(403);
        echo 'Invalid merchant code';
        exit;
    }

    $expectedSig = md5($merchantCode . $amountRaw . $invoiceNumber . (string) ($pgCfg['api_key'] ?? ''));
    if (!hash_equals(strtolower($expectedSig), strtolower($incomingSig))) {
        error_log('payment-webhook reject: invalid signature invoice=' . $invoiceNumber . ' amount=' . $amountRaw);
        http_response_code(403);
        echo 'Invalid signature';
        exit;
    }

    $paymentModel = new Payment($db);
    $invoiceModel = new Invoice($db);
    $notifModel = new PaymentNotification($db);

    $payment = $paymentModel->findByInvoiceNumber($invoiceNumber);
    $invoiceByNumber = null;
    if (!$payment) {
        // Fallback: beberapa data lama hanya konsisten di tabel invoices.
        $invoiceByNumber = $invoiceModel->findByNumber($invoiceNumber);
        if ($invoiceByNumber && !empty($invoiceByNumber['payment_id'])) {
            $payment = $paymentModel->findById((int) $invoiceByNumber['payment_id']);
        }
    }

    $paymentId = $payment ? (int) $payment['id'] : null;

    $notifModel->log([
        'payment_id' => $paymentId,
        'raw_payload' => $payload,
        'notification_type' => 'payment',
        'status_code' => $resultCode,
        'trx_id' => $reference,
    ]);

    if (!$payment) {
        error_log('payment-webhook info: invoice not found invoice=' . $invoiceNumber . ' amount=' . $amountRaw . ' result=' . $resultCode);
        http_response_code(200);
        echo 'OK (invoice not found, logged)';
        exit;
    }

    if ($payment['status'] === 'paid') {
        http_response_code(200);
        echo 'OK (already paid)';
        exit;
    }

    $newStatus = match ($resultCode) {
        '00' => 'paid',
        '01' => 'pending',
        '02' => 'expired',
        default => 'failed',
    };

    $paidAt = ($newStatus === 'paid') ? date('Y-m-d H:i:s') : null;

    $paymentModel->updateAfterPayment((int) $payment['id'], [
        'status' => $newStatus,
        'transaction_id' => $reference,
        'session_id' => null,
        'payment_method' => 'duitku',
        'paid_at' => $paidAt,
    ]);

    if ($invoiceByNumber === null) {
        $invoiceByNumber = $invoiceModel->findByNumber($invoiceNumber);
    }
    if ($invoiceByNumber) {
        $invStatus = match ($newStatus) {
            'paid' => 'paid',
            'failed', 'expired' => 'cancelled',
            default => 'sent',
        };
        $invoiceModel->updateStatus((int) $invoiceByNumber['id'], $invStatus);
    }

    if ($newStatus === 'paid') {
        $subUserId = (int) $payment['user_id'];
        $subPlanId = (int) $payment['plan_id'];
        error_log('payment-webhook activate: user_id=' . $subUserId . ' plan_id=' . $subPlanId . ' invoice=' . $invoiceNumber);
        $subService = new SubscriptionService($db);
        $activated = $subService->activate($subUserId, $subPlanId);
        error_log('payment-webhook activate result: ' . ($activated ? 'OK' : 'FALSE/plan_not_found') . ' user=' . $subUserId . ' plan=' . $subPlanId);

        try {
            $userRow = $db->prepare('SELECT email, full_name, phone_number FROM users WHERE id = ? LIMIT 1');
            $userRow->execute([(int) $payment['user_id']]);
            $user = $userRow->fetch();
            if ($user) {
                $notif = new NotificationService();
                $paymentData = [
                    'invoice_number' => $invoiceNumber,
                    'plan_label' => $payment['plan_label'] ?? '',
                    'amount' => $payment['amount'],
                    'paid_at' => $paidAt,
                ];
                if (!empty($user['email'])) {
                    $notif->sendPaymentSuccess($user['email'], $user['full_name'] ?? 'User', $paymentData);
                }
                if (!empty($user['phone_number'])) {
                    $notif->sendWaPaymentSuccess($user['phone_number'], $user['full_name'] ?? 'User', $paymentData);
                }
            }
        } catch (Throwable) {
            // notifikasi gagal tidak menggagalkan webhook
        }
    }

    http_response_code(200);
    echo 'OK';
} catch (Throwable $e) {
    error_log('payment-webhook error: ' . $e->getMessage());
    http_response_code(200);
    echo 'OK (internal error logged)';
}
