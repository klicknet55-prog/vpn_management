<?php
/**
 * WA Send Message — Public API Endpoint
 * ─────────────────────────────────────────────────────────────────────────────
 * Endpoint publik untuk mengirim pesan WhatsApp via GoWA.
 * Validasi secret key, proxy ke GoWA dengan Basic Auth (server-side).
 *
 * Mode GET aktif untuk kompatibilitas URL API:
 *   /wa-send.php?phone=628xxx&message=Hello&secret=xxxx
 *
 * POST juga tetap didukung:
 *   1. Header:    X-WA-Secret: <secret>          ← direkomendasikan
 *   2. JSON body: { "phone":"...", "message":"...", "secret":"..." }
 *
 * Response: JSON
 *   Success: { "status": "success", "message": "Message sent", "code": 200 }
 *   Error:   { "error": "...", "code": 4xx/5xx }
 * ─────────────────────────────────────────────────────────────────────────────
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');

require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/gowa.php';
require_once __DIR__ . '/api/security.php';
require_once __DIR__ . '/api/wa-send-runtime.php';

// Public endpoint — allow any caller
setCorsHeaders(true);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// ── Method validation ─────────────────────────────────────────────────────────
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET' && $method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use GET or POST.', 'code' => 405]);
    exit;
}

// ── Input ─────────────────────────────────────────────────────────────────────
$body = ($method === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
$phone = trim((string) ($_GET['phone'] ?? $body['phone'] ?? ''));
$message = trim((string) ($_GET['message'] ?? $body['message'] ?? ''));
$useQueue = waRequestedQueueValue($_GET['use_queue'] ?? $body['use_queue'] ?? null);

// Secret: prefer X-WA-Secret header, fallback to body field
$secret = trim((string) ($_SERVER['HTTP_X_WA_SECRET'] ?? $_GET['secret'] ?? $body['secret'] ?? ''));

// ── Validate required parameters ─────────────────────────────────────────────
if (!$phone || !$message || !$secret) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Missing required parameters: phone, message, secret',
        'code'  => 400,
        'hint'  => 'Use GET query params (?phone=&message=&secret=) or POST JSON body with X-WA-Secret/body secret.',
    ]);
    exit;
}


// ── Validate secret against DB ────────────────────────────────────────────────
try {
    $db   = getDB();
    $stmt = $db->prepare('SELECT id, device_id, label, status FROM wa_accounts WHERE secret = ? LIMIT 1');
    $stmt->execute([$secret]);
    $account = $stmt->fetch();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error', 'code' => 500]);
    exit;
}

if (!$account) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid secret key', 'code' => 401]);
    exit;
}

if ($account['status'] !== 'connected') {
    http_response_code(403);
    echo json_encode([
        'error'  => 'Device is not connected. Current status: ' . $account['status'],
        'code'   => 403,
        'device' => $account['label'],
    ]);
    exit;
}

// ── Normalize phone number ────────────────────────────────────────────────────
$phone = preg_replace('/\D+/', '', $phone);

if (str_starts_with($phone, '0')) {
    $phone = '62' . substr($phone, 1);
}

if (strlen($phone) < 7 || strlen($phone) > 15) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid phone number format', 'code' => 400]);
    exit;
}

try {
    $deviceId = (string) $account['device_id'];
    $shouldUseQueue = waShouldUseQueue('user', $useQueue, $db);

    if ($shouldUseQueue) {
        $queueId = enqueueWaMessage($db, $deviceId, $phone, $message, 'wa_send_public_api', 'user', [
            'account_id' => (int) ($account['id'] ?? 0),
            'label' => (string) ($account['label'] ?? ''),
        ]);

        http_response_code(202);
        echo json_encode([
            'status' => 'queued',
            'message' => 'Message queued successfully',
            'code' => 202,
            'queue_id' => $queueId,
            'device_id' => $deviceId,
        ]);
        exit;
    }

    $sendResult = waSendNow($db, $deviceId, $phone, $message, 'wa_send_public_api');
    echo json_encode([
        'status' => 'success',
        'message' => 'Message sent successfully',
        'code' => 200,
        'delay_ms' => $sendResult['delay_ms'],
        'base_delay_ms' => $sendResult['base_delay_ms'],
        'rate_limit_pause_ms' => $sendResult['rate_limit_pause_ms'],
        'data' => $sendResult['response'],
    ]);
} catch (Exception $e) {
    $msg = (string) $e->getMessage();
    $normalizedMsg = strtolower($msg);

    if (str_contains($normalizedMsg, 'device not found') || str_contains($normalizedMsg, 'valid x-device-id')) {
        // Keep local status in sync when device no longer exists on GoWA side.
        try {
            $upd = $db->prepare('UPDATE wa_accounts SET status = ?, updated_at = NOW() WHERE id = ?');
            $upd->execute(['disconnected', (int) ($account['id'] ?? 0)]);
        } catch (Throwable $syncErr) {
            // Ignore sync failure; main error response is still returned below.
        }

        http_response_code(404);
        echo json_encode([
            'error' => 'Device tidak ditemukan di server GoWA. Silakan buat/scan ulang device.',
            'code' => 404,
            'device_id' => (string) ($account['device_id'] ?? ''),
            'hint' => 'Buat device dari menu WA Devices, scan QR, lalu gunakan secret terbaru.',
            'upstream_error' => $msg,
        ]);
        exit;
    }

    http_response_code(500);
    echo json_encode(['error' => $msg, 'code' => 500]);
}
