<?php
session_start();
if (empty($_SESSION['logged_in'])) {
    header('Location: ../login.php');
    exit();
}

$sessionRoles = $_SESSION['roles'] ?? [];
$isAdmin = in_array('admin', $sessionRoles, true) || in_array('super_admin', $sessionRoles, true);
if ($isAdmin) {
    header('Location: ../admin/proxy-routes.php');
    exit();
}

$sessionUserName = $_SESSION['full_name'] ?? 'User';
$sessionUserEmail = $_SESSION['email'] ?? '';
$sessionPhoneNumber = $_SESSION['phone_number'] ?? '';

require_once __DIR__ . '/../system/seo.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php seoRenderHeadTags('Proxy Routes - ' . webCompanyName()); ?>
    <script>
        window.VPN_API = '../api/vpn-controller.php';
        window.VPN_PAGE_MODE = 'user';
        window.DAYNIGHT_SESSION = <?php echo json_encode([
            'fullName'    => $sessionUserName,
            'email'       => $sessionUserEmail,
            'phoneNumber' => $sessionPhoneNumber,
            'role'        => 'user',
            'roles'       => $sessionRoles,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        localStorage.setItem('daynight-user-name', window.DAYNIGHT_SESSION.fullName || 'User');
        localStorage.setItem('daynight-user-email', window.DAYNIGHT_SESSION.email || '');
        localStorage.setItem('daynight-user-role', 'user');
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
        .badge.ssl-status.off { background:rgba(148,163,184,.12); color:var(--text-secondary); }
        .badge.ssl-status.pending { background:rgba(245,158,11,.12); color:#d97706; }
        .badge.ssl-status.ready { background:rgba(16,185,129,.12); color:#10b981; }
        .badge.ssl-status.error { background:rgba(239,68,68,.12); color:var(--danger); }
        /* Subdomain check */
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
        .nav-item.has-dropdown{position:relative}
        .nav-dropdown-btn{display:flex;align-items:center;gap:.35rem;background:none;border:none;cursor:pointer;font-size:.875rem;font-family:inherit;color:var(--text-secondary);padding:.4rem .5rem;border-radius:8px;transition:color .15s,background .15s}
        .nav-dropdown-btn:hover,.nav-item.has-dropdown.open .nav-dropdown-btn{color:var(--text-primary);background:var(--bg-hover,rgba(0,0,0,.05))}
        .nav-dropdown-btn svg{flex-shrink:0}
        .nav-dropdown-arrow{width:12px;height:12px;transition:transform .2s}
        .nav-item.has-dropdown.open .nav-dropdown-arrow{transform:rotate(180deg)}
        .nav-dropdown-panel{display:none;position:absolute;top:calc(100% + 6px);left:0;min-width:190px;background:var(--bg-surface);border:1px solid var(--border-color);border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.12);padding:.4rem;z-index:200}
        .nav-item.has-dropdown.open .nav-dropdown-panel{display:block}
        .nav-dropdown-panel a{display:flex;align-items:center;gap:.55rem;padding:.55rem .75rem;border-radius:8px;font-size:.8125rem;color:var(--text-secondary);text-decoration:none;transition:background .12s,color .12s}
        .nav-dropdown-panel a:hover,.nav-dropdown-panel a.active{background:var(--bg-hover,rgba(0,0,0,.05));color:var(--text-primary)}
        .mobile-group-label{display:flex;align-items:center;gap:.5rem;padding:.65rem 1rem;font-size:.875rem;color:var(--text-secondary);cursor:pointer;user-select:none;border-radius:8px;transition:background .12s}
        .mobile-group-label:hover{background:var(--bg-hover,rgba(0,0,0,.05))}
        .mobile-group-arrow{margin-left:auto;width:14px;height:14px;transition:transform .2s}
        .mobile-group.open .mobile-group-arrow{transform:rotate(180deg)}
        .mobile-group-children{display:none;padding-left:1.25rem}
        .mobile-group.open .mobile-group-children{display:block}
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
        <div class="mobile-group open" id="mobile-group-produk">
            <div class="mobile-group-label" onclick="toggleMobileGroup('produk')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>Produk<svg class="mobile-group-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg></div>
            <div class="mobile-group-children">
                <a href="wa-devices.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12" y2="18"/></svg>WA Devices</a>
                <a href="vpn-users.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>VPN Management</a>
                <a href="proxy-routes.php" class="active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M2 12h3M19 12h3M12 2v3M12 19v3"/><path d="M4.93 4.93l2.12 2.12M16.95 16.95l2.12 2.12M19.07 4.93l-2.12 2.12M7.05 16.95l-2.12 2.12"/></svg>Proxy Routes</a>
            </div>
        </div>
        <a href="subscription.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>Subscription</a>
        <a href="#" onclick="openInfoModal('about');closeMobileMenu();return false;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="8"/><line x1="12" y1="12" x2="12" y2="16"/></svg>Tentang Kami</a>
        <a href="#" onclick="openInfoModal('contact');closeMobileMenu();return false;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2A19.72 19.72 0 0 1 3.1 5.18 2 2 0 0 1 5.09 3h3a2 2 0 0 1 2 1.72c.13 1 .37 1.97.72 2.9a2 2 0 0 1-.45 2.11L9.09 10.91A16 16 0 0 0 14 15.86l1.27-1.27a2 2 0 0 1 2.11-.45c.93.35 1.9.59 2.9.72A2 2 0 0 1 22 16.92z"/></svg>Kontak</a>
        <a href="#" onclick="openInfoModal('tc');closeMobileMenu();return false;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>T&amp;C</a>
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
                    <div class="nav-item has-dropdown" id="desktop-dropdown-produk">
                        <button class="nav-dropdown-btn" onclick="toggleDesktopDropdown('produk')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>Produk<svg class="nav-dropdown-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg></button>
                        <div class="nav-dropdown-panel">
                            <a href="wa-devices.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>WA Devices</a>
                            <a href="vpn-users.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>VPN Management</a>
                            <a href="proxy-routes.php" class="active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><circle cx="12" cy="12" r="3"/><path d="M2 12h3M19 12h3M12 2v3M12 19v3"/><path d="M4.93 4.93l2.12 2.12M16.95 16.95l2.12 2.12M19.07 4.93l-2.12 2.12M7.05 16.95l-2.12 2.12"/></svg>Proxy Routes</a>
                        </div>
                    </div>
                    <div class="nav-item"><a href="subscription.php" class="nav-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>Subscription</a></div>
                    <div class="nav-item"><a href="#" class="nav-link" onclick="openInfoModal('about');return false;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="8"/><line x1="12" y1="12" x2="12" y2="16"/></svg>Tentang Kami</a></div>
                    <div class="nav-item"><a href="#" class="nav-link" onclick="openInfoModal('contact');return false;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2A19.72 19.72 0 0 1 3.1 5.18 2 2 0 0 1 5.09 3h3a2 2 0 0 1 2 1.72c.13 1 .37 1.97.72 2.9a2 2 0 0 1-.45 2.11L9.09 10.91A16 16 0 0 0 14 15.86l1.27-1.27a2 2 0 0 1 2.11-.45c.93.35 1.9.59 2.9.72A2 2 0 0 1 22 16.92z"/></svg>Kontak</a></div>
                    <div class="nav-item"><a href="#" class="nav-link" onclick="openInfoModal('tc');return false;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>T&amp;C</a></div>
                </div>
            </div>
            <div class="nav-right">
                <div class="theme-toggle">
                    <button class="theme-btn theme-btn-snow active" onclick="setTheme('snow')" title="Snow Edition"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/></svg></button>
                    <button class="theme-btn theme-btn-carbon" onclick="setTheme('carbon')" title="Carbon Edition"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg></button>
                </div>
                <div class="account-menu-wrap" id="account-menu-wrap">
                    <button class="user-menu" onclick="toggleAccountDropdown()"><div class="user-avatar">U</div><span class="user-name" data-current-user>User</span></button>
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
                <h1 class="greeting">My Proxy Routes</h1>
                <p class="greeting-sub">Kelola subdomain proxy dengan SSL otomatis</p>
            </div>
            <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                <button class="btn btn-secondary" type="button" onclick="proxyLoadRoutes()">&#8635; Refresh</button>
                <button class="btn btn-primary" type="button" onclick="proxyOpenCreateModal()">+ Tambah Route</button>
            </div>
        </div>

        <div id="proxy-alert" class="admin-alert" style="display:none;"></div>

        <!-- Filter toolbar -->
        <div class="card" style="margin-bottom:1rem;padding:.75rem 1rem;">
            <div class="vpn-filter-toolbar">
                <input id="proxy-search" type="search" class="form-input" placeholder="Cari nama / subdomain / domain…" style="max-width:300px;" oninput="proxyApplyFilters()">
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
                            <th>Dibuat</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="proxy-tbody">
                        <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-secondary);">Memuat data…</td></tr>
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
<script src="../user-proxy-management.js?v=<?php echo (int) (file_exists(__DIR__ . '/../user-proxy-management.js') ? filemtime(__DIR__ . '/../user-proxy-management.js') : time()); ?>"></script>
<!-- Info Modals: Tentang Kami, Kontak, T&C -->
<div class="acct-modal-overlay" id="modal-info-about" onclick="if(event.target===this)closeInfoModal('about')">
    <div class="acct-modal" style="max-width:540px;">
        <button class="modal-close" onclick="closeInfoModal('about')" title="Tutup"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        <h3>Tentang Kami</h3>
        <div style="margin-top:1rem;font-size:0.875rem;color:var(--text-secondary);line-height:1.7;">
            <p><strong style="color:var(--text-primary);"><?php echo htmlspecialchars(webCompanyName(), ENT_QUOTES, 'UTF-8'); ?></strong> adalah platform manajemen layanan <strong>VPN</strong> dan <strong>WhatsApp API Gateway</strong> yang dirancang untuk memudahkan pengelolaan koneksi aman dan pengiriman pesan otomatis.</p>
            <ul style="margin:0.75rem 0 0.75rem 1.25rem;">
                <li>Manajemen akun VPN Remote Access</li>
                <li>WhatsApp API Gateway multi-device</li>
                <li>Proxy Route Management</li>
                <li>Sistem langganan berbasis paket</li>
            </ul>
            <p>Platform kami dibangun dengan mengutamakan keamanan, kemudahan penggunaan, dan skalabilitas untuk kebutuhan individu maupun bisnis.</p>
        </div>
        <div class="modal-actions">
            <button class="btn btn-primary" onclick="closeInfoModal('about')">Tutup</button>
        </div>
    </div>
</div>
<div class="acct-modal-overlay" id="modal-info-contact" onclick="if(event.target===this)closeInfoModal('contact')">
    <div class="acct-modal" style="max-width:480px;">
        <button class="modal-close" onclick="closeInfoModal('contact')" title="Tutup"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        <h3>Kontak &amp; Support</h3>
        <div style="margin-top:1rem;font-size:0.875rem;color:var(--text-secondary);line-height:1.9;">
            <div style="display:flex;align-items:flex-start;gap:0.6rem;margin-bottom:0.5rem;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="flex-shrink:0;margin-top:2px;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                <span><strong style="color:var(--text-primary);">Email:</strong> Klicknet55@gmail.com</span>
            </div>
            <div style="display:flex;align-items:flex-start;gap:0.6rem;margin-bottom:0.5rem;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="flex-shrink:0;margin-top:2px;"><path d="M22 16.92v3a2 2 0 0 1-2.18 2A19.72 19.72 0 0 1 3.1 5.18 2 2 0 0 1 5.09 3h3a2 2 0 0 1 2 1.72c.13 1 .37 1.97.72 2.9a2 2 0 0 1-.45 2.11L9.09 10.91A16 16 0 0 0 14 15.86l1.27-1.27a2 2 0 0 1 2.11-.45c.93.35 1.9.59 2.9.72A2 2 0 0 1 22 16.92z"/></svg>
                <span><strong style="color:var(--text-primary);">WhatsApp / Telepon:</strong> +62 815-5407-7474</span>
            </div>
            <div style="display:flex;align-items:flex-start;gap:0.6rem;margin-bottom:0.5rem;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="flex-shrink:0;margin-top:2px;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                <span><strong style="color:var(--text-primary);">Alamat:</strong> Jl. semar RT.09 RW.02 Plosorejo KC. Gampengrejo</span>
            </div>
            <div style="display:flex;align-items:flex-start;gap:0.6rem;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="flex-shrink:0;margin-top:2px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <span><strong style="color:var(--text-primary);">Jam Operasional:</strong> Senin – Sabtu, 08.00 – 20.00 WIB</span>
            </div>
        </div>
        <div class="modal-actions">
            <button class="btn btn-primary" onclick="closeInfoModal('contact')">Tutup</button>
        </div>
    </div>
</div>
<div class="acct-modal-overlay" id="modal-info-tc" onclick="if(event.target===this)closeInfoModal('tc')">
    <div class="acct-modal" style="max-width:580px;">
        <button class="modal-close" onclick="closeInfoModal('tc')" title="Tutup"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        <h3>Syarat &amp; Ketentuan</h3>
        <div style="margin-top:1rem;max-height:55vh;overflow-y:auto;font-size:0.8125rem;color:var(--text-secondary);line-height:1.75;padding-right:4px;">
            <p><strong style="color:var(--text-primary);">1. Penerimaan Syarat</strong><br>Dengan menggunakan layanan <?php echo htmlspecialchars(webCompanyName(), ENT_QUOTES, 'UTF-8'); ?>, Anda menyetujui syarat dan ketentuan yang berlaku. Jika tidak setuju, harap hentikan penggunaan layanan.</p>
            <p><strong style="color:var(--text-primary);">2. Penggunaan Layanan</strong><br>Layanan ini hanya boleh digunakan untuk tujuan yang sah. Pengguna dilarang menyalahgunakan platform untuk aktivitas ilegal, spam, phishing, atau tindakan yang merugikan pihak lain.</p>
            <p><strong style="color:var(--text-primary);">3. Akun Pengguna</strong><br>Pengguna bertanggung jawab penuh atas kerahasiaan kredensial akun. Segala aktivitas yang terjadi di bawah akun Anda menjadi tanggung jawab Anda sepenuhnya.</p>
            <p><strong style="color:var(--text-primary);">4. Langganan &amp; Pembayaran</strong><br>Layanan berbayar diaktifkan setelah pembayaran berhasil dikonfirmasi. Masa aktif langganan dihitung sejak tanggal aktivasi. Tidak ada pengembalian dana untuk masa berlangganan yang telah berjalan.</p>
            <p><strong style="color:var(--text-primary);">5. Privasi Data</strong><br>Data pengguna dijaga kerahasiaannya dan tidak dijual kepada pihak ketiga. Kami menggunakan data hanya untuk keperluan operasional layanan.</p>
            <p><strong style="color:var(--text-primary);">6. Pembatasan Layanan</strong><br>Kami berhak menangguhkan atau menghentikan akun yang melanggar syarat dan ketentuan tanpa pemberitahuan sebelumnya.</p>
            <p><strong style="color:var(--text-primary);">7. Perubahan Ketentuan</strong><br>Syarat dan ketentuan ini dapat berubah sewaktu-waktu. Perubahan akan diinformasikan melalui platform. Penggunaan layanan setelah perubahan dianggap sebagai persetujuan atas ketentuan baru.</p>
            <p style="margin-top:1rem;font-size:0.75rem;">Terakhir diperbarui: Mei 2026</p>
        </div>
        <div class="modal-actions">
            <button class="btn btn-primary" onclick="closeInfoModal('tc')">Saya Mengerti</button>
        </div>
    </div>
</div>
<script>
function openInfoModal(key) {
    var ids = { about: 'modal-info-about', contact: 'modal-info-contact', tc: 'modal-info-tc' };
    var el = document.getElementById(ids[key]);
    if (el) { el.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
}
function closeInfoModal(key) {
    var ids = { about: 'modal-info-about', contact: 'modal-info-contact', tc: 'modal-info-tc' };
    var el = document.getElementById(ids[key]);
    if (el) { el.style.display = 'none'; document.body.style.overflow = ''; }
}
</script>
<!-- Account Modals -->
<div class="acct-modal-overlay" id="modal-info-about" onclick="if(event.target===this)closeInfoModal('about')">
    <div class="acct-modal" style="max-width:540px;">
        <button class="modal-close" onclick="closeInfoModal('about')" title="Tutup"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        <h3>Tentang Kami</h3>
        <div style="margin-top:1rem;font-size:0.875rem;color:var(--text-secondary);line-height:1.7;">
            <p><strong style="color:var(--text-primary);"><?php echo htmlspecialchars(webCompanyName(), ENT_QUOTES, 'UTF-8'); ?></strong> adalah platform manajemen layanan <strong>VPN</strong> dan <strong>WhatsApp API Gateway</strong> yang dirancang untuk memudahkan pengelolaan koneksi aman dan pengiriman pesan otomatis.</p>
            <ul style="margin:0.75rem 0 0.75rem 1.25rem;">
                <li>Manajemen akun VPN Remote Access</li>
                <li>WhatsApp API Gateway multi-device</li>
                <li>Proxy Route Management</li>
                <li>Sistem langganan berbasis paket</li>
            </ul>
            <p>Platform kami dibangun dengan mengutamakan keamanan, kemudahan penggunaan, dan skalabilitas untuk kebutuhan individu maupun bisnis.</p>
        </div>
        <div class="modal-actions">
            <button class="btn btn-primary" onclick="closeInfoModal('about')">Tutup</button>
        </div>
    </div>
</div>
<div class="acct-modal-overlay" id="modal-info-contact" onclick="if(event.target===this)closeInfoModal('contact')">
    <div class="acct-modal" style="max-width:480px;">
        <button class="modal-close" onclick="closeInfoModal('contact')" title="Tutup"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        <h3>Kontak &amp; Support</h3>
        <div style="margin-top:1rem;font-size:0.875rem;color:var(--text-secondary);line-height:1.9;">
            <div style="display:flex;align-items:flex-start;gap:0.6rem;margin-bottom:0.5rem;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="flex-shrink:0;margin-top:2px;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                <span><strong style="color:var(--text-primary);">Email:</strong> Klicknet55@gmail.com</span>
            </div>
            <div style="display:flex;align-items:flex-start;gap:0.6rem;margin-bottom:0.5rem;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="flex-shrink:0;margin-top:2px;"><path d="M22 16.92v3a2 2 0 0 1-2.18 2A19.72 19.72 0 0 1 3.1 5.18 2 2 0 0 1 5.09 3h3a2 2 0 0 1 2 1.72c.13 1 .37 1.97.72 2.9a2 2 0 0 1-.45 2.11L9.09 10.91A16 16 0 0 0 14 15.86l1.27-1.27a2 2 0 0 1 2.11-.45c.93.35 1.9.59 2.9.72A2 2 0 0 1 22 16.92z"/></svg>
                <span><strong style="color:var(--text-primary);">WhatsApp / Telepon:</strong> +62 815-5407-7474</span>
            </div>
            <div style="display:flex;align-items:flex-start;gap:0.6rem;margin-bottom:0.5rem;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="flex-shrink:0;margin-top:2px;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                <span><strong style="color:var(--text-primary);">Alamat:</strong> Jl. semar RT.09 RW.02 Plosorejo KC. Gampengrejo</span>
            </div>
            <div style="display:flex;align-items:flex-start;gap:0.6rem;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="flex-shrink:0;margin-top:2px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <span><strong style="color:var(--text-primary);">Jam Operasional:</strong> Senin – Sabtu, 08.00 – 20.00 WIB</span>
            </div>
        </div>
        <div class="modal-actions">
            <button class="btn btn-primary" onclick="closeInfoModal('contact')">Tutup</button>
        </div>
    </div>
</div>
<div class="acct-modal-overlay" id="modal-info-tc" onclick="if(event.target===this)closeInfoModal('tc')">
    <div class="acct-modal" style="max-width:580px;">
        <button class="modal-close" onclick="closeInfoModal('tc')" title="Tutup"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        <h3>Syarat &amp; Ketentuan</h3>
        <div style="margin-top:1rem;max-height:55vh;overflow-y:auto;font-size:0.8125rem;color:var(--text-secondary);line-height:1.75;padding-right:4px;">
            <p><strong style="color:var(--text-primary);">1. Penerimaan Syarat</strong><br>Dengan menggunakan layanan <?php echo htmlspecialchars(webCompanyName(), ENT_QUOTES, 'UTF-8'); ?>, Anda menyetujui syarat dan ketentuan yang berlaku. Jika tidak setuju, harap hentikan penggunaan layanan.</p>
            <p><strong style="color:var(--text-primary);">2. Penggunaan Layanan</strong><br>Layanan ini hanya boleh digunakan untuk tujuan yang sah. Pengguna dilarang menyalahgunakan platform untuk aktivitas ilegal, spam, phishing, atau tindakan yang merugikan pihak lain.</p>
            <p><strong style="color:var(--text-primary);">3. Akun Pengguna</strong><br>Pengguna bertanggung jawab penuh atas kerahasiaan kredensial akun. Segala aktivitas yang terjadi di bawah akun Anda menjadi tanggung jawab Anda sepenuhnya.</p>
            <p><strong style="color:var(--text-primary);">4. Langganan &amp; Pembayaran</strong><br>Layanan berbayar diaktifkan setelah pembayaran berhasil dikonfirmasi. Masa aktif langganan dihitung sejak tanggal aktivasi. Tidak ada pengembalian dana untuk masa berlangganan yang telah berjalan.</p>
            <p><strong style="color:var(--text-primary);">5. Privasi Data</strong><br>Data pengguna dijaga kerahasiaannya dan tidak dijual kepada pihak ketiga. Kami menggunakan data hanya untuk keperluan operasional layanan.</p>
            <p><strong style="color:var(--text-primary);">6. Pembatasan Layanan</strong><br>Kami berhak menangguhkan atau menghentikan akun yang melanggar syarat dan ketentuan tanpa pemberitahuan sebelumnya.</p>
            <p><strong style="color:var(--text-primary);">7. Perubahan Ketentuan</strong><br>Syarat dan ketentuan ini dapat berubah sewaktu-waktu. Perubahan akan diinformasikan melalui platform. Penggunaan layanan setelah perubahan dianggap sebagai persetujuan atas ketentuan baru.</p>
            <p style="margin-top:1rem;font-size:0.75rem;">Terakhir diperbarui: Mei 2026</p>
        </div>
        <div class="modal-actions">
            <button class="btn btn-primary" onclick="closeInfoModal('tc')">Saya Mengerti</button>
        </div>
    </div>
</div>
<script>
function toggleDesktopDropdown(key){var el=document.getElementById('desktop-dropdown-'+key);if(!el)return;var o=el.classList.contains('open');document.querySelectorAll('.nav-item.has-dropdown.open').forEach(function(d){d.classList.remove('open');});if(!o)el.classList.add('open');}
document.addEventListener('click',function(e){if(!e.target.closest('.nav-item.has-dropdown')){document.querySelectorAll('.nav-item.has-dropdown.open').forEach(function(d){d.classList.remove('open');});}});
function toggleMobileGroup(key){var el=document.getElementById('mobile-group-'+key);if(el)el.classList.toggle('open');}
function openInfoModal(key){var ids={about:'modal-info-about',contact:'modal-info-contact',tc:'modal-info-tc'};var el=document.getElementById(ids[key]);if(el){el.style.display='flex';document.body.style.overflow='hidden';}}
function closeInfoModal(key){var ids={about:'modal-info-about',contact:'modal-info-contact',tc:'modal-info-tc'};var el=document.getElementById(ids[key]);if(el){el.style.display='none';document.body.style.overflow='';}}
</script>
</body>
</html>
