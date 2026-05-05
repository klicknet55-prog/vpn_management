<?php
/**
 * Payment Controller
 *
 * POST  action=create_invoice  → buat invoice + redirect ke iPaymu
 * GET   action=history         → riwayat pembayaran user
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/IPaymuService.php';
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
 * Buat payment + invoice, lalu ambil URL pembayaran dari iPaymu.
 */
function handleCreateInvoice(PDO $db, int $userId, array $input): void
{
    $planId = (int) ($input['plan_id'] ?? 0);
    $name   = trim($input['name']  ?? '');
    $email  = trim($input['email'] ?? '');
    $phone  = trim($input['phone'] ?? '');

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

    $amount = (int) $plan['price_idr'];
    if ($amount <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Paket ini tidak memerlukan pembayaran.']);
        return;
    }

    // Periksa iPaymu config
    $pgConfig = getPaymentGatewayConfig($db);
    $va     = $pgConfig['va'];
    $apiKey = $pgConfig['api_key'];
    if (!$va || !$apiKey) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Payment gateway belum dikonfigurasi. Hubungi admin.']);
        return;
    }

    // Generate invoice number
    $paymentModel = new Payment($db);
    $invoiceNumber = $paymentModel->generateInvoiceNumber();
    $expiredAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

    // Simpan payment dengan status pending
    $paymentId = $paymentModel->create([
        'user_id'            => $userId,
        'plan_id'            => $planId,
        'amount'             => $amount,
        'invoice_number'     => $invoiceNumber,
        'status'             => 'pending',
        'invoice_expired_at' => $expiredAt,
        'notes'              => "Pembayaran paket {$plan['label']} oleh user_id={$userId}",
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

    // Panggil iPaymu
    $ipaymu = new IPaymuService($va, $apiKey, $pgConfig['base_url']);

    $notifyUrl = APP_URL . '/api/payment-webhook.php';
    $returnUrl = APP_URL . '/user/payment-return.php?invoice=' . urlencode($invoiceNumber) . '&status=success';
    $cancelUrl = APP_URL . '/user/payment-return.php?invoice=' . urlencode($invoiceNumber) . '&status=cancel';

    $result = $ipaymu->createPayment([
        'product'     => [$plan['label'] . ' – ' . $plan['duration_days'] . ' hari'],
        'qty'         => [1],
        'price'       => [$amount],
        'amount'      => $amount,
        'returnUrl'   => $returnUrl,
        'cancelUrl'   => $cancelUrl,
        'notifyUrl'   => $notifyUrl,
        'buyerName'   => $name,
        'buyerEmail'  => $email,
        'buyerPhone'  => $phone,
        'referenceId' => $invoiceNumber,
        'expired'     => 24,
    ]);

    if (!$result['ok']) {
        // Tandai payment sebagai failed
        $paymentModel->updateAfterPayment($paymentId, [
            'status'  => 'failed',
            'paid_at' => null,
        ]);
        $invoiceModel->updateStatus($invoiceId, 'cancelled');

        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'Gagal membuat pembayaran: ' . $result['error']]);
        return;
    }

    // Simpan payment_url dan session_id ke invoice
    $invoiceModel->setPaymentUrl($invoiceId, $result['payment_url']);
    $invoiceModel->updateStatus($invoiceId, 'sent');

    // Simpan session_id ke payment notes
    $db->prepare(
        'UPDATE payments SET notes = CONCAT(COALESCE(notes,""), ?, " session=", ?)
         WHERE id = ?'
    )->execute(['', $result['session_id'] ?? '', $paymentId]);

    echo json_encode([
        'ok'   => true,
        'data' => [
            'invoice_number' => $invoiceNumber,
            'payment_url'    => $result['payment_url'],
            'session_id'     => $result['session_id'],
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
