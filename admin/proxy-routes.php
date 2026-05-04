<?php
session_start();
if (empty($_SESSION['logged_in'])) {
    header('Location: ../login.php');
    exit();
}

$sessionRoles = $_SESSION['roles'] ?? [];
$isAdmin = in_array('admin', $sessionRoles, true) || in_array('super_admin', $sessionRoles, true);
if (!$isAdmin) {
    header('Location: ../user/vpn-users.php');
    exit();
}

$sessionUserName = $_SESSION['full_name'] ?? 'Admin';
$sessionUserEmail = $_SESSION['email'] ?? '';
$sessionPhoneNumber = $_SESSION['phone_number'] ?? '';

require_once __DIR__ . '/../system/seo.php';

$vpnServerBaseUrl = '';
try {
    require_once __DIR__ . '/../api/db.php';
    $db = getDB();
    $vpnApiRow = $db->query(
        "SELECT base_url FROM api_configurations WHERE service_type = 'vpn' AND is_enabled = 1 ORDER BY updated_at DESC, id DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if ($vpnApiRow && !empty($vpnApiRow['base_url'])) {
        $vpnServerBaseUrl = rtrim(trim($vpnApiRow['base_url']), '/');
    }
} catch (Throwable $e) {
    // Use defaults if DB unavailable
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php seoRenderHeadTags('Proxy Routes - ' . webCompanyName()); ?>
    <script>
        window.VPN_API = '../api/vpn-controller.php';
        window.VPN_PAGE_MODE = 'admin';
        window.VPN_SERVER_URL = <?php echo json_encode($vpnServerBaseUrl, JSON_UNESCAPED_SLASHES); ?>;
        window.DAYNIGHT_SESSION = <?php echo json_encode([
            'fullName' => $sessionUserName,
            'email' => $sessionUserEmail,
            'phoneNumber' => $sessionPhoneNumber,
            'role' => 'admin',
            'roles' => $sessionRoles,
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
        .form-label { display:block; font-size:.8125rem; margin-bottom:.35rem; color:var(--text-secondary); }
        .form-input,.form-select { width:100%; border:1px solid var(--border-color); border-radius:10px; background:var(--bg-surface); color:var(--text-primary); padding:.65rem .75rem; font-size:.875rem; }
        .admin-alert { padding:.625rem .75rem; border-radius:10px; font-size:.8125rem; white-space:pre-wrap; margin-bottom:1rem; }
        .admin-alert.error { background:rgba(239,68,68,.08); color:var(--danger); border:1px solid rgba(239,68,68,.28); }
        .admin-alert.success { background:rgba(34,197,94,.08); color:var(--success); border:1px solid rgba(34,197,94,.28); }
        /* Modal */
        .vpn-modal-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:1000; align-items:center; justify-content:center; }
        .vpn-modal-backdrop.open { display:flex; }
        .vpn-modal { background:var(--bg-primary); color:var(--text-primary); border:1px solid var(--border-color); border-radius:16px; padding:1.5rem; width:100%; max-width:520px; margin:1rem; box-shadow:0 8px 32px rgba(0,0,0,.25); }
        .vpn-modal-title { font-size:1.05rem; font-weight:600; margin-bottom:1.1rem; color:var(--text-primary); }
        .vpn-modal-grid { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; }
        .vpn-modal-grid .full { grid-column:1 / -1; }
        .vpn-modal-footer { display:flex; justify-content:flex-end; gap:.5rem; margin-top:1.1rem; }
        .vpn-modal-confirm-text { font-size:.9rem; margin-bottom:1rem; line-height:1.5; }
        /* Table */
        .btn-action { padding:.22rem .55rem; font-size:.72rem; border-radius:7px; cursor:pointer; border:1px solid var(--border-color); background:var(--bg-surface); color:var(--text-primary); transition:opacity .15s; }
        .btn-action:hover { opacity:.75; }
        .btn-action.info { border-color:rgba(59,130,246,.4); color:#3b82f6; }
        .btn-action.success { border-color:rgba(16,185,129,.4); color:#10b981; }
        .btn-action.danger { border-color:rgba(239,68,68,.4); color:var(--danger); }
        .table-actions { display:flex; gap:.35rem; flex-wrap:wrap; }
        .badge { display:inline-block; padding:.1rem .45rem; border-radius:6px; font-size:.72rem; font-weight:600; }
        .badge.active { background:rgba(34,197,94,.12); color:var(--success); }
        .badge.ssl { background:rgba(99,102,241,.12); color:#6366f1; }
        .badge.ssl-status.off { background:rgba(148,163,184,.12); color:var(--text-secondary); }
        .badge.ssl-status.pending { background:rgba(245,158,11,.12); color:#d97706; }
        .badge.ssl-status.ready { background:rgba(16,185,129,.12); color:#10b981; }
        .badge.ssl-status.error { background:rgba(239,68,68,.12); color:var(--danger); }
        /* Check indicator */
        .check-result { margin-top:.5rem; font-size:.8rem; padding:.4rem .65rem; border-radius:8px; }
        .check-result.avail { background:rgba(34,197,94,.08); color:var(--success); border:1px solid rgba(34,197,94,.28); }
        .check-result.taken { background:rgba(239,68,68,.08); color:var(--danger); border:1px solid rgba(239,68,68,.28); }
        .check-result.checking { color:var(--text-secondary); }
        .create-progress { margin-top:.65rem; font-size:.78rem; color:var(--text-secondary); display:none; }
        .create-progress.active { display:block; }
        .create-progress .dot { display:inline-block; min-width:1rem; }
        .vpn-filter-toolbar { display:flex; align-items:center; gap:.55rem; flex-wrap:nowrap; overflow-x:auto; }
        @media (max-width:768px) {
            .vpn-modal-grid { grid-template-columns:1fr; }
            .vpn-filter-toolbar { display:grid; grid-template-columns:1fr; }
        }
    </style>
</head>
<body>
<?php seoRenderBodyOpenTags(); ?>
<div class="mobile-menu-overlay"></div>

<div class="mobile-menu">
    <div class="mobile-menu-header">
        <a href="index.php" class="logo">
            <div class="logo-icon"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg></div>
            <?php echo htmlspecialchars(webCompanyName(), ENT_QUOTES, 'UTF-8'); ?>
        </a>
        <button class="mobile-menu-close" onclick="closeMobileMenu()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <nav class="mobile-menu-nav">
        <a href="index.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>Dashboard</a>
        <a href="wa-devices.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12" y2="18"/></svg>WA Devices</a>
        <a href="vpn-users.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>VPN Management</a>
        <a href="proxy-routes.php" class="active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M2 12h3M19 12h3M12 2v3M12 19v3"/><path d="M4.93 4.93l2.12 2.12M16.95 16.95l2.12 2.12M19.07 4.93l-2.12 2.12M7.05 16.95l-2.12 2.12"/></svg>Proxy Routes</a>
        <a href="config.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>Config</a>
        <a href="audit-log.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3h18v18H3z"/><path d="M7 7h10"/><path d="M7 12h10"/><path d="M7 17h6"/></svg>Audit Log</a>
    </nav>
    <div class="mobile-menu-footer"><a href="../api/logout.php" class="mobile-logout-btn">Logout</a>
        <div class="theme-toggle">
            <button class="theme-btn theme-btn-snow active" onclick="setTheme('snow')" title="Snow Edition"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/></svg></button>
            <button class="theme-btn theme-btn-carbon" onclick="setTheme('carbon')" title="Carbon Edition"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg></button>
        </div>
    </div>
</div>

<div class="app-container">
    <nav class="top-nav">
        <div class="nav-container">
            <div class="nav-left">
                <a href="index.php" class="logo"><div class="logo-icon"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg></div><?php echo htmlspecialchars(webCompanyName(), ENT_QUOTES, 'UTF-8'); ?></a>
                <div class="nav-menu">
                    <div class="nav-item"><a href="index.php" class="nav-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>Dashboard</a></div>
                    <div class="nav-item"><a href="wa-devices.php" class="nav-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>WA Devices</a></div>
                    <div class="nav-item"><a href="vpn-users.php" class="nav-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>VPN Management</a></div>
                    <div class="nav-item"><a href="proxy-routes.php" class="nav-link active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M2 12h3M19 12h3M12 2v3M12 19v3"/><path d="M4.93 4.93l2.12 2.12M16.95 16.95l2.12 2.12M19.07 4.93l-2.12 2.12M7.05 16.95l-2.12 2.12"/></svg>Proxy Routes</a></div>
                    <div class="nav-item"><a href="config.php" class="nav-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>Config</a></div>
                    <div class="nav-item"><a href="audit-log.php" class="nav-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3h18v18H3z"/><path d="M7 7h10"/><path d="M7 12h10"/><path d="M7 17h6"/></svg>Audit Log</a></div>
                </div>
            </div>
            <div class="nav-right">
                <div class="theme-toggle">
                    <button class="theme-btn theme-btn-snow active" onclick="setTheme('snow')" title="Snow Edition"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/></svg></button>
                    <button class="theme-btn theme-btn-carbon" onclick="setTheme('carbon')" title="Carbon Edition"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg></button>
                </div>
                <div class="account-menu-wrap" id="account-menu-wrap">
                    <button class="user-menu" onclick="toggleAccountDropdown()"><div class="user-avatar">A</div><span class="user-name" data-current-user>Admin</span></button>
                    <div class="account-dropdown">
                        <button onclick="openAccountModal('edit-profile')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Edit Profil</button>
                        <button onclick="openAccountModal('change-password')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Ganti Password</button>
                    </div>
                </div>
                <a href="../api/logout.php" class="btn-logout" title="Logout">Logout</a>
                <button class="mobile-menu-btn" onclick="toggleMobileMenu()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
            </div>
        </div>
    </nav>

    <main class="main-content">
        <div class="page-header" style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start;">
            <div>
                <h1 class="greeting">Proxy Routes</h1>
                <p class="subtitle">Kelola subdomain proxy dengan SSL otomatis</p>
            </div>
            <button class="btn btn-primary" onclick="proxyOpenCreateModal()" style="flex-shrink:0;">+ Tambah Route</button>
        </div>

        <div id="proxy-alert" class="admin-alert" style="display:none;"></div>

        <!-- Filter toolbar -->
        <div class="card" style="margin-bottom:1rem;padding:.75rem 1rem;">
            <div class="vpn-filter-toolbar">
                <input id="proxy-search" type="search" class="form-input" placeholder="Cari nama / subdomain / domain…" style="max-width:280px;" oninput="proxyApplyFilters()">
                <select id="proxy-owner-filter" class="form-select" style="max-width:180px;" onchange="proxyApplyFilters()">
                    <option value="all">Semua Owner</option>
                </select>
                <button class="btn btn-secondary" onclick="proxyLoadRoutes()" style="flex-shrink:0;">&#8635; Refresh</button>
            </div>
        </div>

        <!-- Table -->
        <div class="card">
            <div class="table-container">
                <table class="data-table" id="proxy-table">
                    <thead>
                        <tr>
                            <th>Nama</th>
                            <th>Subdomain / Domain</th>
                            <th>Port Forward</th>
                            <th>Upstream</th>
                            <th>SSL</th>
                            <th>Owner</th>
                            <th>Dibuat</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="proxy-tbody">
                        <tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--text-secondary);">Memuat data…</td></tr>
                    </tbody>
                </table>
            </div>
            <div id="proxy-count" style="font-size:.78rem;color:var(--text-secondary);padding:.5rem .25rem;"></div>
        </div>
    </main>
</div>

<!-- ===================== Modal: Create Proxy Route ===================== -->
<div id="modal-proxy-create" class="vpn-modal-backdrop" onclick="proxyBackdropClose(event,'modal-proxy-create')">
    <div class="vpn-modal">
        <div class="vpn-modal-title">Tambah Proxy Route</div>
        <div id="modal-proxy-create-alert" class="admin-alert" style="display:none;"></div>
        <div class="vpn-modal-grid">
            <div class="full">
                <label class="form-label">Nama Route <span style="color:var(--danger)">*</span></label>
                <input id="proxy-create-name" class="form-input" type="text" placeholder="contoh: customer-1" autocomplete="off">
            </div>
            <div class="full">
                <label class="form-label">Subdomain <span style="color:var(--danger)">*</span></label>
                <div style="display:flex;gap:.5rem;align-items:flex-start;flex-direction:column;">
                    <div style="display:flex;gap:.5rem;width:100%;align-items:center;">
                        <input id="proxy-create-subdomain" class="form-input" type="text" placeholder="contoh: customer1" autocomplete="off" oninput="proxyOnSubdomainInput()" style="flex:1;">
                        <button type="button" class="btn btn-secondary" onclick="proxyCheckSubdomain()" style="flex-shrink:0;white-space:nowrap;">Cek</button>
                    </div>
                    <div id="proxy-check-result" style="display:none;" class="check-result"></div>
                </div>
            </div>
            <div class="full">
                <label class="form-label">Port Forward Name <span style="color:var(--danger)">*</span></label>
                <select id="proxy-create-pf" class="form-select">
                    <option value="">— pilih port forwarding —</option>
                </select>
                <div style="font-size:.75rem;color:var(--text-secondary);margin-top:.3rem;">Port forwarding yang akan diteruskan sebagai upstream proxy.</div>
            </div>
        </div>
        <div id="proxy-create-progress" class="create-progress"></div>
        <div class="vpn-modal-footer">
            <button class="btn btn-secondary" onclick="vpnCloseModal('modal-proxy-create')">Batal</button>
            <button class="btn btn-primary" id="proxy-create-btn" onclick="proxyCreateRoute()">Buat Route</button>
        </div>
    </div>
</div>

<!-- ===================== Modal: Confirm Delete ===================== -->
<div id="modal-proxy-delete" class="vpn-modal-backdrop" onclick="proxyBackdropClose(event,'modal-proxy-delete')">
    <div class="vpn-modal" style="max-width:400px;">
        <div class="vpn-modal-title">Hapus Proxy Route</div>
        <div id="modal-proxy-delete-alert" class="admin-alert" style="display:none;"></div>
        <p class="vpn-modal-confirm-text" id="proxy-delete-confirm-text">Apakah Anda yakin ingin menghapus proxy route ini? SSL certificate dan DNS record akan ikut dihapus.</p>
        <div class="vpn-modal-footer">
            <button class="btn btn-secondary" onclick="vpnCloseModal('modal-proxy-delete')">Batal</button>
            <button class="btn btn-danger" id="proxy-delete-btn" onclick="proxyConfirmDelete()">Hapus</button>
        </div>
    </div>
</div>

<script src="../templatemo-daynight-script.js?v=<?php echo (int) (file_exists(__DIR__ . '/../templatemo-daynight-script.js') ? filemtime(__DIR__ . '/../templatemo-daynight-script.js') : time()); ?>"></script>
<script src="../proxy-management.js?v=<?php echo (int) (file_exists(__DIR__ . '/../proxy-management.js') ? filemtime(__DIR__ . '/../proxy-management.js') : time()); ?>"></script>
</body>
</html>
