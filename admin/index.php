<?php
session_start();
if (empty($_SESSION['logged_in'])) {
    header('Location: ../login.php');
    exit();
}

$sessionRoles = $_SESSION['roles'] ?? [];
$isAdmin = in_array('admin', $sessionRoles, true) || in_array('super_admin', $sessionRoles, true);
if (!$isAdmin) {
    header('Location: ../index.php');
    exit();
}

require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../system/seo.php';

$actionError = '';
$actionSuccess = '';
$postAction = (string) ($_POST['action'] ?? '');
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$db = null;

try {
    $db = getDB();

    try {
        $db->exec('ALTER TABLE users ADD COLUMN phone_number VARCHAR(30) NULL AFTER email');
    } catch (Throwable $e) {
        // Column may already exist.
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($postAction === 'create_user') {
            $fullName = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phoneNumber = trim((string) ($_POST['phone_number'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $roleCode = trim($_POST['role_code'] ?? '');
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($fullName === '' || $email === '' || $password === '' || $roleCode === '' || $phoneNumber === '') {
                throw new RuntimeException('Semua field wajib diisi.');
            }
            $phoneDigits = preg_replace('/\D+/', '', $phoneNumber);
            if (str_starts_with($phoneDigits, '0')) {
                $phoneDigits = '62' . substr($phoneDigits, 1);
            }
            if (strlen($phoneDigits) < 7 || strlen($phoneDigits) > 15) {
                throw new RuntimeException('No. HP tidak valid. Gunakan format 62xxxxxxxxxxx atau 08xxxxxxxxxx.');
            }
            if (strlen($password) < 6) {
                throw new RuntimeException('Password minimal 6 karakter.');
            }

            $roleStmt = $db->prepare('SELECT id FROM roles WHERE code = :code LIMIT 1');
            $roleStmt->execute(['code' => $roleCode]);
            $roleId = (int) ($roleStmt->fetchColumn() ?: 0);
            if ($roleId <= 0) {
                throw new RuntimeException('Role tidak valid.');
            }

            $insertUser = $db->prepare(
                'INSERT INTO users (full_name, email, phone_number, password_hash, is_active, created_at, updated_at)
                 VALUES (:full_name, :email, :phone_number, :password_hash, :is_active, NOW(), NOW())'
            );
            $insertUser->execute([
                'full_name' => $fullName,
                'email' => $email,
                'phone_number' => $phoneDigits,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'is_active' => $isActive,
            ]);

            $userId = (int) $db->lastInsertId();
            $insertRole = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)');
            $insertRole->execute([
                'user_id' => $userId,
                'role_id' => $roleId,
            ]);

            $actionSuccess = 'User baru berhasil dibuat.';
        } elseif ($postAction === 'update_user') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $phoneNumber = trim((string) ($_POST['phone_number'] ?? ''));
            $roleCode = trim((string) ($_POST['role_code'] ?? ''));
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($userId <= 0) {
                throw new RuntimeException('User tidak valid.');
            }
            if ($fullName === '' || $roleCode === '' || $phoneNumber === '') {
                throw new RuntimeException('Nama, no. HP, dan role wajib diisi.');
            }

            $phoneDigits = preg_replace('/\D+/', '', $phoneNumber);
            if (str_starts_with($phoneDigits, '0')) {
                $phoneDigits = '62' . substr($phoneDigits, 1);
            }
            if (strlen($phoneDigits) < 7 || strlen($phoneDigits) > 15) {
                throw new RuntimeException('No. HP tidak valid. Gunakan format 62xxxxxxxxxxx atau 08xxxxxxxxxx.');
            }
            if ($currentUserId > 0 && $userId === $currentUserId && $isActive !== 1) {
                throw new RuntimeException('Akun Anda sendiri tidak boleh dinonaktifkan.');
            }

            $roleStmt = $db->prepare('SELECT id FROM roles WHERE code = :code LIMIT 1');
            $roleStmt->execute(['code' => $roleCode]);
            $roleId = (int) ($roleStmt->fetchColumn() ?: 0);
            if ($roleId <= 0) {
                throw new RuntimeException('Role tidak valid.');
            }

            $updateUser = $db->prepare(
                'UPDATE users
                 SET full_name = :full_name, phone_number = :phone_number, is_active = :is_active, updated_at = NOW()
                 WHERE id = :id'
            );
            $updateUser->execute([
                'full_name' => $fullName,
                'phone_number' => $phoneDigits,
                'is_active' => $isActive,
                'id' => $userId,
            ]);

            $deleteRoles = $db->prepare('DELETE FROM user_roles WHERE user_id = :user_id');
            $deleteRoles->execute(['user_id' => $userId]);

            $insertRole = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)');
            $insertRole->execute([
                'user_id' => $userId,
                'role_id' => $roleId,
            ]);

            if ($currentUserId > 0 && $userId === $currentUserId) {
                $_SESSION['full_name'] = $fullName;
            }

            $actionSuccess = 'Profil user berhasil diperbarui.';
        } elseif ($postAction === 'delete_user') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            if ($userId <= 0) {
                throw new RuntimeException('User tidak valid.');
            }
            if ($currentUserId > 0 && $userId === $currentUserId) {
                throw new RuntimeException('Akun Anda sendiri tidak dapat dihapus.');
            }

            $db->beginTransaction();
            $deleteRoles = $db->prepare('DELETE FROM user_roles WHERE user_id = :user_id');
            $deleteRoles->execute(['user_id' => $userId]);

            $deleteUser = $db->prepare('DELETE FROM users WHERE id = :id');
            $deleteUser->execute(['id' => $userId]);

            if ($deleteUser->rowCount() < 1) {
                $db->rollBack();
                throw new RuntimeException('User tidak ditemukan atau sudah dihapus.');
            }

            $db->commit();
            $actionSuccess = 'User berhasil dihapus.';
        }
    }
} catch (RuntimeException $e) {
    $actionError = $e->getMessage();
} catch (PDOException $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $code = (string) ($e->errorInfo[1] ?? '');
    if ($code === '1062') {
        $actionError = 'Email sudah terdaftar.';
    } elseif ($code === '1451') {
        $actionError = 'User tidak bisa dihapus karena masih dipakai data lain.';
    } else {
        $actionError = 'Gagal menyimpan user.';
    }
}

$roles = [];
$users = [];

try {
    $db->exec(
        "INSERT INTO roles (code, name, description) VALUES
            ('admin', 'Admin', 'Akses penuh semua modul'),
            ('user', 'User', 'Kelola data VPN dan WA milik sendiri')
         ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)"
    );

    $roleListStmt = $db->query("SELECT id, code, name FROM roles WHERE code IN ('admin','user') ORDER BY FIELD(code, 'admin', 'user')");
    $roles = $roleListStmt->fetchAll();

    $usersStmt = $db->query(
        'SELECT u.id, u.full_name, u.email, u.phone_number, u.is_active, u.last_login_at, u.created_at,
                GROUP_CONCAT(r.code ORDER BY r.code SEPARATOR ",") AS role_codes
         FROM users u
         LEFT JOIN user_roles ur ON ur.user_id = u.id
         LEFT JOIN roles r ON r.id = ur.role_id
         GROUP BY u.id, u.full_name, u.email, u.phone_number, u.is_active, u.last_login_at, u.created_at
         ORDER BY u.created_at DESC'
    );
    $users = $usersStmt->fetchAll();
} catch (Throwable $e) {
    if ($actionError === '') {
        $actionError = 'Gagal memuat data user dari database.';
    }
}

$totalUsers = count($users);

// ── System Health: real-time indicators ──────────────────────────────────────

// Helper: latency → health percent + CSS class
function healthPctFromMs(int $ms, bool $ok, array $thresholds): int {
    if (!$ok) return 0;
    arsort($thresholds); // highest ms threshold first
    foreach ($thresholds as $limit => $pct) {
        if ($ms >= $limit) return $pct;
    }
    return 100;
}
function healthClass(int $pct): string {
    if ($pct >= 80) return 'success';
    if ($pct >= 55) return 'warning';
    return 'danger';
}

function formatJakartaDateTime(string $datetime): string {
    $timestamp = strtotime($datetime);
    if ($timestamp === false) {
        return '-';
    }

    $dt = new DateTime('@' . $timestamp);
    $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
    return $dt->format('d M Y H:i:s') . ' WIB';
}

function resolveAdminGowaRuntimeConfig(PDO $db): array
{
    $cfg = [
        'base_url' => defined('GOWA_BASE_URL') ? rtrim((string) GOWA_BASE_URL, '/') : '',
        'username' => defined('GOWA_USERNAME') ? (string) GOWA_USERNAME : '',
        'password' => defined('GOWA_PASSWORD') ? (string) GOWA_PASSWORD : '',
        'source' => 'ENV',
    ];

    try {
        $stmt = $db->query(
            "SELECT base_url, api_key, api_secret
             FROM api_configurations
             WHERE service_type = 'whatsapp' AND is_enabled = 1
             ORDER BY updated_at DESC, id DESC
             LIMIT 1"
        );
        $row = $stmt->fetch();
        if (is_array($row)) {
            $dbBaseUrl = trim((string) ($row['base_url'] ?? ''));
            $dbUser = trim((string) ($row['api_key'] ?? ''));
            $dbPass = (string) ($row['api_secret'] ?? '');

            if ($dbBaseUrl !== '') {
                $cfg['base_url'] = rtrim($dbBaseUrl, '/');
            }
            if ($dbUser !== '') {
                $cfg['username'] = $dbUser;
            }
            if ($dbPass !== '') {
                $cfg['password'] = $dbPass;
            }
            if ($dbBaseUrl !== '' || $dbUser !== '' || $dbPass !== '') {
                $cfg['source'] = 'DB';
            }
        }
    } catch (Throwable $e) {
        // Keep ENV fallback.
    }

    return $cfg;
}

// 1. Database Response Time
$dbHealthPct  = 0;
$dbLatencyMs  = null;
$dbHealthLabel = 'Error';
try {
    $t0 = microtime(true);
    $db->query('SELECT 1');
    $dbLatencyMs = (int) round((microtime(true) - $t0) * 1000);
    $dbHealthPct = healthPctFromMs($dbLatencyMs, true, [100 => 40, 50 => 60, 20 => 75, 5 => 90]);
    $dbHealthLabel = $dbLatencyMs . ' ms';
} catch (Throwable $e) {
    $dbHealthLabel = 'Error';
}

// 2. WhatsApp Gateway (GOWA) ping
$gowaHealthPct   = 0;
$gowaHealthLabel = 'Unreachable';
$gowaOk          = false;
$gowaCfg = resolveAdminGowaRuntimeConfig($db);
$gowaCfgSource = (string) ($gowaCfg['source'] ?? 'ENV');
if ((string) ($gowaCfg['base_url'] ?? '') !== '') {
    $gowaUrl = rtrim((string) ($gowaCfg['base_url'] ?? ''), '/');
    $t0 = microtime(true);
    $ch = curl_init($gowaUrl . '/app/devices');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_USERPWD        => (string) ($gowaCfg['username'] ?? '') . ':' . (string) ($gowaCfg['password'] ?? ''),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    curl_exec($ch);
    $gowaHttpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $gowaLatencyMs = (int) round((microtime(true) - $t0) * 1000);
    curl_close($ch);
    $gowaOk = ($gowaHttpCode >= 200 && $gowaHttpCode < 500);
    if ($gowaOk) {
        $gowaHealthPct   = healthPctFromMs($gowaLatencyMs, true, [800 => 40, 300 => 65, 100 => 85]);
        $gowaHealthLabel = $gowaLatencyMs . ' ms (' . $gowaCfgSource . ')';
    } else {
        $gowaHealthLabel = ($gowaHttpCode > 0 ? 'HTTP ' . $gowaHttpCode : 'Unreachable') . ' (' . $gowaCfgSource . ')';
    }
} else {
    $gowaHealthLabel = 'Not configured (' . $gowaCfgSource . ')';
}

// 3. Active User Ratio (active / total)
$activeUserCount  = count(array_filter($users, fn($u) => (int) $u['is_active'] === 1));
$userActiveRatio  = $totalUsers > 0 ? (int) round($activeUserCount / $totalUsers * 100) : 100;
$userHealthLabel  = $activeUserCount . ' / ' . $totalUsers . ' active';

// 4. WA Device Connected Rate
$waTotal      = 0;
$waConnected  = 0;
$waHealthPct  = 100;
$waHealthLabel = 'No devices';
try {
    $waStmt = $db->query("SELECT status, COUNT(*) AS cnt FROM wa_accounts GROUP BY status");
    foreach ($waStmt->fetchAll() as $row) {
        $waTotal += (int) $row['cnt'];
        if ($row['status'] === 'connected') {
            $waConnected = (int) $row['cnt'];
        }
    }
    if ($waTotal > 0) {
        $waHealthPct   = (int) round($waConnected / $waTotal * 100);
        $waHealthLabel = $waConnected . ' / ' . $waTotal . ' connected';
    }
} catch (Throwable $e) {
    $waHealthPct   = 0;
    $waHealthLabel = 'Error';
}

// 5. Cron Reconnect Run Indicator (already run vs not yet run)
$cronReconnectHealthPct = 20;
$cronReconnectHealthLabel = 'Belum dijalankan';
$cronReconnectLastRunLabel = '-';
try {
    $cronStmt = $db->prepare('SELECT config_value FROM app_settings WHERE config_key = :config_key LIMIT 1');
    $cronStmt->execute(['config_key' => 'cron.reconnect.last_run']);
    $cronLastRun = trim((string) ($cronStmt->fetchColumn() ?: ''));
    if ($cronLastRun !== '') {
        $cronReconnectHealthPct = 100;
        $cronReconnectHealthLabel = 'Sudah dijalankan';
        $cronReconnectLastRunLabel = formatJakartaDateTime($cronLastRun);
    }
} catch (Throwable $e) {
    $cronReconnectHealthPct = 0;
    $cronReconnectHealthLabel = 'Error';
    $cronReconnectLastRunLabel = '-';
}

// 6. Queue Worker Run Indicator (already run vs not yet run)
$queueWorkerHealthPct = 20;
$queueWorkerHealthLabel = 'Belum dijalankan';
$queueWorkerLastRunLabel = '-';
try {
    $queueLastStmt = $db->prepare('SELECT config_value FROM app_settings WHERE config_key = :config_key LIMIT 1');
    $queueLastStmt->execute(['config_key' => 'cron.queue_worker.last_run']);
    $queueLastRun = trim((string) ($queueLastStmt->fetchColumn() ?: ''));

    if ($queueLastRun !== '') {
        $queueWorkerHealthPct = 100;
        $queueWorkerHealthLabel = 'Sudah dijalankan';
        $queueWorkerLastRunLabel = formatJakartaDateTime($queueLastRun);
    }
} catch (Throwable $e) {
    $queueWorkerHealthPct = 0;
    $queueWorkerHealthLabel = 'Error';
    $queueWorkerLastRunLabel = '-';
}

$sessionUserName = $_SESSION['full_name'] ?? 'Admin';
$sessionUserEmail = $_SESSION['email'] ?? '';
$sessionPhoneNumber = $_SESSION['phone_number'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php seoRenderHeadTags('Dashboard - ' . webCompanyName()); ?>
    <script>
        window.DAYNIGHT_SESSION = <?php echo json_encode([
            'fullName'    => $sessionUserName,
            'email'       => $sessionUserEmail,
            'phoneNumber' => $sessionPhoneNumber,
            'role'        => 'admin',
            'roles'       => $sessionRoles,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

        localStorage.setItem('daynight-user-name', window.DAYNIGHT_SESSION.fullName || 'Admin');
        localStorage.setItem('daynight-user-email', window.DAYNIGHT_SESSION.email || '');
        localStorage.setItem('daynight-user-role', 'admin');
        localStorage.setItem('daynight-user-roles', JSON.stringify(window.DAYNIGHT_SESSION.roles || []));

        if (localStorage.getItem('daynight-theme') === 'carbon') {
            document.documentElement.classList.add('carbon');
        }
    </script>
    <link rel="stylesheet" href="../templatemo-daynight-style.css?v=<?php echo (int) (file_exists(__DIR__ . '/../templatemo-daynight-style.css') ? filemtime(__DIR__ . '/../templatemo-daynight-style.css') : time()); ?>">
    <style>
        .admin-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .dashboard-bottom-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .admin-table {
            width: 100%;
            border-collapse: collapse;
        }

        .admin-table th,
        .admin-table td {
            text-align: left;
            padding: 0.75rem 0.5rem;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.875rem;
        }

        .admin-table th {
            font-size: 0.75rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .admin-tag {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.5rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            border: 1px solid transparent;
        }

        .admin-tag.super {
            color: var(--accent);
            background: var(--accent-light);
            border-color: rgba(56, 189, 248, 0.35);
        }

        .admin-tag.editor {
            color: var(--warning);
            background: rgba(245, 158, 11, 0.12);
            border-color: rgba(245, 158, 11, 0.3);
        }

        .admin-tag.ops {
            color: var(--success);
            background: rgba(34, 197, 94, 0.12);
            border-color: rgba(34, 197, 94, 0.3);
        }

        .admin-tag.user {
            color: var(--success);
            background: rgba(34, 197, 94, 0.12);
            border-color: rgba(34, 197, 94, 0.3);
        }

        .admin-form-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.875rem;
        }

        .admin-form-grid .full {
            grid-column: 1 / -1;
        }

        .form-label {
            display: block;
            font-size: 0.8125rem;
            margin-bottom: 0.35rem;
            color: var(--text-secondary);
        }

        .form-input,
        .form-select {
            width: 100%;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            background: var(--bg-surface);
            color: var(--text-primary);
            padding: 0.65rem 0.75rem;
            font-size: 0.875rem;
        }

        .form-input::placeholder {
            color: var(--text-secondary);
            opacity: 1;
        }

        .admin-alert {
            padding: 0.625rem 0.75rem;
            border-radius: 10px;
            font-size: 0.8125rem;
            margin-bottom: 0.875rem;
        }

        .admin-alert.error {
            background: rgba(239, 68, 68, 0.08);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.28);
        }

        .admin-alert.success {
            background: rgba(34, 197, 94, 0.08);
            color: var(--success);
            border: 1px solid rgba(34, 197, 94, 0.28);
        }

        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(2px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            z-index: 1400;
        }

        .modal-overlay[hidden] {
            display: none !important;
        }

        .modal-card {
            width: min(760px, calc(100vw - 2rem));
            max-height: calc(100vh - 2rem);
            overflow: auto;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            background: var(--bg-card);
            box-shadow: 0 25px 60px rgba(15, 23, 42, 0.35);
            padding: 1rem;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .modal-card .form-label {
            color: var(--text-primary);
            font-weight: 600;
        }

        .modal-close {
            border: 1px solid var(--border-color);
            background: transparent;
            color: var(--text-secondary);
            border-radius: 8px;
            cursor: pointer;
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .modal-close:hover {
            color: var(--text-primary);
            border-color: var(--text-secondary);
        }

        .health-item {
            margin-bottom: 1rem;
        }

        .health-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.8125rem;
            margin-bottom: 0.4rem;
            color: var(--text-secondary);
        }

        .health-bar {
            height: 8px;
            border-radius: 999px;
            background: var(--bg-surface);
            overflow: hidden;
        }

        .health-fill {
            height: 100%;
            border-radius: 999px;
            background: var(--accent);
        }

        .health-fill.warning {
            background: var(--warning);
        }

        .health-fill.success {
            background: var(--success);
        }

        .health-fill.danger {
            background: var(--danger);
        }

        .activity-list {
            list-style: none;
            display: grid;
            gap: 0.875rem;
        }

        .activity-line {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px dashed var(--border-color);
            font-size: 0.875rem;
        }

        .activity-line:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .activity-time {
            color: var(--text-secondary);
            font-size: 0.75rem;
            white-space: nowrap;
        }

        .table-actions {
            display: flex;
            gap: 0.4rem;
            align-items: center;
            white-space: nowrap;
        }

        .table-actions .btn {
            padding: 0.3rem 0.55rem;
            font-size: 0.75rem;
            border-radius: 7px;
        }

        @media (max-width: 980px) {
            .admin-grid {
                grid-template-columns: 1fr;
            }

            .dashboard-bottom-grid {
                grid-template-columns: 1fr;
            }

            .admin-form-grid {
                grid-template-columns: 1fr;
            }
        }

        .stat-card.clickable {
            cursor: pointer;
            transition: border-color 0.15s, background 0.15s;
        }

        .stat-card.clickable:hover {
            border-color: var(--accent);
        }

        .stat-card.clickable.panel-open {
            border-color: var(--accent);
            background: var(--accent-light, rgba(56,189,248,0.08));
        }

        .dash-mini-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        .dash-mini-table th,
        .dash-mini-table td {
            text-align: left;
            padding: 0.6rem 0.5rem;
            border-bottom: 1px solid var(--border-color);
        }

        .dash-mini-table th {
            font-size: 0.75rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .dash-status-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 5px;
            vertical-align: middle;
        }

        .dash-status-dot.connected,
        .dash-status-dot.active {
            background: var(--success);
        }

        .dash-status-dot.disconnected,
        .dash-status-dot.inactive,
        .dash-status-dot.suspended {
            background: var(--danger);
        }
    </style>
</head>
<body>
    <?php seoRenderBodyOpenTags(); ?>
    <div class="mobile-menu-overlay"></div>

    <div class="mobile-menu">
        <div class="mobile-menu-header">
            <a href="../index.php" class="logo">
                <div class="logo-icon">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                    </svg>
                </div>
                <?php echo htmlspecialchars(webCompanyName(), ENT_QUOTES, 'UTF-8'); ?>
            </a>
            <button class="mobile-menu-close" onclick="closeMobileMenu()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <nav class="mobile-menu-nav">
            <a href="../index.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>Dashboard</a>
            <a href="wa-devices.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12" y2="18"/></svg>WA Devices</a>
            <a href="vpn-users.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>VPN Management</a>
            <a href="proxy-routes.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M2 12h3M19 12h3M12 2v3M12 19v3"/><path d="M4.93 4.93l2.12 2.12M16.95 16.95l2.12 2.12M19.07 4.93l-2.12 2.12M7.05 16.95l-2.12 2.12"/></svg>Proxy Routes</a>
        <a href="config.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>Config</a>
            <a href="subscriptions.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>Subscriptions</a>
            <a href="audit-log.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3h18v18H3z"/><path d="M7 7h10"/><path d="M7 12h10"/><path d="M7 17h6"/></svg>Audit Log</a>
        </nav>
        <div class="mobile-menu-footer">
            <a href="../api/logout.php" class="mobile-logout-btn">Logout</a>
            <div class="theme-toggle">
                <button class="theme-btn theme-btn-snow active" onclick="setTheme('snow')" title="Snow Edition">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="5"/>
                        <line x1="12" y1="1" x2="12" y2="3"/>
                        <line x1="12" y1="21" x2="12" y2="23"/>
                        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
                        <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                        <line x1="1" y1="12" x2="3" y2="12"/>
                        <line x1="21" y1="12" x2="23" y2="12"/>
                        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
                        <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                    </svg>
                </button>
                <button class="theme-btn theme-btn-carbon" onclick="setTheme('carbon')" title="Carbon Edition">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <div class="app-container">
        <nav class="top-nav">
            <div class="nav-container">
                <div class="nav-left">
                    <a href="../index.php" class="logo">
                        <div class="logo-icon">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                            </svg>
                        </div>
                        <?php echo htmlspecialchars(webCompanyName(), ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                    <div class="nav-menu">
                        <div class="nav-item"><a href="../index.php" class="nav-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>Dashboard</a></div>
                        <div class="nav-item"><a href="wa-devices.php" class="nav-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>WA Devices</a></div>
                        <div class="nav-item"><a href="vpn-users.php" class="nav-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>VPN Management</a></div>
                                            <div class="nav-item"><a href="proxy-routes.php" class="nav-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M2 12h3M19 12h3M12 2v3M12 19v3"/><path d="M4.93 4.93l2.12 2.12M16.95 16.95l2.12 2.12M19.07 4.93l-2.12 2.12M7.05 16.95l-2.12 2.12"/></svg>Proxy Routes</a></div>
                    <div class="nav-item"><a href="config.php" class="nav-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>Config</a></div>
                        <div class="nav-item"><a href="subscriptions.php" class="nav-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>Subscriptions</a></div>
                        <div class="nav-item"><a href="audit-log.php" class="nav-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3h18v18H3z"/><path d="M7 7h10"/><path d="M7 12h10"/><path d="M7 17h6"/></svg>Audit Log</a></div>
                    </div>
                </div>
                <div class="nav-right">
                    <div class="theme-toggle">
                        <button class="theme-btn theme-btn-snow active" onclick="setTheme('snow')" title="Snow Edition">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="5"/>
                                <line x1="12" y1="1" x2="12" y2="3"/>
                                <line x1="12" y1="21" x2="12" y2="23"/>
                                <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
                                <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                                <line x1="1" y1="12" x2="3" y2="12"/>
                                <line x1="21" y1="12" x2="23" y2="12"/>
                                <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
                                <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                            </svg>
                        </button>
                        <button class="theme-btn theme-btn-carbon" onclick="setTheme('carbon')" title="Carbon Edition">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                            </svg>
                        </button>
                    </div>
                    <div class="account-menu-wrap" id="account-menu-wrap">
                        <button class="user-menu" onclick="toggleAccountDropdown()">
                            <div class="user-avatar">A</div>
                            <span class="user-name" data-current-user>Admin</span>
                        </button>
                        <div class="account-dropdown">
                            <button onclick="openAccountModal('edit-profile')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Edit Profil</button>
                            <button onclick="openAccountModal('change-password')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Ganti Password</button>
                        </div>
                    </div>
                    <a href="../api/logout.php" class="btn-logout" title="Logout">Logout</a>
                    <button class="mobile-menu-btn" onclick="toggleMobileMenu()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="3" y1="12" x2="21" y2="12"/>
                            <line x1="3" y1="6" x2="21" y2="6"/>
                            <line x1="3" y1="18" x2="21" y2="18"/>
                        </svg>
                    </button>
                </div>
            </div>
        </nav>

        <main class="main-content">
            <div class="page-header" style="display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; flex-wrap:wrap;">
                <div>
                    <h1 id="greeting" class="greeting">Dashboard</h1>
                    <p class="greeting-sub">Control akses, kesehatan sistem, dan operasional harian.</p>
                </div>
                <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
                    <a href="config.php" class="btn btn-secondary">Open Config</a>
                    <a href="audit-log.php" class="btn btn-secondary">Open Audit Log</a>
                    <a href="wa-devices.php" class="btn btn-primary">Manage Devices</a>
                    <a href="vpn-users.php" class="btn btn-secondary">Create VPN</a>
                    <button type="button" class="btn btn-secondary" onclick="openCreateUserModal()">Create User</button>
                </div>
            </div>

            <?php if ($actionSuccess !== ''): ?>
                <div class="admin-alert success" style="margin-bottom:1rem;"><?php echo htmlspecialchars($actionSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($actionError !== '' && $postAction !== 'create_user'): ?>
                <div class="admin-alert error" style="margin-bottom:1rem;"><?php echo htmlspecialchars($actionError, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Total User Terdaftar</div>
                    <div class="stat-value"><?php echo (int) $totalUsers; ?></div>
                    <div class="stat-change positive">Live from database</div>
                </div>
                <div class="stat-card clickable" id="dash-stat-wa" onclick="dashToggle('wa')" title="Klik untuk lihat tabel WA device">
                    <div class="stat-label">WA API GATEWAY</div>
                    <div class="stat-value"><?php echo $waConnected; ?>/<?php echo $waTotal; ?></div>
                    <div class="stat-change">Klik untuk lihat device ↓</div>
                </div>
                <div class="stat-card clickable" id="dash-stat-vpn" onclick="dashToggle('vpn')" title="Klik untuk lihat daftar akun VPN">
                    <div class="stat-label">AKUN VPN</div>
                    <div class="stat-value">Ready</div>
                    <div class="stat-change">Klik untuk lihat akun ↓</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">API Uptime</div>
                    <div class="stat-value">99.94%</div>
                    <div class="stat-change positive">Last 30 days</div>
                </div>
            </div>

            <div id="dash-wa-panel" hidden style="margin-bottom:1.5rem;">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">WA API GATEWAY</h3>
                            <p class="card-subtitle">Daftar semua WA device</p>
                        </div>
                        <button class="btn btn-secondary" style="font-size:0.75rem;padding:0.35rem 0.75rem;" onclick="dashToggle('wa')">Tutup ×</button>
                    </div>
                    <div id="dash-wa-body" style="overflow:auto;"><p style="color:var(--text-secondary);font-size:0.875rem;">Memuat data...</p></div>
                </div>
            </div>

            <div id="dash-vpn-panel" hidden style="margin-bottom:1.5rem;">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">AKUN VPN</h3>
                            <p class="card-subtitle">Daftar semua akun VPN terdaftar</p>
                        </div>
                        <button class="btn btn-secondary" style="font-size:0.75rem;padding:0.35rem 0.75rem;" onclick="dashToggle('vpn')">Tutup ×</button>
                    </div>
                    <div id="dash-vpn-body" style="overflow:auto;"><p style="color:var(--text-secondary);font-size:0.875rem;">Memuat data...</p></div>
                </div>
            </div>

            <section class="admin-grid">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">Detail User Terdaftar</h3>
                            <p class="card-subtitle">Data user, role, status, dan aktivitas login</p>
                        </div>
                    </div>
                    <div style="overflow:auto;">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Email</th>
                                    <th>No. HP</th>
                                    <th>Roles</th>
                                    <th>Status</th>
                                    <th>Last Login</th>
                                    <th>Created</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$users): ?>
                                    <tr>
                                        <td colspan="8" style="color:var(--text-secondary);">Belum ada user terdaftar.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($users as $u): ?>
                                        <?php
                                            $rawCodes = array_filter(array_map('trim', explode(',', (string) ($u['role_codes'] ?? ''))));
                                            $codes = [];
                                            foreach ($rawCodes as $rawCode) {
                                                $codes[] = ($rawCode === 'admin' || $rawCode === 'super_admin') ? 'admin' : 'user';
                                            }
                                            $codes = array_values(array_unique($codes));
                                            $statusText = ((int) $u['is_active'] === 1) ? 'Active' : 'Inactive';
                                            $lastLogin = $u['last_login_at'] ? date('d M Y H:i', strtotime((string) $u['last_login_at'])) : '-';
                                            $createdAt = $u['created_at'] ? date('d M Y H:i', strtotime((string) $u['created_at'])) : '-';
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string) $u['full_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars((string) $u['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars((string) (($u['phone_number'] ?? '') !== '' ? $u['phone_number'] : '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td>
                                                <?php if (!$codes): ?>
                                                    <span class="admin-tag">-</span>
                                                <?php else: ?>
                                                    <?php foreach ($codes as $code): ?>
                                                        <?php
                                                            $tagClass = 'editor';
                                                            if ($code === 'admin') {
                                                                $tagClass = 'super';
                                                            } elseif ($code === 'user') {
                                                                $tagClass = 'user';
                                                            }
                                                        ?>
                                                        <span class="admin-tag <?php echo $tagClass; ?>" style="margin-right:0.3rem;margin-bottom:0.3rem;">
                                                            <?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($lastLogin, ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td>
                                                <?php $primaryRoleCode = in_array('admin', $codes, true) ? 'admin' : 'user'; ?>
                                                <div class="table-actions">
                                                    <button
                                                        type="button"
                                                        class="btn btn-secondary"
                                                        onclick="openEditUserModal(this)"
                                                        data-user-id="<?php echo (int) $u['id']; ?>"
                                                        data-full-name="<?php echo htmlspecialchars((string) $u['full_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-email="<?php echo htmlspecialchars((string) $u['email'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-phone-number="<?php echo htmlspecialchars((string) ($u['phone_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-role-code="<?php echo htmlspecialchars($primaryRoleCode, ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-is-active="<?php echo (int) $u['is_active']; ?>"
                                                    >
                                                        Detail
                                                    </button>
                                                    <form method="post" onsubmit="return confirm('Hapus user ini?');" style="display:inline;">
                                                        <input type="hidden" name="action" value="delete_user">
                                                        <input type="hidden" name="user_id" value="<?php echo (int) $u['id']; ?>">
                                                        <button type="submit" class="btn btn-secondary">Delete</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </section>

            <section class="dashboard-bottom-grid">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">Security Checklist</h3>
                            <p class="card-subtitle">Action items to close this week</p>
                        </div>
                    </div>
                    <ul class="activity-list">
                        <li class="activity-line"><span>Rotate API secret for production gateway</span><span class="activity-time">Due today</span></li>
                        <li class="activity-line"><span>Enforce MFA for all editor-level users</span><span class="activity-time">Due tomorrow</span></li>
                        <li class="activity-line"><span>Audit inactive sessions older than 14 days</span><span class="activity-time">2 pending</span></li>
                    </ul>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">System Health</h3>
                            <p class="card-subtitle">Real-time service monitor</p>
                        </div>
                    </div>
                    <div class="health-item">
                        <div class="health-meta">
                            <span>Database Response</span>
                            <span><?php echo htmlspecialchars($dbHealthLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="health-bar"><div class="health-fill <?php echo healthClass($dbHealthPct); ?>" style="width:<?php echo $dbHealthPct; ?>%;"></div></div>
                    </div>
                    <div class="health-item">
                        <div class="health-meta">
                            <span>WhatsApp Gateway</span>
                            <span><?php echo htmlspecialchars($gowaHealthLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="health-bar"><div class="health-fill <?php echo healthClass($gowaHealthPct); ?>" style="width:<?php echo max(4, $gowaHealthPct); ?>%;"></div></div>
                    </div>
                    <div class="health-item">
                        <div class="health-meta">
                            <span>Active Users</span>
                            <span><?php echo htmlspecialchars($userHealthLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="health-bar"><div class="health-fill <?php echo healthClass($userActiveRatio); ?>" style="width:<?php echo max(4, $userActiveRatio); ?>%;"></div></div>
                    </div>
                    <div class="health-item">
                        <div class="health-meta">
                            <span>WA Device Connected</span>
                            <span><?php echo htmlspecialchars($waHealthLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="health-bar"><div class="health-fill <?php echo healthClass($waHealthPct); ?>" style="width:<?php echo max(4, $waHealthPct); ?>%;"></div></div>
                    </div>
                    <div class="health-item">
                        <div class="health-meta">
                            <span>Cron Reconnect</span>
                            <span><?php echo htmlspecialchars($cronReconnectHealthLabel . ' | Last run: ' . $cronReconnectLastRunLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="health-bar"><div class="health-fill <?php echo healthClass($cronReconnectHealthPct); ?>" style="width:<?php echo max(4, $cronReconnectHealthPct); ?>%;"></div></div>
                    </div>
                    <div class="health-item" style="margin-bottom:0;">
                        <div class="health-meta">
                            <span>Queue Worker</span>
                            <span><?php echo htmlspecialchars($queueWorkerHealthLabel . ' | Last run: ' . $queueWorkerLastRunLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="health-bar"><div class="health-fill <?php echo healthClass($queueWorkerHealthPct); ?>" style="width:<?php echo max(4, $queueWorkerHealthPct); ?>%;"></div></div>
                    </div>
                </div>
            </section>

            <div id="create-user-modal" class="modal-overlay" hidden>
                <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="create-user-modal-title">
                    <div class="modal-header">
                        <div>
                            <h3 id="create-user-modal-title" class="card-title">Create User</h3>
                            <p class="card-subtitle">Tambah akun baru untuk operator sistem</p>
                        </div>
                        <button type="button" class="modal-close" onclick="closeCreateUserModal()" aria-label="Close create user modal">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <line x1="18" y1="6" x2="6" y2="18"/>
                                <line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </button>
                    </div>

                    <?php if ($actionError !== '' && $postAction === 'create_user'): ?>
                        <div class="admin-alert error"><?php echo htmlspecialchars($actionError, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>

                    <form method="post" class="admin-form-grid">
                        <input type="hidden" name="action" value="create_user">
                        <div class="full">
                            <label class="form-label" for="modal_full_name">Nama Lengkap</label>
                            <input class="form-input" type="text" id="modal_full_name" name="full_name" placeholder="Contoh: Budi Santoso" autocomplete="name" required>
                        </div>
                        <div>
                            <label class="form-label" for="modal_email">Email / Username</label>
                            <input class="form-input" type="text" id="modal_email" name="email" placeholder="Contoh: budi@company.com atau budi" autocomplete="username" required>
                        </div>
                        <div>
                            <label class="form-label" for="modal_phone_number">No. HP</label>
                            <input class="form-input" type="text" id="modal_phone_number" name="phone_number" placeholder="Contoh: 08123456789" autocomplete="tel" required>
                        </div>
                        <div>
                            <label class="form-label" for="modal_password">Password</label>
                            <input class="form-input" type="password" id="modal_password" name="password" placeholder="Minimal 6 karakter" autocomplete="new-password" minlength="6" required>
                        </div>
                        <div>
                            <label class="form-label" for="modal_role_code">Role</label>
                            <select class="form-select" id="modal_role_code" name="role_code" required>
                                <option value="">Pilih role</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo htmlspecialchars((string) $role['code'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars((string) $role['code'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label" for="modal_is_active">Status Akun</label>
                            <label style="display:flex;align-items:center;gap:0.5rem;margin-top:0.55rem;font-size:0.875rem;">
                                <input type="checkbox" id="modal_is_active" name="is_active" checked>
                                Aktifkan user setelah dibuat
                            </label>
                        </div>
                        <div class="full" style="display:flex;justify-content:flex-end;gap:0.6rem;">
                            <button type="button" class="btn btn-secondary" onclick="closeCreateUserModal()">Cancel</button>
                            <button type="submit" class="btn btn-primary">Create User</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="edit-user-modal" class="modal-overlay" hidden>
                <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="edit-user-modal-title">
                    <div class="modal-header">
                        <div>
                            <h3 id="edit-user-modal-title" class="card-title">Detail User</h3>
                            <p class="card-subtitle">Edit profil user terdaftar</p>
                        </div>
                        <button type="button" class="modal-close" onclick="closeEditUserModal()" aria-label="Close edit user modal">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <line x1="18" y1="6" x2="6" y2="18"/>
                                <line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </button>
                    </div>

                    <form method="post" class="admin-form-grid">
                        <input type="hidden" name="action" value="update_user">
                        <input type="hidden" name="user_id" id="edit_user_id" value="">
                        <div class="full">
                            <label class="form-label" for="edit_full_name">Nama Lengkap</label>
                            <input class="form-input" type="text" id="edit_full_name" name="full_name" required>
                        </div>
                        <div>
                            <label class="form-label" for="edit_email">Email / Username</label>
                            <input class="form-input" type="text" id="edit_email" disabled>
                        </div>
                        <div>
                            <label class="form-label" for="edit_phone_number">No. HP</label>
                            <input class="form-input" type="text" id="edit_phone_number" name="phone_number" required>
                        </div>
                        <div>
                            <label class="form-label" for="edit_role_code">Role</label>
                            <select class="form-select" id="edit_role_code" name="role_code" required>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo htmlspecialchars((string) $role['code'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars((string) $role['code'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label" for="edit_is_active">Status Akun</label>
                            <label style="display:flex;align-items:center;gap:0.5rem;margin-top:0.55rem;font-size:0.875rem;">
                                <input type="checkbox" id="edit_is_active" name="is_active" value="1">
                                User aktif
                            </label>
                        </div>
                        <div class="full" style="display:flex;justify-content:flex-end;gap:0.6rem;">
                            <button type="button" class="btn btn-secondary" onclick="closeEditUserModal()">Cancel</button>
                            <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                        </div>
                    </form>
                </div>
            </div>
        </main>

        <footer class="footer">
            <p>&copy; 2026 TEAM <?php echo htmlspecialchars(webCompanyName(), ENT_QUOTES, 'UTF-8'); ?></p>
        </footer>
    </div>

    <script>
        (function () {
            var _loaded = { wa: false, vpn: false };

            window.dashToggle = function (panel) {
                var waPanel = document.getElementById('dash-wa-panel');
                var vpnPanel = document.getElementById('dash-vpn-panel');
                var waCard = document.getElementById('dash-stat-wa');
                var vpnCard = document.getElementById('dash-stat-vpn');
                if (panel === 'wa') {
                    var opening = waPanel.hidden;
                    waPanel.hidden = !opening;
                    vpnPanel.hidden = true;
                    if (waCard) waCard.classList.toggle('panel-open', opening);
                    if (vpnCard) vpnCard.classList.remove('panel-open');
                    if (opening && !_loaded.wa) { _loaded.wa = true; loadWa(); }
                    if (opening) waPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } else {
                    var opening2 = vpnPanel.hidden;
                    vpnPanel.hidden = !opening2;
                    waPanel.hidden = true;
                    if (vpnCard) vpnCard.classList.toggle('panel-open', opening2);
                    if (waCard) waCard.classList.remove('panel-open');
                    if (opening2 && !_loaded.vpn) { _loaded.vpn = true; loadVpn(); }
                    if (opening2) vpnPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            };

            function esc(s) {
                var d = document.createElement('div');
                d.appendChild(document.createTextNode(String(s || '')));
                return d.innerHTML;
            }

            function loadWa() {
                var wrap = document.getElementById('dash-wa-body');
                fetch('../api/wa-account.php', { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        var list = res.data || [];
                        if (!list.length) { wrap.innerHTML = '<p style="color:var(--text-secondary);font-size:0.875rem;">Belum ada device.</p>'; return; }
                        var h = '<table class="dash-mini-table"><thead><tr><th>Label</th><th>Phone</th><th>Status</th><th>Owner</th></tr></thead><tbody>';
                        list.forEach(function (d) {
                            var dot = d.status === 'connected' ? 'connected' : 'disconnected';
                            h += '<tr><td>' + esc(d.label || d.device_id || '-') + '</td>';
                            h += '<td>' + esc(d.phone_jid || '-') + '</td>';
                            h += '<td><span class="dash-status-dot ' + dot + '"></span>' + esc(d.status || '-') + '</td>';
                            h += '<td>' + esc(d.owner_name || d.owner_email || '-') + '</td></tr>';
                        });
                        h += '</tbody></table>';
                        wrap.innerHTML = h;
                    })
                    .catch(function () { wrap.innerHTML = '<p style="color:var(--danger);font-size:0.875rem;">Gagal memuat data.</p>'; });
            }

            function loadVpn() {
                var wrap = document.getElementById('dash-vpn-body');
                fetch('../api/vpn-controller.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'list_users' })
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        var list = (res.data && res.data.items) || [];
                        if (!list.length) { wrap.innerHTML = '<p style="color:var(--text-secondary);font-size:0.875rem;">Belum ada akun VPN.</p>'; return; }
                        var h = '<table class="dash-mini-table"><thead><tr><th>Username</th><th>Status</th><th>Owner</th></tr></thead><tbody>';
                        list.forEach(function (u) {
                            var dot = u.vpn_status === 'active' ? 'active' : 'inactive';
                            h += '<tr><td>' + esc(u.username || '-') + '</td>';
                            h += '<td><span class="dash-status-dot ' + dot + '"></span>' + esc(u.vpn_status || '-') + '</td>';
                            h += '<td>' + esc(u.owner_name || u.owner_email || '-') + '</td></tr>';
                        });
                        h += '</tbody></table>';
                        wrap.innerHTML = h;
                    })
                    .catch(function () { wrap.innerHTML = '<p style="color:var(--danger);font-size:0.875rem;">Gagal memuat data.</p>'; });
            }
        })();

        (function () {
            const createModal = document.getElementById('create-user-modal');
            const editModal = document.getElementById('edit-user-modal');
            if (!createModal || !editModal) {
                return;
            }

            const setCreateModalOpen = (isOpen) => {
                createModal.hidden = !isOpen;
                document.body.style.overflow = isOpen ? 'hidden' : '';
            };

            const setEditModalOpen = (isOpen) => {
                editModal.hidden = !isOpen;
                document.body.style.overflow = isOpen ? 'hidden' : '';
            };

            const closeAllModals = () => {
                createModal.hidden = true;
                editModal.hidden = true;
                document.body.style.overflow = '';
            };

            window.openEditUserModal = function (buttonEl) {
                const dataset = buttonEl ? buttonEl.dataset : {};
                const userIdField = document.getElementById('edit_user_id');
                const fullNameField = document.getElementById('edit_full_name');
                const emailField = document.getElementById('edit_email');
                const phoneField = document.getElementById('edit_phone_number');
                const roleField = document.getElementById('edit_role_code');
                const isActiveField = document.getElementById('edit_is_active');

                if (!userIdField || !fullNameField || !emailField || !phoneField || !roleField || !isActiveField) {
                    return;
                }

                userIdField.value = dataset.userId || '';
                fullNameField.value = dataset.fullName || '';
                emailField.value = dataset.email || '';
                phoneField.value = dataset.phoneNumber || '';
                roleField.value = dataset.roleCode || 'user';
                isActiveField.checked = dataset.isActive === '1';
                setEditModalOpen(true);
            };

            window.closeEditUserModal = function () {
                setEditModalOpen(false);
            };

            window.openCreateUserModal = function () {
                setCreateModalOpen(true);
            };

            window.closeCreateUserModal = function () {
                setCreateModalOpen(false);
            };

            createModal.addEventListener('click', function (event) {
                if (event.target === createModal) {
                    setCreateModalOpen(false);
                }
            });

            editModal.addEventListener('click', function (event) {
                if (event.target === editModal) {
                    setEditModalOpen(false);
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && (!createModal.hidden || !editModal.hidden)) {
                    closeAllModals();
                }
            });

            const shouldOpenCreateModal = <?php echo json_encode($actionError !== '' && $postAction === 'create_user'); ?>;
            if (shouldOpenCreateModal) {
                setCreateModalOpen(true);
            }

            const shouldOpenEditModal = <?php echo json_encode($actionError !== '' && $postAction === 'update_user'); ?>;
            if (shouldOpenEditModal) {
                const editButton = document.querySelector('button[data-user-id="<?php echo (int) ($_POST['user_id'] ?? 0); ?>"]');
                if (editButton) {
                    window.openEditUserModal(editButton);
                } else {
                    const fallbackPayload = <?php echo json_encode([
                        'userId' => (int) ($_POST['user_id'] ?? 0),
                        'fullName' => (string) ($_POST['full_name'] ?? ''),
                        'email' => '',
                        'phoneNumber' => (string) ($_POST['phone_number'] ?? ''),
                        'roleCode' => (string) ($_POST['role_code'] ?? 'user'),
                        'isActive' => isset($_POST['is_active']) ? '1' : '0',
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
                    const userIdField = document.getElementById('edit_user_id');
                    const fullNameField = document.getElementById('edit_full_name');
                    const emailField = document.getElementById('edit_email');
                    const phoneField = document.getElementById('edit_phone_number');
                    const roleField = document.getElementById('edit_role_code');
                    const isActiveField = document.getElementById('edit_is_active');
                    if (userIdField && fullNameField && emailField && phoneField && roleField && isActiveField) {
                        userIdField.value = fallbackPayload.userId || '';
                        fullNameField.value = fallbackPayload.fullName || '';
                        emailField.value = fallbackPayload.email || '';
                        phoneField.value = fallbackPayload.phoneNumber || '';
                        roleField.value = fallbackPayload.roleCode || 'user';
                        isActiveField.checked = fallbackPayload.isActive === '1';
                    }
                    setEditModalOpen(true);
                }
            }
        })();
    </script>
    <script src="../templatemo-daynight-script.js?v=<?php echo (int) (file_exists(__DIR__ . '/../templatemo-daynight-script.js') ? filemtime(__DIR__ . '/../templatemo-daynight-script.js') : time()); ?>"></script>

    <!-- Account Modals -->
    <div class="acct-modal-overlay" id="modal-edit-profile" onclick="if(event.target===this)closeAccountModal('modal-edit-profile')">
        <div class="acct-modal">
            <button class="modal-close" onclick="closeAccountModal('modal-edit-profile')" title="Tutup"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
            <h3>Edit Profil</h3>
            <div class="modal-msg"></div>
            <div class="form-group"><label for="ep-full-name">Nama Lengkap</label><input type="text" id="ep-full-name" placeholder="Nama Lengkap" autocomplete="name"></div>
            <div class="form-group"><label for="ep-phone">No. HP</label><input type="text" id="ep-phone" placeholder="62xxxxxxxxxx"></div>
            <div class="form-group"><label for="ep-email">Email (tidak dapat diubah)</label><input type="email" id="ep-email" disabled></div>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="closeAccountModal('modal-edit-profile')">Batal</button>
                <button class="btn btn-primary" id="ep-submit-btn" onclick="submitEditProfile()">Simpan</button>
            </div>
        </div>
    </div>
    <div class="acct-modal-overlay" id="modal-change-password" onclick="if(event.target===this)closeAccountModal('modal-change-password')">
        <div class="acct-modal">
            <button class="modal-close" onclick="closeAccountModal('modal-change-password')" title="Tutup"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
            <h3>Ganti Password</h3>
            <div class="modal-msg"></div>
            <div class="form-group"><label for="cp-old-password">Password Lama</label><input type="password" id="cp-old-password" placeholder="Password saat ini" autocomplete="current-password"></div>
            <div class="form-group"><label for="cp-new-password">Password Baru</label><input type="password" id="cp-new-password" placeholder="Minimal 6 karakter" autocomplete="new-password"></div>
            <div class="form-group"><label for="cp-confirm-password">Konfirmasi Password Baru</label><input type="password" id="cp-confirm-password" placeholder="Ulangi password baru" autocomplete="new-password"></div>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="closeAccountModal('modal-change-password')">Batal</button>
                <button class="btn btn-primary" id="cp-submit-btn" onclick="submitChangePassword()">Simpan</button>
            </div>
        </div>
    </div>
</body>
</html>
