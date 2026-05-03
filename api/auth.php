<?php
/**
 * Authentication Endpoint
 * POST /api/auth.php
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

header('Content-Type: application/json');
setCorsHeaders(false);

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit();
}

// Get JSON input
$input    = json_decode(file_get_contents('php://input'), true) ?? [];
$email    = trim($input['email'] ?? '');
$password = (string) ($input['password'] ?? '');

if ($email === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Email dan password harus diisi']);
    exit();
}

try {
    $db = getDB();

    // ── Rate limiting — keyed by IP + email ──────────────────────────────────
    $rlKey = clientIp() . '|' . strtolower($email);
    if (isRateLimited($db, $rlKey)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Terlalu banyak percobaan login. Coba lagi dalam 15 menit.']);
        exit();
    }

    // ── Fetch user ────────────────────────────────────────────────────────────
    $stmt = $db->prepare('SELECT id, full_name, email, phone_number, password_hash, is_active FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        recordFailedAttempt($db, $rlKey);
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Email atau password salah']);
        exit();
    }

    if (!(int) $user['is_active']) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Akun tidak aktif']);
        exit();
    }

    // ── Build role list ───────────────────────────────────────────────────────
    $rolesStmt = $db->prepare(
        'SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?'
    );
    $rolesStmt->execute([$user['id']]);
    $roleList = [];
    foreach ($rolesStmt->fetchAll() as $row) {
        $roleList[] = in_array($row['code'], ['admin', 'super_admin'], true) ? 'admin' : 'user';
    }
    $roleList = array_values(array_unique($roleList));

    // ── Clear rate limit + update last login ──────────────────────────────────
    clearLoginAttempts($db, $rlKey);
    $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);

    // ── Create hardened session ───────────────────────────────────────────────
    secureSessionStart();
    session_regenerate_id(true);
    $_SESSION['user_id']      = $user['id'];
    $_SESSION['email']        = $user['email'];
    $_SESSION['full_name']    = $user['full_name'];
    $_SESSION['phone_number'] = $user['phone_number'] ?? '';
    $_SESSION['roles']        = $roleList;
    $_SESSION['logged_in']    = true;

    http_response_code(200);
    echo json_encode([
        'ok'      => true,
        'message' => 'Login berhasil',
        'user'    => [
            'id'       => $user['id'],
            'email'    => $user['email'],
            'fullName' => $user['full_name'],
            'roles'    => $roleList,
        ],
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Terjadi kesalahan pada server']);
}
