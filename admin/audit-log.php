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

$sessionUserName = $_SESSION['full_name'] ?? 'Admin';
$sessionUserEmail = $_SESSION['email'] ?? '';
$sessionPhoneNumber = $_SESSION['phone_number'] ?? '';

require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../system/seo.php';

$allowedActions = [
    'wa_device_create',
    'wa_device_delete',
    'wa_device_disconnect',
    'vpn_user_create',
    'vpn_user_enable',
    'vpn_user_disable',
    'vpn_user_disconnect',
    'vpn_user_delete',
    'vpn_pf_create',
    'vpn_pf_delete',
    'vpn_sync_server',
];

$selectedAction = trim($_GET['action'] ?? 'all');
if ($selectedAction !== 'all' && !in_array($selectedAction, $allowedActions, true)) {
    $selectedAction = 'all';
}

$limit = (int) ($_GET['limit'] ?? 100);
if ($limit < 20) {
    $limit = 20;
}
if ($limit > 300) {
    $limit = 300;
}

$db = getDB();
$actionSqlList = [
    'wa_device_create',
    'wa_device_delete',
    'wa_device_disconnect',
    'vpn_user_create',
    'vpn_user_enable',
    'vpn_user_disable',
    'vpn_user_disconnect',
    'vpn_user_delete',
    'vpn_pf_create',
    'vpn_pf_delete',
    'vpn_sync_server',
];
$sql = 'SELECT al.id, al.action, al.target_type, al.target_id, al.detail, al.created_at, u.full_name AS actor_name, u.email AS actor_email
        FROM audit_logs al
        LEFT JOIN users u ON u.id = al.actor_user_id
    WHERE al.action IN ("' . implode('", "', $actionSqlList) . '")';
$params = [];
if ($selectedAction !== 'all') {
    $sql .= ' AND al.action = :action';
    $params['action'] = $selectedAction;
}
$sql .= ' ORDER BY al.created_at DESC LIMIT ' . $limit;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

function actionLabel(string $action): string
{
    $map = [
        'wa_device_create' => 'Create Device',
        'wa_device_delete' => 'Delete Device',
        'wa_device_disconnect' => 'Disconnect Device',
        'vpn_user_create' => 'Create VPN User',
        'vpn_user_enable' => 'Enable VPN User',
        'vpn_user_disable' => 'Disable VPN User',
        'vpn_user_disconnect' => 'Disconnect VPN User',
        'vpn_user_delete' => 'Delete VPN User',
        'vpn_pf_create' => 'Create Port Forwarding',
        'vpn_pf_delete' => 'Delete Port Forwarding',
        'vpn_sync_server' => 'Sync VPN Server',
    ];
    return $map[$action] ?? $action;
}

function actionClass(string $action): string
{
    if ($action === 'wa_device_create' || $action === 'vpn_user_create' || $action === 'vpn_user_enable' || $action === 'vpn_pf_create' || $action === 'vpn_sync_server') {
        return 'green';
    }
    if ($action === 'wa_device_delete' || $action === 'vpn_user_delete' || $action === 'vpn_pf_delete') {
        return 'red';
    }
    if ($action === 'wa_device_disconnect' || $action === 'vpn_user_disconnect' || $action === 'vpn_user_disable') {
        return 'orange';
    }
    return '';
}

function parseLogDetail(?string $json): array
{
    if (!$json) {
        return [];
    }
    $detail = json_decode($json, true);
    if (!is_array($detail)) {
        return [];
    }
    return $detail;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php seoRenderHeadTags('Audit Log - ' . webCompanyName() . ' Admin'); ?>
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
        .audit-controls {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }

        .audit-controls .form-input {
            min-width: 170px;
            width: auto;
        }

        .audit-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        .audit-table th,
        .audit-table td {
            text-align: left;
            padding: 0.75rem 0.625rem;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.875rem;
            vertical-align: top;
        }

        .audit-table th {
            font-size: 0.75rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.03em;
            white-space: nowrap;
        }

        .audit-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.5rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            border: 1px solid transparent;
            white-space: nowrap;
        }

        .audit-badge.green {
            color: var(--success);
            border-color: rgba(34, 197, 94, 0.25);
            background: rgba(34, 197, 94, 0.1);
        }

        .audit-badge.red {
            color: var(--danger);
            border-color: rgba(239, 68, 68, 0.25);
            background: rgba(239, 68, 68, 0.1);
        }

        .audit-badge.orange {
            color: var(--warning);
            border-color: rgba(245, 158, 11, 0.25);
            background: rgba(245, 158, 11, 0.12);
        }

        .mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 0.75rem;
            color: var(--text-secondary);
        }

        .subline {
            display: block;
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-top: 0.15rem;
        }

        .detail-chip {
            display: inline-flex;
            gap: 0.3rem;
            align-items: center;
            padding: 0.18rem 0.4rem;
            border-radius: 999px;
            border: 1px solid var(--border-color);
            background: var(--bg-surface);
            font-size: 0.75rem;
            margin-right: 0.3rem;
            margin-bottom: 0.3rem;
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
            <a href="config.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>Config</a>
            <a href="audit-log.php" class="active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3h18v18H3z"/><path d="M7 7h10"/><path d="M7 12h10"/><path d="M7 17h6"/></svg>Audit Log</a>
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
                        <div class="nav-item"><a href="config.php" class="nav-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>Config</a></div>
                        <div class="nav-item"><a href="audit-log.php" class="nav-link active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3h18v18H3z"/><path d="M7 7h10"/><path d="M7 12h10"/><path d="M7 17h6"/></svg>Audit Log</a></div>
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
                    <h1 class="greeting">Audit Log</h1>
                    <p class="greeting-sub">Riwayat aktivitas WA, VPN user, dan port forwarding dari semua pengguna.</p>
                </div>
                <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
                    <a href="index.php" class="btn btn-secondary">Back To Admin</a>
                    <a href="wa-devices.php" class="btn btn-primary">Open Devices</a>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div>
                        <h3 class="card-title">Recent Events</h3>
                        <p class="card-subtitle">Menampilkan maksimum <?php echo (int) $limit; ?> event terbaru.</p>
                    </div>
                </div>

                <form method="GET" class="audit-controls">
                    <select name="action" class="form-input">
                        <option value="all" <?php echo $selectedAction === 'all' ? 'selected' : ''; ?>>Semua Aksi</option>
                        <option value="wa_device_create" <?php echo $selectedAction === 'wa_device_create' ? 'selected' : ''; ?>>Create Device</option>
                        <option value="wa_device_disconnect" <?php echo $selectedAction === 'wa_device_disconnect' ? 'selected' : ''; ?>>Disconnect Device</option>
                        <option value="wa_device_delete" <?php echo $selectedAction === 'wa_device_delete' ? 'selected' : ''; ?>>Delete Device</option>
                        <option value="vpn_user_create" <?php echo $selectedAction === 'vpn_user_create' ? 'selected' : ''; ?>>Create VPN User</option>
                        <option value="vpn_user_enable" <?php echo $selectedAction === 'vpn_user_enable' ? 'selected' : ''; ?>>Enable VPN User</option>
                        <option value="vpn_user_disable" <?php echo $selectedAction === 'vpn_user_disable' ? 'selected' : ''; ?>>Disable VPN User</option>
                        <option value="vpn_user_disconnect" <?php echo $selectedAction === 'vpn_user_disconnect' ? 'selected' : ''; ?>>Disconnect VPN User</option>
                        <option value="vpn_user_delete" <?php echo $selectedAction === 'vpn_user_delete' ? 'selected' : ''; ?>>Delete VPN User</option>
                        <option value="vpn_pf_create" <?php echo $selectedAction === 'vpn_pf_create' ? 'selected' : ''; ?>>Create Port Forwarding</option>
                        <option value="vpn_pf_delete" <?php echo $selectedAction === 'vpn_pf_delete' ? 'selected' : ''; ?>>Delete Port Forwarding</option>
                        <option value="vpn_sync_server" <?php echo $selectedAction === 'vpn_sync_server' ? 'selected' : ''; ?>>Sync VPN Server</option>
                    </select>

                    <select name="limit" class="form-input">
                        <option value="50" <?php echo $limit === 50 ? 'selected' : ''; ?>>50 baris</option>
                        <option value="100" <?php echo $limit === 100 ? 'selected' : ''; ?>>100 baris</option>
                        <option value="200" <?php echo $limit === 200 ? 'selected' : ''; ?>>200 baris</option>
                        <option value="300" <?php echo $limit === 300 ? 'selected' : ''; ?>>300 baris</option>
                    </select>

                    <button class="btn btn-secondary" type="submit">Apply Filter</button>
                </form>

                <div style="overflow:auto;">
                    <table class="audit-table">
                        <thead>
                            <tr>
                                <th>Waktu</th>
                                <th>Aksi</th>
                                <th>Actor</th>
                                <th>Target</th>
                                <th>Detail</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="5" style="text-align:center; color:var(--text-secondary); padding:2rem 1rem;">
                                        Belum ada data audit untuk filter ini.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $row): ?>
                                    <?php
                                        $detail = parseLogDetail($row['detail'] ?? null);
                                        $actorName = $row['actor_name'] ?: 'Unknown';
                                        $actorEmail = $row['actor_email'] ?: '-';
                                        $label = actionLabel((string) $row['action']);
                                        $badgeClass = actionClass((string) $row['action']);
                                    ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars((string) $row['created_at']); ?>
                                            <span class="subline mono">#<?php echo (int) $row['id']; ?></span>
                                        </td>
                                        <td>
                                            <span class="audit-badge <?php echo htmlspecialchars($badgeClass); ?>">
                                                <?php echo htmlspecialchars($label); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($actorName); ?>
                                            <span class="subline"><?php echo htmlspecialchars($actorEmail); ?></span>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars((string) ($row['target_type'] ?? '')); ?>
                                            <span class="subline mono"><?php echo htmlspecialchars((string) ($row['target_id'] ?? '')); ?></span>
                                        </td>
                                        <td>
                                            <?php if (empty($detail)): ?>
                                                <span class="subline">-</span>
                                            <?php else: ?>
                                                <?php foreach ($detail as $k => $v): ?>
                                                    <?php $detailValue = is_scalar($v) ? (string) $v : (json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[complex]'); ?>
                                                    <span class="detail-chip">
                                                        <strong><?php echo htmlspecialchars((string) $k); ?></strong>
                                                        <span><?php echo htmlspecialchars($detailValue); ?></span>
                                                    </span>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>

        <footer class="footer">
            <p>&copy; 2026 TEAM <?php echo htmlspecialchars(webCompanyName(), ENT_QUOTES, 'UTF-8'); ?></p>
        </footer>
    </div>

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
