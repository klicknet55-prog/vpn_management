<?php
/**
 * Public Register API
 *
 * Actions (POST):
 * - ?action=request_otp : create/update pending user + send OTP register
 * - ?action=verify_otp  : verify OTP and activate account
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/gowa.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/../system/services/SubscriptionService.php';

setCorsHeaders(false);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$action = trim((string) ($_GET['action'] ?? ''));
$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = [];
}

try {
    $db = getDB();
    ensureUserPhoneColumn($db);
    ensureOtpTable($db);

    if ($action === 'request_otp') {
        handleRequestOtp($db, $payload);
        exit;
    }

    if ($action === 'verify_otp') {
        handleVerifyOtp($db, $payload);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

function handleRequestOtp(PDO $db, array $payload): void
{
    $fullName = trim((string) ($payload['full_name'] ?? ''));
    $email = trim((string) ($payload['email'] ?? ''));
    $password = (string) ($payload['password'] ?? '');
    $phoneRaw = trim((string) ($payload['phone_number'] ?? ''));

    if ($fullName === '' || $email === '' || $password === '' || $phoneRaw === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'full_name, email, phone_number, dan password wajib diisi']);
        return;
    }

    if (strlen($password) < 6) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Password minimal 6 karakter']);
        return;
    }

    $phone = normalizePhone($phoneRaw);
    if ($phone === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No. HP tidak valid']);
        return;
    }

    $db->beginTransaction();
    try {
        $userStmt = $db->prepare('SELECT id, is_active FROM users WHERE email = :email LIMIT 1');
        $userStmt->execute(['email' => $email]);
        $existingUser = $userStmt->fetch();

        if (is_array($existingUser)) {
            $userId = (int) $existingUser['id'];

            if ((int) ($existingUser['is_active'] ?? 0) === 1) {
                http_response_code(409);
                echo json_encode(['ok' => false, 'error' => 'Email/username sudah terdaftar dan aktif. Silakan login.']);
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                return;
            }

            $updateUser = $db->prepare(
                'UPDATE users
                 SET full_name = :full_name,
                     phone_number = :phone_number,
                     password_hash = :password_hash,
                     is_active = 0,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $updateUser->execute([
                'full_name' => $fullName,
                'phone_number' => $phone,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'id' => $userId,
            ]);
        } else {
            $insertUser = $db->prepare(
                'INSERT INTO users (full_name, email, phone_number, password_hash, is_active, created_at, updated_at)
                 VALUES (:full_name, :email, :phone_number, :password_hash, 0, NOW(), NOW())'
            );
            $insertUser->execute([
                'full_name' => $fullName,
                'email' => $email,
                'phone_number' => $phone,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            ]);
            $userId = (int) $db->lastInsertId();
        }

        $roleId = ensureUserRole($db);
        $assignRole = $db->prepare(
            'INSERT INTO user_roles (user_id, role_id)
             VALUES (:user_id, :role_id)
             ON DUPLICATE KEY UPDATE role_id = VALUES(role_id)'
        );
        $assignRole->execute([
            'user_id' => $userId,
            'role_id' => $roleId,
        ]);

        $db->prepare(
            "UPDATE user_otp_codes
             SET used_at = NOW(), used_reason = 'replaced'
             WHERE user_id = :user_id
               AND purpose = 'register'
               AND used_at IS NULL"
        )->execute(['user_id' => $userId]);

        $otpCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otpHash = password_hash($otpCode, PASSWORD_BCRYPT);

        $insertOtp = $db->prepare(
            "INSERT INTO user_otp_codes
                (user_id, purpose, otp_hash, phone_number, expires_at, max_attempts, created_by, created_at)
             VALUES
                (:user_id, 'register', :otp_hash, :phone_number, DATE_ADD(NOW(), INTERVAL 5 MINUTE), 5, NULL, NOW())"
        );
        $insertOtp->execute([
            'user_id' => $userId,
            'otp_hash' => $otpHash,
            'phone_number' => $phone,
        ]);

        $message = "Kode OTP registrasi Anda: {$otpCode}. Berlaku 5 menit. Jangan berikan kode ini ke siapa pun.";
        $sendResult = sendUsingAdminDefaultDevice($db, $phone, $message);

        $db->commit();

        echo json_encode([
            'ok' => true,
            'message' => 'OTP registrasi berhasil dikirim',
            'data' => [
                'user_id' => $userId,
                'email' => $email,
                'phone_number' => $phone,
                'delivery' => $sendResult,
            ],
        ]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function handleVerifyOtp(PDO $db, array $payload): void
{
    $userId = (int) ($payload['user_id'] ?? 0);
    $email = trim((string) ($payload['email'] ?? ''));
    $otpCode = trim((string) ($payload['otp_code'] ?? ''));

    if ($userId <= 0 && $email !== '') {
        $stmt = $db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $userId = (int) ($stmt->fetchColumn() ?: 0);
    }

    if ($userId <= 0 || $otpCode === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'user_id/email dan otp_code wajib diisi']);
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
        echo json_encode(['ok' => false, 'error' => 'OTP tidak ditemukan atau sudah digunakan']);
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
        echo json_encode(['ok' => false, 'error' => 'OTP sudah kedaluwarsa']);
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
            echo json_encode(['ok' => false, 'error' => 'OTP salah. Batas percobaan habis, silakan minta OTP baru.']);
            return;
        }

        $db->prepare('UPDATE user_otp_codes SET attempts = :attempts WHERE id = :id')
            ->execute([
                'attempts' => $attempts,
                'id' => $otpId,
            ]);
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => 'OTP tidak valid',
            'attempts_left' => max(0, $maxAttempts - $attempts),
        ]);
        return;
    }

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE user_otp_codes SET used_at = NOW(), used_reason = 'verified' WHERE id = :id")
            ->execute(['id' => $otpId]);

        $db->prepare('UPDATE users SET is_active = 1, updated_at = NOW() WHERE id = :id')
            ->execute(['id' => $userId]);

        // Assign free plan ke user yang baru aktif
        $subService = new SubscriptionService($db);
        $subService->assignFreePlan($userId);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Registrasi berhasil diverifikasi. Akun sudah aktif.',
        'data' => [
            'user_id' => $userId,
            'verified' => true,
        ],
    ]);
}

function ensureUserRole(PDO $db): int
{
    $db->exec(
        "INSERT INTO roles (code, name, description)
         VALUES ('user', 'User', 'Kelola data VPN dan WA milik sendiri')
         ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)"
    );

    $stmt = $db->prepare('SELECT id FROM roles WHERE code = :code LIMIT 1');
    $stmt->execute(['code' => 'user']);
    $roleId = (int) ($stmt->fetchColumn() ?: 0);
    if ($roleId <= 0) {
        throw new RuntimeException('Role user tidak ditemukan');
    }
    return $roleId;
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

    $fallback = trim((string) GOWA_ADMIN_SEND_DEVICE_ID);
    if ($fallback !== '') {
        return $fallback;
    }

    throw new RuntimeException('Admin default device belum diatur. Silakan set di dashboard admin.');
}

function sendUsingAdminDefaultDevice(PDO $db, string $phone, string $message): array
{
    $deviceId = getAdminDefaultDeviceId($db);
    $res = gowaRequest(
        'POST',
        '/send/message',
        [
            'phone' => $phone,
            'message' => $message,
        ],
        ['X-Device-Id: ' . $deviceId]
    );

    if (!$res['ok']) {
        $err = '';
        if (is_array($res['data'])) {
            $err = (string) ($res['data']['message'] ?? $res['data']['error'] ?? '');
        }
        if ($err === '') {
            $err = 'Gagal kirim OTP (GoWA HTTP ' . (int) $res['status'] . ')';
        }
        throw new RuntimeException($err);
    }

    return [
        'device_id' => $deviceId,
        'response' => gowaUnwrap($res['data']),
    ];
}

function ensureUserPhoneColumn(PDO $db): void
{
    try {
        $db->exec('ALTER TABLE users ADD COLUMN phone_number VARCHAR(30) NULL AFTER email');
    } catch (Throwable $e) {
        // Column may already exist.
    }
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
            CONSTRAINT fk_user_otp_user_reg FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_user_otp_created_by_reg FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_user_otp_lookup_reg (user_id, purpose, used_at, expires_at),
            INDEX idx_user_otp_created_at_reg (created_at)
        ) ENGINE=InnoDB"
    );
}
