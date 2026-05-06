<?php
/**
 * Message Controller API
 *
 * Actions:
 * - POST ?action=send_register_otp  : kirim OTP registrasi ke user
 * - POST ?action=send_notification  : kirim notifikasi custom ke user
 * - POST ?action=verify_register_otp: verifikasi OTP registrasi (expired 5 menit, sekali pakai)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/gowa.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/wa-send-runtime.php';

session_start();

header('Content-Type: application/json; charset=utf-8');
setCorsHeaders(false);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed', 'code' => 405]);
    exit;
}

$action = trim((string) ($_GET['action'] ?? ''));

if ($action !== 'verify_register_otp') {
    if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized', 'code' => 401]);
        exit;
    }

    $roles = $_SESSION['roles'] ?? [];
    $isAdmin = in_array('admin', $roles, true) || in_array('super_admin', $roles, true);
    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden', 'code' => 403]);
        exit;
    }
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = [];
}

try {
    $db = getDB();
    ensureOtpTable($db);
    $actorUserId = (int) ($_SESSION['user_id'] ?? 0);

    switch ($action) {
        case 'send_register_otp':
            handleSendRegisterOtp($db, $payload, $actorUserId);
            break;
        case 'send_notification':
            handleSendNotification($db, $payload);
            break;
        case 'verify_register_otp':
            handleVerifyRegisterOtp($db, $payload);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action', 'code' => 400]);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'code' => 500]);
}

function handleSendRegisterOtp(PDO $db, array $payload, int $actorUserId): void
{
    $userId = (int) ($payload['user_id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'user_id wajib diisi', 'code' => 400]);
        return;
    }

    $user = findUserById($db, $userId);
    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'User tidak ditemukan', 'code' => 404]);
        return;
    }

    $phone = normalizePhone((string) ($user['phone_number'] ?? ''));
    if ($phone === '') {
        http_response_code(400);
        echo json_encode(['error' => 'User belum memiliki No. HP yang valid', 'code' => 400]);
        return;
    }

    $otpCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otpHash = password_hash($otpCode, PASSWORD_BCRYPT);
    $message = "Kode OTP registrasi Anda: {$otpCode}. Berlaku 5 menit. Jangan berikan kode ini ke siapa pun.";

    $db->beginTransaction();
    try {
        $db->prepare(
            "UPDATE user_otp_codes
             SET used_at = NOW(), used_reason = 'replaced'
             WHERE user_id = :user_id
               AND purpose = 'register'
               AND used_at IS NULL"
        )->execute(['user_id' => $userId]);

        $insertOtp = $db->prepare(
            "INSERT INTO user_otp_codes
                (user_id, purpose, otp_hash, phone_number, expires_at, max_attempts, created_by, created_at)
             VALUES
                (:user_id, 'register', :otp_hash, :phone_number, DATE_ADD(NOW(), INTERVAL 5 MINUTE), 5, :created_by, NOW())"
        );
        $insertOtp->execute([
            'user_id' => $userId,
            'otp_hash' => $otpHash,
            'phone_number' => $phone,
            'created_by' => $actorUserId > 0 ? $actorUserId : null,
        ]);

        $sendResult = sendAdminMessage($db, $phone, $message, (string) ($payload['device_id'] ?? ''), [
            'source' => 'register_otp',
            'use_queue' => false,
        ]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'OTP registrasi berhasil dikirim',
        'code' => 200,
        'data' => [
            'user_id' => $userId,
            'phone' => $phone,
            'delivery' => $sendResult,
        ],
    ]);
}

function handleVerifyRegisterOtp(PDO $db, array $payload): void
{
    $userId = (int) ($payload['user_id'] ?? 0);
    $otpCode = trim((string) ($payload['otp_code'] ?? ''));

    if ($userId <= 0 || $otpCode === '') {
        http_response_code(400);
        echo json_encode(['error' => 'user_id dan otp_code wajib diisi', 'code' => 400]);
        return;
    }

    $stmt = $db->prepare(
        "SELECT id, otp_hash, expires_at, attempts, max_attempts
         FROM user_otp_codes
         WHERE user_id = :user_id
           AND purpose = 'register'
           AND used_at IS NULL
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute(['user_id' => $userId]);
    $otpRow = $stmt->fetch();

    if (!is_array($otpRow)) {
        http_response_code(400);
        echo json_encode(['error' => 'OTP tidak ditemukan atau sudah digunakan', 'code' => 400]);
        return;
    }

    $otpId = (int) $otpRow['id'];
    $attempts = (int) ($otpRow['attempts'] ?? 0);
    $maxAttempts = max(1, (int) ($otpRow['max_attempts'] ?? 5));
    $expiresAt = strtotime((string) ($otpRow['expires_at'] ?? ''));

    if ($expiresAt !== false && $expiresAt < time()) {
        $db->prepare("UPDATE user_otp_codes SET used_at = NOW(), used_reason = 'expired' WHERE id = :id")
            ->execute(['id' => $otpId]);
        http_response_code(400);
        echo json_encode(['error' => 'OTP sudah kedaluwarsa', 'code' => 400]);
        return;
    }

    if (!password_verify($otpCode, (string) ($otpRow['otp_hash'] ?? ''))) {
        $attempts++;
        if ($attempts >= $maxAttempts) {
            $db->prepare(
                "UPDATE user_otp_codes
                 SET attempts = :attempts, used_at = NOW(), used_reason = 'max_attempts'
                 WHERE id = :id"
            )->execute([
                'attempts' => $attempts,
                'id' => $otpId,
            ]);
            http_response_code(400);
            echo json_encode(['error' => 'OTP salah. Batas percobaan habis, silakan minta OTP baru.', 'code' => 400]);
            return;
        }

        $db->prepare('UPDATE user_otp_codes SET attempts = :attempts WHERE id = :id')
            ->execute([
                'attempts' => $attempts,
                'id' => $otpId,
            ]);
        http_response_code(400);
        echo json_encode(['error' => 'OTP tidak valid', 'code' => 400, 'attempts_left' => max(0, $maxAttempts - $attempts)]);
        return;
    }

    $db->prepare("UPDATE user_otp_codes SET used_at = NOW(), used_reason = 'verified' WHERE id = :id")
        ->execute(['id' => $otpId]);

    echo json_encode([
        'status' => 'success',
        'message' => 'OTP valid',
        'code' => 200,
        'data' => [
            'user_id' => $userId,
            'verified' => true,
        ],
    ]);
}

function ensureOtpTable(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS user_otp_codes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            purpose VARCHAR(40) NOT NULL,
            otp_hash VARCHAR(255) NOT NULL,
            phone_number VARCHAR(30) NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            used_reason VARCHAR(40) NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_user_otp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_user_otp_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_user_otp_lookup (user_id, purpose, used_at, expires_at),
            INDEX idx_user_otp_created_at (created_at)
        ) ENGINE=InnoDB"
    );
}

function handleSendNotification(PDO $db, array $payload): void
{
    $userId = (int) ($payload['user_id'] ?? 0);
    $customMessage = trim((string) ($payload['message'] ?? ''));

    if ($userId <= 0 || $customMessage === '') {
        http_response_code(400);
        echo json_encode(['error' => 'user_id dan message wajib diisi', 'code' => 400]);
        return;
    }

    $user = findUserById($db, $userId);
    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'User tidak ditemukan', 'code' => 404]);
        return;
    }

    $phone = normalizePhone((string) ($user['phone_number'] ?? ''));
    if ($phone === '') {
        http_response_code(400);
        echo json_encode(['error' => 'User belum memiliki No. HP yang valid', 'code' => 400]);
        return;
    }

    $sendResult = sendAdminMessage($db, $phone, $customMessage, (string) ($payload['device_id'] ?? ''), [
        'source' => 'send_notification',
        'use_queue' => waRequestedQueueValue($payload['use_queue'] ?? null),
    ]);

    $queued = (bool) ($sendResult['queued'] ?? false);

    if ($queued) {
        http_response_code(202);
    }

    echo json_encode([
        'status' => $queued ? 'queued' : 'success',
        'message' => $queued ? 'Notifikasi masuk queue pengiriman' : 'Notifikasi berhasil dikirim',
        'code' => $queued ? 202 : 200,
        'data' => [
            'user_id' => $userId,
            'phone' => $phone,
            'delivery' => $sendResult,
        ],
    ]);
}

function findUserById(PDO $db, int $userId): ?array
{
    $stmt = $db->prepare('SELECT id, full_name, email, phone_number FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function normalizePhone(string $phone): string
{
    $phone = preg_replace('/\D+/', '', $phone);
    if ($phone === '') {
        return '';
    }
    if (str_starts_with($phone, '0')) {
        $phone = '62' . substr($phone, 1);
    }
    if (strlen($phone) < 7 || strlen($phone) > 15) {
        return '';
    }
    return $phone;
}

function getAdminDefaultDeviceId(PDO $db): string
{
    try {
        $stmt = $db->query(
            "SELECT admin_send_device_id
             FROM api_configurations
             WHERE service_type = 'whatsapp' AND is_enabled = 1
             ORDER BY updated_at DESC, id DESC
             LIMIT 1"
        );
        $row = $stmt->fetch();
        $id = trim((string) ($row['admin_send_device_id'] ?? ''));
        if ($id !== '') {
            return $id;
        }
    } catch (Throwable $e) {
        // Fallback to constant.
    }

    return trim((string) GOWA_ADMIN_SEND_DEVICE_ID);
}

function sendAdminMessage(PDO $db, string $phone, string $message, string $explicitDeviceId = '', array $options = []): array
{
    $deviceId = trim($explicitDeviceId);
    if ($deviceId === '') {
        $deviceId = getAdminDefaultDeviceId($db);
    }

    if ($deviceId === '') {
        throw new RuntimeException('Admin default device belum diatur. Silakan set di dashboard admin.');
    }

    $source = trim((string) ($options['source'] ?? 'message_controller'));
    $shouldUseQueue = waShouldUseQueue('admin', $options['use_queue'] ?? null, $db);
    
    // Check device-specific queue setting
    if ($shouldUseQueue && !waDeviceQueueEnabled($db, $deviceId)) {
        $shouldUseQueue = false;
    }

    if ($shouldUseQueue) {
        $queueId = enqueueWaMessage($db, $deviceId, $phone, $message, $source, 'admin', [
            'explicit_device' => $explicitDeviceId !== '',
        ]);

        return [
            'device_id' => $deviceId,
            'queue_id' => $queueId,
            'queued' => true,
        ];
    }

    return waSendNow($db, $deviceId, $phone, $message, $source);
}
