<?php
/**
 * Payment Controller
 *
 * POST  action=create_invoice  → buat invoice + redirect ke Duitku
 * GET   action=history         → riwayat pembayaran user
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/DuitkuService.php';
require_once __DIR__ . '/invoice-link.php';
require_once __DIR__ . '/../system/models/Plan.php';
require_once __DIR__ . '/../system/models/Payment.php';
require_once __DIR__ . '/../system/models/Invoice.php';

secureSessionStart();
setCorsHeaders(false);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$method = strtoupper($_SERVER['REQUEST_METHOD']);

try {
    $db = getDB();

    if ($method === 'POST') {
        $input  = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = trim($input['action'] ?? '');

        if ($action === 'create_invoice') {
            handleCreateInvoice($db, $userId, $input);
            exit;
        }

        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
        exit;
    }

    if ($method === 'GET') {
        $action = trim($_GET['action'] ?? 'history');

        if ($action === 'history') {
            handleHistory($db, $userId);
            exit;
        }

        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

// ─────────────────────────────────────────────────────────────────────────────

/**
 * Buat payment + invoice, lalu ambil URL pembayaran dari Duitku.
 */
function handleCreateInvoice(PDO $db, int $userId, array $input): void
{
    $planId = (int) ($input['plan_id'] ?? 0);
    $name   = trim($input['name']  ?? '');
    $email  = trim($input['email'] ?? '');
    $phone  = trim($input['phone'] ?? '');
    $requestedPaymentMethod = strtoupper(trim((string) ($input['payment_method'] ?? '')));

    if ($planId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'plan_id wajib diisi.']);
        return;
    }
    if (!$name || !$email || !$phone) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'name, email, dan phone wajib diisi.']);
        return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Format email tidak valid.']);
        return;
    }

    // Validasi plan
    $planModel = new Plan($db);
    $plan = $planModel->findById($planId);
    if (!$plan) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Paket tidak ditemukan.']);
        return;
    }

    $amountRaw = $plan['price_idr'] ?? $plan['price'] ?? 0;
    $amount = (int) $amountRaw;
    if ($amount <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Paket ini tidak memerlukan pembayaran.']);
        return;
    }

    // Periksa Duitku config
    $pgConfig = getPaymentGatewayConfig($db);
    $merchantCode = $pgConfig['merchant_code'];
    $apiKey = $pgConfig['api_key'];
    $enabledMethods = is_array($pgConfig['enabled_payment_methods'] ?? null)
        ? $pgConfig['enabled_payment_methods']
        : [];
    if (!$merchantCode || !$apiKey) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Payment gateway belum dikonfigurasi. Hubungi admin.']);
        return;
    }
    if (empty($enabledMethods)) {
        $enabledMethods = ['BC'];
    }

    if ($requestedPaymentMethod !== '' && !in_array($requestedPaymentMethod, $enabledMethods, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Metode pembayaran tidak diizinkan oleh konfigurasi admin.']);
        return;
    }

    $candidateMethods = $requestedPaymentMethod !== '' ? [$requestedPaymentMethod] : $enabledMethods;

    $planLabel = (string) ($plan['label'] ?? $plan['name'] ?? 'Subscription Plan');
    $planDuration = (int) ($plan['duration_days'] ?? 30);

    // Generate invoice number dengan pengecekan keunikan
    $paymentModel = new Payment($db);
    $invoiceNumber = null;
    $maxRetries = 10;
    for ($i = 0; $i < $maxRetries; $i++) {
        $candidate = $paymentModel->generateInvoiceNumber();
        $existing = $paymentModel->findByInvoiceNumber($candidate);
        if (!$existing) {
            $invoiceNumber = $candidate;
            break;
        }
    }
    if (!$invoiceNumber) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Gagal membuat nomor invoice unik. Coba lagi dalam beberapa detik.']);
        return;
    }
    $expiredAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

    // Simpan payment dengan status pending
    $paymentId = $paymentModel->create([
        'user_id'            => $userId,
        'plan_id'            => $planId,
        'amount'             => $amount,
        'invoice_number'     => $invoiceNumber,
        'status'             => 'pending',
        'invoice_expired_at' => $expiredAt,
        'notes'              => "Pembayaran paket {$planLabel} oleh user_id={$userId}",
    ]);

    // Simpan invoice
    $invoiceModel = new Invoice($db);
    $invoiceId = $invoiceModel->create([
        'user_id'        => $userId,
        'payment_id'     => $paymentId,
        'plan_id'        => $planId,
        'invoice_number' => $invoiceNumber,
        'amount'         => $amount,
        'due_date'       => $expiredAt,
        'status'         => 'draft',
        'payment_url'    => null,
    ]);

    // Panggil Duitku
    $duitku = new DuitkuService($merchantCode, $apiKey, $pgConfig['base_url']);

    $notifyUrl = APP_URL . '/api/payment-webhook.php';
    $publicInvoiceUrl = invoicePublicBuildUrl($invoiceNumber, time() + (7 * 24 * 3600), 'success');
    $returnUrl = $publicInvoiceUrl;

    $customerVaName = preg_replace('/\s+/', ' ', $name) ?? $name;
    $customerVaName = trim($customerVaName);
    if (function_exists('mb_substr')) {
        $customerVaName = mb_substr($customerVaName, 0, 20, 'UTF-8');
    } else {
        $customerVaName = substr($customerVaName, 0, 20);
    }
    if ($customerVaName === '') {
        $customerVaName = 'Customer';
    }

    $customerDetail = [
        'firstName' => $name,
        'lastName' => '',
        'email' => $email,
        'phoneNumber' => $phone,
    ];

    $result = null;
    $paymentMethod = '';
    $attemptErrors = [];

    foreach ($candidateMethods as $candidate) {
        $tryResult = $duitku->createInvoice([
            'merchantOrderId' => $invoiceNumber,
            'paymentAmount'   => $amount,
            'paymentMethod'   => $candidate,
            'productDetails'  => $planLabel . ' - ' . $planDuration . ' hari',
            'email'           => $email,
            'phoneNumber'     => $phone,
            'customerVaName'  => $customerVaName,
            'callbackUrl'     => $notifyUrl,
            'returnUrl'       => $returnUrl,
            'expiryPeriod'    => 1440,
            'customerDetail'  => $customerDetail,
            'itemDetails'     => [
                [
                    'name'     => $planLabel . ' - ' . $planDuration . ' hari',
                    'price'    => $amount,
                    'quantity' => 1,
                ],
            ],
        ]);

        if (!empty($tryResult['ok'])) {
            $result = $tryResult;
            $paymentMethod = (string) $candidate;
            break;
        }

        $attemptErrors[] = (string) ($candidate . ': ' . ($tryResult['error'] ?? 'Unknown error'));
        $result = $tryResult;
    }

    if (!$result || !$result['ok']) {
        // Tandai payment sebagai failed (simple update, tanpa kolom gateway)
        $db->prepare('UPDATE payments SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
           ->execute(['failed', $paymentId]);
        $invoiceModel->updateStatus($invoiceId, 'cancelled');

        $httpStatus = (int) ($result['http_status'] ?? 0);
        $gatewayMsg = (string) ($result['error'] ?? 'Unknown error');
        if (!empty($attemptErrors)) {
            $gatewayMsg .= ' | metode dicoba: ' . implode('; ', $attemptErrors);
        }

        // Log aman (tanpa API key/signature) untuk investigasi issue gateway.
        error_log('[DuitkuCreateInvoiceFail] invoice=' . $invoiceNumber
            . ' merchant=' . substr($merchantCode, 0, 4) . '***'
            . ' amount=' . $amount
            . ' http=' . $httpStatus
            . ' msg=' . $gatewayMsg);

        if ($httpStatus >= 500 || stripos($gatewayMsg, 'An error has occurred') !== false) {
            $gatewayMsg = 'Gateway Duitku gagal memproses request (HTTP 500). Cek Merchant Code/API Key sandbox, status akun merchant, dan whitelist/IP di dashboard Duitku.';
        }

        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'Gagal membuat pembayaran: ' . $gatewayMsg]);
        return;
    }

    // Simpan payment_url ke invoice
    $invoiceModel->setPaymentUrl($invoiceId, $result['payment_url']);
    $invoiceModel->updateStatus($invoiceId, 'sent');

    // Simpan reference Duitku ke payment notes
    $db->prepare(
        'UPDATE payments SET notes = CONCAT(COALESCE(notes,""), ?, " reference=", ?)
         WHERE id = ?'
    )->execute(['', $result['reference'] ?? '', $paymentId]);
    $db->prepare('UPDATE payments SET payment_method = ? WHERE id = ?')
       ->execute([$paymentMethod, $paymentId]);

    echo json_encode([
        'ok'   => true,
        'data' => [
            'invoice_number' => $invoiceNumber,
            'payment_url'    => $result['payment_url'],
            'public_invoice_url' => $publicInvoiceUrl,
            'reference'      => $result['reference'] ?? '',
            'payment_method' => $paymentMethod,
            'amount'         => $amount,
            'expired_at'     => $expiredAt,
        ],
    ]);
}

/**
 * Riwayat pembayaran user.
 */
function handleHistory(PDO $db, int $userId): void
{
    $paymentModel = new Payment($db);
    $payments     = $paymentModel->getByUserId($userId);

    echo json_encode(['ok' => true, 'data' => $payments]);
}
