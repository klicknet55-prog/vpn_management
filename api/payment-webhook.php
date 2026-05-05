<?php
/**
 * iPaymu Payment Webhook Handler
 *
 * iPaymu mengirim POST ke URL ini setelah transaksi selesai/gagal.
 * Endpoint: POST /api/payment-webhook.php
 *
 * Payload yang dikirim iPaymu (form-encoded atau JSON):
 *   trx_id          — ID transaksi iPaymu
 *   status          — berisi status_code: 1=success, 2=pending, 3=failed, 4=expired
 *   reference_id    — referenceId yang kita kirim (invoice_number kita)
 *   session_id      — session ID iPaymu
 *   amount          — jumlah terbayar
 *   payment_method
 *   payment_channel
 */

// Tidak butuh output HTML, ini pure API endpoint
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/../system/models/Payment.php';
require_once __DIR__ . '/../system/models/Invoice.php';
require_once __DIR__ . '/../system/models/PaymentNotification.php';
require_once __DIR__ . '/../system/services/SubscriptionService.php';
require_once __DIR__ . '/../system/services/NotificationService.php';

// Hanya izinkan POST
if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

// ── Baca payload ──────────────────────────────────────────────────────────────
$rawBody = file_get_contents('php://input');

// iPaymu bisa kirim form-encoded ATAU JSON
$payload = [];
parse_str($rawBody, $payload);
if (empty($payload)) {
    $payload = json_decode($rawBody, true) ?? [];
}
if (empty($payload)) {
    $payload = $_POST;
}

// ── Verifikasi signature iPaymu ───────────────────────────────────────────────
// iPaymu menandatangani webhook dengan header Signature yang sama seperti request:
// SHA256( "POST" + ":" + VA + ":" + SHA256(body) + ":" + API_KEY )
$_pgCfg = getPaymentGatewayConfig(getDB());
$va     = $_pgCfg['va'];
$apiKey = $_pgCfg['api_key'];
unset($_pgCfg);

if ($va && $apiKey) {
    $incomingSig = $_SERVER['HTTP_SIGNATURE'] ?? $_SERVER['HTTP_X_SIGNATURE'] ?? '';
    if ($incomingSig !== '') {
        $expectedSig = hash('sha256', 'post:' . $va . ':' . hash('sha256', $rawBody) . ':' . $apiKey);
        if (!hash_equals($expectedSig, strtolower($incomingSig))) {
            http_response_code(403);
            echo 'Invalid signature';
            exit;
        }
    }
}

// ── Ambil field kunci ─────────────────────────────────────────────────────────
$trxId         = trim((string) ($payload['trx_id']         ?? $payload['transaction_id'] ?? ''));
$statusCode    = (int) ($payload['status']         ?? $payload['status_code'] ?? 0);
$referenceId   = trim((string) ($payload['reference_id']   ?? $payload['referenceId']   ?? ''));
$sessionId     = trim((string) ($payload['session_id']     ?? ''));
$amountPaid    = (int) ($payload['amount'] ?? 0);
$paymentMethod = trim((string) ($payload['payment_method']  ?? ''));

if (!$trxId || !$referenceId) {
    http_response_code(400);
    echo 'Missing trx_id or reference_id';
    exit;
}

try {
    $db            = getDB();
    $paymentModel  = new Payment($db);
    $invoiceModel  = new Invoice($db);
    $notifModel    = new PaymentNotification($db);

    // Cari payment berdasarkan invoice_number (reference_id)
    $payment = $paymentModel->findByInvoiceNumber($referenceId);
    $paymentId = $payment ? (int) $payment['id'] : null;

    // Log notifikasi mentah untuk audit
    $notifModel->log([
        'payment_id'        => $paymentId,
        'raw_payload'       => $payload,
        'notification_type' => 'payment',
        'status_code'       => $statusCode,
        'trx_id'            => $trxId,
    ]);

    if (!$payment) {
        // Invoice tidak ditemukan, tapi kita sudah log. Kembalikan 200 agar iPaymu tidak retry.
        http_response_code(200);
        echo 'OK (invoice not found, logged)';
        exit;
    }

    // Jangan proses ulang yang sudah berhasil
    if ($payment['status'] === 'paid') {
        http_response_code(200);
        echo 'OK (already paid)';
        exit;
    }

    // ── Mapping status iPaymu ─────────────────────────────────────────────────
    // 1 = berhasil, 2 = pending, 3 = gagal, 4 = expired
    $newStatus = match ($statusCode) {
        1       => 'paid',
        2       => 'pending',
        3       => 'failed',
        4       => 'expired',
        default => 'failed',
    };

    $paidAt = ($newStatus === 'paid') ? date('Y-m-d H:i:s') : null;

    // Update tabel payments
    $paymentModel->updateAfterPayment((int) $payment['id'], [
        'status'                  => $newStatus,
        'ipaymu_transaction_id'   => $trxId,
        'ipaymu_session_id'       => $sessionId,
        'payment_method'          => $paymentMethod,
        'paid_at'                 => $paidAt,
    ]);

    // Update invoice
    $invoiceByNumber = $invoiceModel->findByNumber($referenceId);
    if ($invoiceByNumber) {
        $invStatus = match ($newStatus) {
            'paid'    => 'paid',
            'failed', 'expired' => 'cancelled',
            default   => 'sent',
        };
        $invoiceModel->updateStatus((int) $invoiceByNumber['id'], $invStatus);
    }

    // Jika sukses bayar → aktifkan subscription + kirim email konfirmasi
    if ($newStatus === 'paid') {
        $subService = new SubscriptionService($db);
        $subService->activate((int) $payment['user_id'], (int) $payment['plan_id']);

        // Kirim email + WA konfirmasi ke user
        try {
            $userRow = $db->prepare('SELECT email, full_name, phone_number FROM users WHERE id = ? LIMIT 1');
            $userRow->execute([(int) $payment['user_id']]);
            $user = $userRow->fetch();
            if ($user) {
                $notif = new NotificationService();
                $paymentData = [
                    'invoice_number' => $referenceId,
                    'plan_label'     => $payment['plan_label'] ?? '',
                    'amount'         => $payment['amount'],
                    'paid_at'        => $paidAt,
                ];
                if (!empty($user['email'])) {
                    $notif->sendPaymentSuccess($user['email'], $user['full_name'] ?? 'User', $paymentData);
                }
                if (!empty($user['phone_number'])) {
                    $notif->sendWaPaymentSuccess($user['phone_number'], $user['full_name'] ?? 'User', $paymentData);
                }
            }
        } catch (Throwable) { /* notifikasi gagal tidak gagalkan webhook */ }
    }

    http_response_code(200);
    echo 'OK';

} catch (Throwable $e) {
    // Log error tapi tetap return 200 agar iPaymu tidak retry terus
    error_log('payment-webhook error: ' . $e->getMessage());
    http_response_code(200);
    echo 'OK (internal error logged)';
}
