<?php
/**
 * WA Account REST API
 *
 * Role policy:
 * - Admin: can see and manage all devices.
 * - User: can only see and manage own devices.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gowa.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/wa-send-runtime.php';
require_once __DIR__ . '/../system/middleware/subscription.php';

session_start();

header('Content-Type: application/json');
setCorsHeaders(false);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD']);
$action = trim($_GET['action'] ?? '');
$currentUser = requireAuthUser();

// Ensure queue_enabled column exists
try {
    $db = getDB();
    $db->exec('ALTER TABLE wa_accounts ADD COLUMN queue_enabled BOOLEAN NOT NULL DEFAULT 1 AFTER disconnected_at');
} catch (Throwable $e) {
    // Column may already exist
}

try {
    if ($method === 'GET' && $action === '') {
        handleList($currentUser);
        return;
    }
    if ($method === 'GET' && $action === 'status') {
        handleStatus($currentUser);
        return;
    }
    if ($method === 'GET' && $action === 'qr') {
        handleQR($currentUser);
        return;
    }
    if ($method === 'GET' && $action === 'test_send') {
        handleTestSend($currentUser);
        return;
    }
    if ($method === 'POST' && $action === '' && !isset($_GET['device_id'])) {
        $db = getDB();
        requireActiveSubscription($currentUser['id'], $db);
        requireFeatureLimit($currentUser['id'], 'wa_device', $db);
        handleCreate($currentUser);
        return;
    }
    if ($method === 'POST' && $action === 'disconnect') {
        handleDisconnect($currentUser);
        return;
    }
    if ($method === 'POST' && $action === 'update_settings') {
        handleUpdateSettings($currentUser);
        return;
    }
    if ($method === 'DELETE') {
        handleDelete($currentUser);
        return;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Unknown endpoint']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function requireAuthUser(): array
{
    if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $roles = $_SESSION['roles'] ?? [];

    return [
        'id' => (int) $_SESSION['user_id'],
        'email' => (string) ($_SESSION['email'] ?? ''),
        'full_name' => (string) ($_SESSION['full_name'] ?? 'User'),
        'roles' => $roles,
        'is_admin' => in_array('admin', $roles, true) || in_array('super_admin', $roles, true),
    ];
}

function assertDeviceAccessible(PDO $db, string $deviceId, array $currentUser): array
{
    $stmt = $db->prepare(
        'SELECT wa.*, u.full_name AS owner_name, u.email AS owner_email
         FROM wa_accounts wa
         LEFT JOIN users u ON u.id = wa.owner_user_id
         WHERE wa.device_id = :device_id
         LIMIT 1'
    );
    $stmt->execute(['device_id' => $deviceId]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Device not found']);
        exit;
    }

    if (!$currentUser['is_admin'] && (int) $row['owner_user_id'] !== (int) $currentUser['id']) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    return $row;
}

function assertSensitiveActionAllowed(array $deviceRow, array $currentUser): void
{
    if (
        $currentUser['is_admin']
        && (int) ($deviceRow['owner_user_id'] ?? 0) !== (int) $currentUser['id']
    ) {
        http_response_code(403);
        echo json_encode(['error' => 'Admin hanya diizinkan menghapus device milik user lain']);
        exit;
    }
}

function writeAuditLog(PDO $db, int $actorUserId, string $action, string $targetType, string $targetId, array $detail = []): void
{
    $stmt = $db->prepare(
        'INSERT INTO audit_logs (actor_user_id, action, target_type, target_id, detail, created_at)
         VALUES (:actor_user_id, :action, :target_type, :target_id, :detail, NOW())'
    );

    $stmt->execute([
        'actor_user_id' => $actorUserId,
        'action' => $action,
        'target_type' => $targetType,
        'target_id' => $targetId,
        'detail' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function handleList(array $currentUser): void
{
    $gowaRes = gowaRequest('GET', '/devices');
    $gowaDevices = [];
    if ($gowaRes['ok'] && is_array($gowaRes['data'])) {
        $raw = gowaUnwrap($gowaRes['data']);
        $gowaDevices = is_array($raw) ? $raw : [];
    }

    $gowaMap = [];
    foreach ($gowaDevices as $d) {
        $id = $d['id'] ?? $d['device_id'] ?? '';
        if ($id) {
            $gowaMap[$id] = $d;
        }
    }

    $db = getDB();
    if ($currentUser['is_admin']) {
        $stmt = $db->query(
            'SELECT wa.*, u.full_name AS owner_name, u.email AS owner_email
             FROM wa_accounts wa
             LEFT JOIN users u ON u.id = wa.owner_user_id
             ORDER BY wa.created_at DESC'
        );
    } else {
        $stmt = $db->prepare(
            'SELECT wa.*, u.full_name AS owner_name, u.email AS owner_email
             FROM wa_accounts wa
             LEFT JOIN users u ON u.id = wa.owner_user_id
             WHERE wa.owner_user_id = :owner_user_id
             ORDER BY wa.created_at DESC'
        );
        $stmt->execute(['owner_user_id' => $currentUser['id']]);
    }
    $dbDevices = $stmt->fetchAll();

    // Best-effort sync: refresh status/phone_jid from GoWA payload when list is loaded.
    try {
        foreach ($dbDevices as &$row) {
            $deviceId = (string) ($row['device_id'] ?? '');
            if ($deviceId === '' || !isset($gowaMap[$deviceId]) || !is_array($gowaMap[$deviceId])) {
                continue;
            }

            $g = $gowaMap[$deviceId];
            $currentStatus = strtolower(trim((string) ($row['status'] ?? '')));
            $nextStatus = normalizeGowaStatus($g, $currentStatus);

            $currentPhone = trim((string) ($row['phone_jid'] ?? ''));
            $nextPhone = trim((string) ($g['phone'] ?? $g['jid'] ?? ''));

            $fields = [];
            $params = ['device_id' => $deviceId];

            if ($nextStatus !== '' && $nextStatus !== $currentStatus) {
                $fields[] = 'status = :status';
                $params['status'] = $nextStatus;
                $row['status'] = $nextStatus;
            }

            if ($nextPhone !== '' && $nextPhone !== $currentPhone) {
                $fields[] = 'phone_jid = :phone_jid';
                $params['phone_jid'] = $nextPhone;
                $row['phone_jid'] = $nextPhone;
            }

            if ($nextStatus === 'connected' && empty($row['connected_at'])) {
                $fields[] = 'connected_at = NOW()';
                $row['connected_at'] = date('Y-m-d H:i:s');
            }

            if ($fields) {
                $fields[] = 'updated_at = NOW()';
                $sql = 'UPDATE wa_accounts SET ' . implode(', ', $fields) . ' WHERE device_id = :device_id';
                $db->prepare($sql)->execute($params);
            }
        }
        unset($row);
    } catch (Throwable $e) {
        // Keep list endpoint resilient; skip sync if any row update fails.
    }

    $result = array_map(static function (array $row) use ($gowaMap, $currentUser): array {
        $g = $gowaMap[$row['device_id']] ?? null;
        $status = $g ? normalizeGowaStatus($g, $row['status']) : $row['status'];
        $canUseSensitiveActions = !$currentUser['is_admin']
            || (int) ($row['owner_user_id'] ?? 0) === (int) $currentUser['id'];

        $item = [
            'id' => (int) $row['id'],
            'device_id' => $row['device_id'],
            'label' => $row['label'],
            'phone_jid' => $row['phone_jid'] ?? ($g['phone'] ?? null),
            'status' => $status,
            'db_name' => $row['db_name'] ?? '',
            'webhook_url' => $row['webhook_url'],
            'queue_enabled' => (bool) ($row['queue_enabled'] ?? true),
            'secret' => $canUseSensitiveActions ? $row['secret'] : null,
            'api_url' => $canUseSensitiveActions
                ? APP_URL . '/wa-send.php?phone=%5Bnumber%5D&message=%5Btext%5D&secret=' . $row['secret']
                : null,
            'connected_at' => $row['connected_at'],
            'created_at' => $row['created_at'],
            'can_sensitive_actions' => $canUseSensitiveActions,
        ];

        if ($currentUser['is_admin']) {
            $item['owner_user_id'] = (int) ($row['owner_user_id'] ?? 0);
            $item['owner_name'] = $row['owner_name'] ?? null;
            $item['owner_email'] = $row['owner_email'] ?? null;
        }

        return $item;
    }, $dbDevices);

    echo json_encode([
        'ok' => true,
        'is_admin' => $currentUser['is_admin'],
        'data' => $result,
    ]);
}

function handleCreate(array $currentUser): void
{
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $label = trim($input['label'] ?? '');
    $webhook = trim($input['webhook'] ?? '');

    if (!$label) {
        http_response_code(400);
        echo json_encode(['error' => 'label is required']);
        return;
    }

    // Ambil username (pakai email jika username tidak ada)
    $username = $currentUser['email'] ?? ($currentUser['full_name'] ?? 'user');
    // Normalisasi label dan username: lowercase, spasi/non-alfanumerik jadi _
    $normLabel = strtolower(preg_replace('/[^a-z0-9]+/', '_', $label));
    $normUser = strtolower(preg_replace('/[^a-z0-9]+/', '_', $username));
    // Tambahkan random string agar device_id selalu unik
    $rand = bin2hex(random_bytes(3)); // 6 hex char
    $customDeviceId = rtrim($normLabel . '_' . $normUser . '_' . $rand, '_');

    $body = [
        'label' => $label,
        'device_id' => $customDeviceId,
    ];
    if ($webhook) {
        $body['webhook'] = $webhook;
    }

    $gowaRes = gowaRequest('POST', '/devices', $body);

    if (!$gowaRes['ok']) {
        $errMsg = '';
        if (is_array($gowaRes['data'])) {
            $errMsg = $gowaRes['data']['message'] ?? $gowaRes['data']['error'] ?? '';
        }
        if (!$errMsg) {
            $errMsg = 'GoWA server error (HTTP ' . $gowaRes['status'] . ')';
        }
        http_response_code($gowaRes['status'] ?: 500);
        echo json_encode(['error' => $errMsg]);
        return;
    }

    $gowaData = gowaUnwrap($gowaRes['data']);
    $deviceId = $gowaData['id'] ?? $gowaData['device_id'] ?? null;

    if (!$deviceId) {
        http_response_code(500);
        echo json_encode(['error' => 'GoWA did not return a device ID']);
        return;
    }

    $dbName = $gowaData['db']
        ?? $gowaData['db_name']
        ?? $gowaData['database']
        ?? ('wa_device_' . preg_replace('/[^a-z0-9]/', '_', strtolower(substr($deviceId, 0, 20))));

    $secret = bin2hex(random_bytes(SECRET_BYTES));

    $db = getDB();
    $existingStmt = $db->prepare('SELECT owner_user_id FROM wa_accounts WHERE device_id = :device_id LIMIT 1');
    $existingStmt->execute(['device_id' => $deviceId]);
    $existingOwner = $existingStmt->fetchColumn();

    if ($existingOwner !== false && !$currentUser['is_admin'] && (int) $existingOwner !== (int) $currentUser['id']) {
        http_response_code(403);
        echo json_encode(['error' => 'Device already owned by another user']);
        return;
    }

    $stmt = $db->prepare(
        'INSERT INTO wa_accounts
            (owner_user_id, device_id, label, db_name, webhook_url, secret, status, created_at, updated_at)
         VALUES
            (:owner_user_id, :device_id, :label, :db_name, :webhook_url, :secret, "pending", NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            label = VALUES(label),
            webhook_url = VALUES(webhook_url),
            updated_at = NOW()'
    );

    $stmt->execute([
        'owner_user_id' => $currentUser['id'],
        'device_id' => $deviceId,
        'label' => $label,
        'db_name' => $dbName,
        'webhook_url' => $webhook ?: null,
        'secret' => $secret,
    ]);

    writeAuditLog(
        $db,
        $currentUser['id'],
        'wa_device_create',
        'wa_device',
        $deviceId,
        [
            'label' => $label,
            'owner_user_id' => $currentUser['id'],
            'webhook_url' => $webhook ?: null,
        ]
    );

    afterFeatureCreated($currentUser['id'], 'wa_device', $db);

    $apiUrl = APP_URL . '/wa-send.php?'
        . 'phone=[number]&message=[text]&secret=' . $secret;

    echo json_encode([
        'ok' => true,
        'device_id' => $deviceId,
        'label' => $label,
        'secret' => $secret,
        'api_url' => $apiUrl,
        'api_url_example' => str_replace(['[number]', '[text]'], ['628123456789', 'Hello!'], $apiUrl),
    ]);
}

function handleQR(array $currentUser): void
{
    $deviceId = trim($_GET['device_id'] ?? '');
    if (!$deviceId) {
        http_response_code(400);
        echo json_encode(['error' => 'device_id is required']);
        return;
    }

    $db = getDB();
    $deviceRow = assertDeviceAccessible($db, $deviceId, $currentUser);
    assertSensitiveActionAllowed($deviceRow, $currentUser);

    $res = gowaRequest('GET', '/devices/' . rawurlencode($deviceId) . '/login');

    // Backward compatibility for older GoWA builds.
    if (!$res['ok'] && shouldUseLegacyDeviceLogin($res)) {
        $res = gowaRequest('GET', '/app/login', null, ['X-Device-Id: ' . $deviceId]);
    }

    if (str_contains($res['content_type'] ?? '', 'image/')) {
        header('Content-Type: ' . $res['content_type']);
        header('Cache-Control: no-cache, no-store');
        echo $res['raw'];
        return;
    }

    if (is_array($res['data'])) {
        $payload = gowaUnwrap($res['data']);
        $qrLink = is_array($payload) ? (string) ($payload['qr_link'] ?? '') : '';
        if ($qrLink !== '') {
            $qrRes = gowaFetchQrResource($qrLink, $deviceId);
            if (str_contains($qrRes['content_type'] ?? '', 'image/')) {
                header('Content-Type: ' . $qrRes['content_type']);
                header('Cache-Control: no-cache, no-store');
                echo $qrRes['raw'];
                return;
            }
        }
    }

    if (str_contains($res['content_type'] ?? '', 'text/html')) {
        // Extract QR image URL from HTML and proxy the image through our backend.
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $res['raw'], $imgM)) {
            $qrRes = gowaFetchQrResource((string) $imgM[1], $deviceId);
            if (str_contains($qrRes['content_type'] ?? '', 'image/')) {
                header('Content-Type: ' . $qrRes['content_type']);
                header('Cache-Control: no-cache, no-store');
                echo $qrRes['raw'];
                return;
            }
        }
    }

    // Legacy JSON payload may return a qr_link, proxy it so browser never follows external redirects.
    if (is_array($res['data'])) {
        $payload = gowaUnwrap($res['data']);
        $qrLink = is_array($payload) ? (string) ($payload['qr_link'] ?? '') : '';
        if ($qrLink !== '') {
            $qrRes = gowaFetchQrResource($qrLink, $deviceId);

            if (str_contains($qrRes['content_type'] ?? '', 'image/')) {
                header('Content-Type: ' . $qrRes['content_type']);
                header('Cache-Control: no-cache, no-store');
                echo $qrRes['raw'];
                return;
            }
        }
    }

    header('Cache-Control: no-cache, no-store');
    echo json_encode([
        'ok' => $res['ok'],
        'status' => $res['status'],
        'data' => $res['data'],
    ]);
}

function gowaResolveUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $url)) {
        return $url;
    }

    return gowaBaseUrl() . '/' . ltrim($url, '/');
}

function gowaFetchQrResource(string $url, string $deviceId): array
{
    $resolved = gowaResolveUrl($url);
    if ($resolved === '') {
        return ['ok' => false, 'status' => 400, 'data' => ['error' => 'Invalid QR URL'], 'raw' => '', 'content_type' => 'application/json'];
    }

    return gowaRequest('GET', $resolved, null, ['X-Device-Id: ' . $deviceId]);
}

function handleTestSend(array $currentUser): void
{
    $deviceId = trim($_GET['device_id'] ?? '');
    $phone    = trim($_GET['phone']     ?? '');
    $message  = trim($_GET['message']  ?? '');

    if (!$deviceId || !$phone || !$message) {
        http_response_code(400);
        echo json_encode(['error' => 'device_id, phone, dan message wajib diisi', 'code' => 400]);
        return;
    }

    $db = getDB();
    $account = assertDeviceAccessible($db, $deviceId, $currentUser);
    assertSensitiveActionAllowed($account, $currentUser);

    if ($account['status'] !== 'connected') {
        http_response_code(403);
        echo json_encode(['error' => 'Device tidak terhubung. Status: ' . $account['status'], 'code' => 403]);
        return;
    }

    // Normalize phone
    $phone = preg_replace('/\D+/', '', $phone);
    if (str_starts_with($phone, '0')) {
        $phone = '62' . substr($phone, 1);
    }
    if (strlen($phone) < 7 || strlen($phone) > 15) {
        http_response_code(400);
        echo json_encode(['error' => 'Format nomor tidak valid', 'code' => 400]);
        return;
    }

    $resolvedDeviceId = (string) ($account['device_id'] ?? $deviceId);

    try {
        $sendResult = waSendNow($db, $resolvedDeviceId, $phone, $message, 'wa_account_test_send');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'success',
            'message' => 'Pesan berhasil dikirim',
            'code' => 200,
            'delay_ms' => $sendResult['delay_ms'] ?? 0,
            'base_delay_ms' => $sendResult['base_delay_ms'] ?? 0,
            'rate_limit_pause_ms' => $sendResult['rate_limit_pause_ms'] ?? 0,
            'data' => $sendResult['response'] ?? null,
        ]);
    } catch (Throwable $e) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage(), 'code' => 500]);
    }
}

function shouldUseLegacyDeviceLogin(array $res): bool
{
    if (($res['status'] ?? 0) < 500 || !is_array($res['data'])) {
        return false;
    }

    $message = strtolower((string) ($res['data']['message'] ?? ''));
    return $message !== '' && str_contains($message, 'not implemented');
}

function handleStatus(array $currentUser): void
{
    $deviceId = trim($_GET['device_id'] ?? '');
    if (!$deviceId) {
        http_response_code(400);
        echo json_encode(['error' => 'device_id is required']);
        return;
    }

    $db = getDB();
    assertDeviceAccessible($db, $deviceId, $currentUser);

    $res = gowaRequest('GET', '/devices/' . rawurlencode($deviceId) . '/status');
    $data = gowaUnwrap($res['data']);

    $status = is_array($data) ? normalizeGowaStatus($data, '') : '';
    if ($status && in_array($status, ['connected', 'disconnected', 'error', 'pending'], true)) {
        $fields = ['status = :status', 'updated_at = NOW()'];
        $params = ['status' => $status, 'device_id' => $deviceId];

        if ($status === 'connected') {
            $fields[] = 'connected_at = NOW()';
            $phone = $data['phone'] ?? $data['jid'] ?? '';
            if ($phone) {
                $fields[] = 'phone_jid = :phone_jid';
                $params['phone_jid'] = $phone;
            }
        }

        $db->prepare('UPDATE wa_accounts SET ' . implode(', ', $fields) . ' WHERE device_id = :device_id')
            ->execute($params);
    }

    echo json_encode([
        'ok' => $res['ok'],
        'status' => $status,
        'data' => $data,
    ]);
}

function handleDelete(array $currentUser): void
{
    $id = (int) ($_GET['id'] ?? 0);
    $deviceId = trim($_GET['device_id'] ?? '');

    $db = getDB();

    if (!$deviceId && $id) {
        $stmt = $db->prepare('SELECT device_id FROM wa_accounts WHERE id = ?');
        $stmt->execute([$id]);
        $deviceId = (string) ($stmt->fetchColumn() ?: '');
    }

    if (!$deviceId) {
        http_response_code(400);
        echo json_encode(['error' => 'device_id or id is required']);
        return;
    }

    $deviceRow = assertDeviceAccessible($db, $deviceId, $currentUser);

    $gowaRes = gowaRequest('DELETE', '/devices/' . rawurlencode($deviceId));
    $db->prepare('DELETE FROM wa_accounts WHERE device_id = ?')->execute([$deviceId]);

    writeAuditLog(
        $db,
        $currentUser['id'],
        'wa_device_delete',
        'wa_device',
        $deviceId,
        [
            'label' => $deviceRow['label'] ?? null,
            'owner_user_id' => isset($deviceRow['owner_user_id']) ? (int) $deviceRow['owner_user_id'] : null,
            'gowa_status' => $gowaRes['status'] ?? null,
        ]
    );

    afterFeatureDeleted($currentUser['id'], 'wa_device', $db);

    echo json_encode(['ok' => true, 'gowa_status' => $gowaRes['status']]);
}

function normalizeGowaStatus(array $payload, string $fallback = ''): string
{
    $status = strtolower((string) ($payload['status'] ?? ''));
    if ($status !== '') {
        return $status;
    }

    $isConnected = $payload['is_connected'] ?? null;
    $isLoggedIn = $payload['is_logged_in'] ?? null;

    if ($isConnected === true || $isLoggedIn === true) {
        return 'connected';
    }
    if ($isConnected === false || $isLoggedIn === false) {
        return 'pending';
    }

    return strtolower(trim($fallback));
}

function handleDisconnect(array $currentUser): void
{
    $deviceId = trim($_GET['device_id'] ?? '');
    if (!$deviceId) {
        http_response_code(400);
        echo json_encode(['error' => 'device_id is required']);
        return;
    }

    $db = getDB();
    $deviceRow = assertDeviceAccessible($db, $deviceId, $currentUser);
    assertSensitiveActionAllowed($deviceRow, $currentUser);

    $res = gowaRequest('POST', '/devices/' . rawurlencode($deviceId) . '/logout');

    $db->prepare(
        'UPDATE wa_accounts SET status = "disconnected", disconnected_at = NOW(), updated_at = NOW()
         WHERE device_id = ?'
    )->execute([$deviceId]);

    writeAuditLog(
        $db,
        $currentUser['id'],
        'wa_device_disconnect',
        'wa_device',
        $deviceId,
        [
            'gowa_ok' => (bool) ($res['ok'] ?? false),
            'gowa_status' => $res['status'] ?? null,
        ]
    );

    echo json_encode(['ok' => $res['ok'], 'data' => gowaUnwrap($res['data'])]);
}

function handleUpdateSettings(array $currentUser): void
{
    $deviceId = trim($_GET['device_id'] ?? '');
    if (!$deviceId) {
        http_response_code(400);
        echo json_encode(['error' => 'device_id is required']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $db = getDB();
    $deviceRow = assertDeviceAccessible($db, $deviceId, $currentUser);

    $queueEnabled = isset($input['queue_enabled']) ? (bool) $input['queue_enabled'] : null;

    $updateFields = [];
    $params = ['device_id' => $deviceId];

    if ($queueEnabled !== null) {
        $updateFields[] = 'queue_enabled = :queue_enabled';
        $params['queue_enabled'] = $queueEnabled ? 1 : 0;
    }

    if (!$updateFields) {
        http_response_code(400);
        echo json_encode(['error' => 'No settings provided']);
        return;
    }

    $updateFields[] = 'updated_at = NOW()';
    $sql = 'UPDATE wa_accounts SET ' . implode(', ', $updateFields) . ' WHERE device_id = :device_id';
    $db->prepare($sql)->execute($params);

    writeAuditLog(
        $db,
        $currentUser['id'],
        'wa_device_settings_update',
        'wa_device',
        $deviceId,
        [
            'queue_enabled' => $queueEnabled,
        ]
    );

    echo json_encode([
        'ok' => true,
        'device_id' => $deviceId,
        'queue_enabled' => $queueEnabled,
    ]);
}
