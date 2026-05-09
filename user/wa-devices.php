<?php
session_start();
if (empty($_SESSION['logged_in'])) {
    header('Location: ../login.php');
    exit();
}

$sessionUserName = $_SESSION['full_name'] ?? 'User';
$sessionUserEmail = $_SESSION['email'] ?? '';
$sessionPhoneNumber = $_SESSION['phone_number'] ?? '';
$sessionRoles = $_SESSION['roles'] ?? [];
$sessionRole = (in_array('admin', $sessionRoles, true) || in_array('super_admin', $sessionRoles, true)) ? 'admin' : 'user';
if ($sessionRole === 'admin') {
    header('Location: ../admin/wa-devices.php');
    exit();
}
require_once __DIR__ . '/../system/seo.php';
$dayNightScriptVer = file_exists(__DIR__ . '/../templatemo-daynight-script.js') ? filemtime(__DIR__ . '/../templatemo-daynight-script.js') : time();
$gowaScriptVer = file_exists(__DIR__ . '/../gowa-devices.js') ? filemtime(__DIR__ . '/../gowa-devices.js') : time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php seoRenderHeadTags('WA Devices - VPN & WhatsApp API Manager'); ?>
    <script>
        window.GOWA_API = '../api/wa-account.php';
        window.GOWA_PAGE_MODE = 'user';
        window.DAYNIGHT_SESSION = <?php echo json_encode([
            'userId'      => (int) ($_SESSION['user_id'] ?? 0),
            'fullName'    => $sessionUserName,
            'email'       => $sessionUserEmail,
            'phoneNumber' => $sessionPhoneNumber,
            'role'        => $sessionRole,
            'roles'       => $sessionRoles,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        localStorage.setItem('daynight-user-name', window.DAYNIGHT_SESSION.fullName || 'User');
        localStorage.setItem('daynight-user-email', window.DAYNIGHT_SESSION.email || '');
        localStorage.setItem('daynight-user-role', window.DAYNIGHT_SESSION.role || 'user');
        localStorage.setItem('daynight-user-roles', JSON.stringify(window.DAYNIGHT_SESSION.roles || []));
        if(localStorage.getItem("daynight-theme")==="carbon"){document.documentElement.classList.add("carbon");}
    </script>
    <link rel="stylesheet" href="../templatemo-daynight-style.css?v=<?php echo (int) (file_exists(__DIR__ . '/../templatemo-daynight-style.css') ? filemtime(__DIR__ . '/../templatemo-daynight-style.css') : time()); ?>">
    <style>
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
        /* Hide logout and theme toggle in nav bar on mobile, show only on desktop */
        @media (max-width: 992px) {
            .desktop-only {
                display: none !important;
            }
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
            <div class="mobile-group open" id="mobile-group-produk">
                <div class="mobile-group-label" onclick="toggleMobileGroup('produk')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>Produk<svg class="mobile-group-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg></div>
                <div class="mobile-group-children">
                    <a href="wa-devices.php" class="active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>WA Devices</a>
                    <a href="vpn-users.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>VPN Management</a>
                    <a href="proxy-routes.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M2 12h3M19 12h3M12 2v3M12 19v3"/><path d="M4.93 4.93l2.12 2.12M16.95 16.95l2.12 2.12M19.07 4.93l-2.12 2.12M7.05 16.95l-2.12 2.12"/></svg>Proxy Routes</a>
                </div>
            </div>
            <a href="subscription.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>Subscription</a>
            <a href="#" onclick="openInfoModal('about');closeMobileMenu();return false;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="8"/><line x1="12" y1="12" x2="12" y2="16"/></svg>Tentang Kami</a>
            <a href="#" onclick="openInfoModal('contact');closeMobileMenu();return false;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2A19.72 19.72 0 0 1 3.1 5.18 2 2 0 0 1 5.09 3h3a2 2 0 0 1 2 1.72c.13 1 .37 1.97.72 2.9a2 2 0 0 1-.45 2.11L9.09 10.91A16 16 0 0 0 14 15.86l1.27-1.27a2 2 0 0 1 2.11-.45c.93.35 1.9.59 2.9.72A2 2 0 0 1 22 16.92z"/></svg>Kontak</a>
            <a href="#" onclick="openInfoModal('tc');closeMobileMenu();return false;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>T&amp;C</a>
        </nav>
        <div class="mobile-menu-footer"><a href="../api/logout.php" class="mobile-logout-btn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>Logout</a>
            <div class="theme-toggle">
                <button class="theme-btn theme-btn-snow active" onclick="setTheme('snow')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/></svg></button>
                <button class="theme-btn theme-btn-carbon" onclick="setTheme('carbon')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg></button>
            </div>
        </div>
    </div>

    <div class="app-container">
        <!-- Top Nav -->
        <nav class="top-nav">
            <div class="nav-container">
                <div class="nav-left">
                    <a href="index.php" class="logo"><div class="logo-icon"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg></div><?php echo htmlspecialchars(webCompanyName(), ENT_QUOTES, 'UTF-8'); ?></a>
                    <div class="nav-menu">
                        <div class="nav-item"><a href="index.php" class="nav-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>Dashboard</a></div>
                        <div class="nav-item has-dropdown" id="desktop-dropdown-produk">
                            <button class="nav-dropdown-btn" onclick="toggleDesktopDropdown('produk')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>Produk<svg class="nav-dropdown-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg></button>
                            <div class="nav-dropdown-panel">
                                <a href="wa-devices.php" class="active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>WA Devices</a>
                                <a href="vpn-users.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>VPN Management</a>
                                <a href="proxy-routes.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><circle cx="12" cy="12" r="3"/><path d="M2 12h3M19 12h3M12 2v3M12 19v3"/><path d="M4.93 4.93l2.12 2.12M16.95 16.95l2.12 2.12M19.07 4.93l-2.12 2.12M7.05 16.95l-2.12 2.12"/></svg>Proxy Routes</a>
                            </div>
                        </div>
                        <div class="nav-item"><a href="subscription.php" class="nav-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>Subscription</a></div>
                        <div class="nav-item"><a href="#" class="nav-link" onclick="openInfoModal('about');return false;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="8"/><line x1="12" y1="12" x2="12" y2="16"/></svg>Tentang Kami</a></div>
                        <div class="nav-item"><a href="#" class="nav-link" onclick="openInfoModal('contact');return false;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2A19.72 19.72 0 0 1 3.1 5.18 2 2 0 0 1 5.09 3h3a2 2 0 0 1 2 1.72c.13 1 .37 1.97.72 2.9a2 2 0 0 1-.45 2.11L9.09 10.91A16 16 0 0 0 14 15.86l1.27-1.27a2 2 0 0 1 2.11-.45c.93.35 1.9.59 2.9.72A2 2 0 0 1 22 16.92z"/></svg>Kontak</a></div>
                        <div class="nav-item"><a href="#" class="nav-link" onclick="openInfoModal('tc');return false;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>T&amp;C</a></div>
                    </div>
                </div>
                <div class="nav-right">
                    <div class="theme-toggle desktop-only">
                        <button class="theme-btn theme-btn-snow active" onclick="setTheme('snow')" title="Snow Edition"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/></svg></button>
                        <button class="theme-btn theme-btn-carbon" onclick="setTheme('carbon')" title="Carbon Edition"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg></button>
                    </div>
                    <div class="account-menu-wrap" id="account-menu-wrap">
                        <button class="user-menu" onclick="toggleAccountDropdown()"><div class="user-avatar">U</div><span class="user-name" data-current-user>User</span></button>
                        <div class="account-dropdown">
                            <button onclick="openAccountModal('edit-profile')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Edit Profil</button>
                            <button onclick="openAccountModal('change-password')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Ganti Password</button>
                        </div>
                    </div>
                    <a href="../api/logout.php" class="btn-logout desktop-only" title="Logout">Logout</a>
                    <button class="mobile-menu-btn" onclick="toggleMobileMenu()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
                </div>
            </div>
        </nav>

        <main class="main-content">
            <!-- Page Header -->
            <div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
                <div>
                    <h1 class="greeting">WA Device Manager</h1>
                    <p class="greeting-sub">Buat dan kelola device WhatsApp Gateway API</p>
                </div>
                <div style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
                    <button class="btn btn-secondary" onclick="gowaLoadDevices()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.5"/></svg>
                        Refresh
                    </button>
                    <button class="btn btn-primary" onclick="gowaOpenCreateModal()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Buat Device Baru
                    </button>
                </div>
            </div>

            <!-- GoWA Server Info Bar -->
            <div class="card" style="margin-bottom:1.5rem;padding:1rem 1.5rem;">
                <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="color:var(--accent);flex-shrink:0;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    <div style="flex:1;">
                        <p style="font-size:0.875rem;font-weight:600;color:var(--text-primary);margin-bottom:0.125rem;">Server Gateway Terhubung</p>
                        
                    </div>
                    <div style="text-align:right;">
                        <p style="font-size:0.75rem;color:var(--text-secondary);margin-bottom:0.25rem;">URL Send API</p>
                        <code style="font-size:0.75rem;background:var(--bg-surface);padding:0.25rem 0.5rem;border-radius:6px;color:var(--accent);">wa-send.php?phone=[number]&amp;message=[text]&amp;secret=[secret]</code>
                    </div>
                </div>
            </div>

            <!-- Stats Bar -->
            <div class="stats-grid" style="margin-bottom:1.5rem;">
                <div class="stat-card">
                    <div class="stat-label">Total Device</div>
                    <div class="stat-value" id="stat-total">0</div>
                    <div class="stat-change">Device terdaftar</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Terhubung</div>
                    <div class="stat-value" id="stat-connected" style="color:var(--success);">0</div>
                    <div class="stat-change positive">Aktif & siap kirim</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Terputus</div>
                    <div class="stat-value" id="stat-disconnected" style="color:var(--danger);">0</div>
                    <div class="stat-change negative">Perlu scan QR ulang</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Menunggu Scan</div>
                    <div class="stat-value" id="stat-pending" style="color:var(--warning);">0</div>
                    <div class="stat-change">QR belum di-scan</div>
                </div>
            </div>

            <!-- Device List Card -->
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3 class="card-title">Daftar Device</h3>
                        <p class="card-subtitle">Setiap device memiliki database terpisah (isolated) dan sesi login mandiri</p>
                    </div>
                    <div id="device-loading" style="display:none;font-size:0.8125rem;color:var(--text-secondary);">
                        Memuat...
                    </div>
                </div>

                <!-- Empty State -->
                <div id="device-empty" style="text-align:center;padding:3rem 1rem;display:none;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="48" height="48" style="color:var(--border-color);margin:0 auto 1rem;display:block;"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
                    <p style="color:var(--text-secondary);margin-bottom:1rem;">Belum ada device yang terdaftar</p>
                    <button class="btn btn-primary" onclick="gowaOpenCreateModal()">Buat Device Pertama</button>
                </div>

                <!-- Error State -->
                <div id="device-error" style="display:none;padding:1rem;background:rgba(239,68,68,0.08);border-radius:8px;border:1px solid rgba(239,68,68,0.2);margin-bottom:1rem;">
                    <p style="font-size:0.875rem;color:var(--danger);" id="device-error-msg"></p>
                </div>

                <!-- Device Table -->
                <div class="table-container" id="device-table-wrapper">
                    <table id="device-table">
                        <thead>
                            <tr>
                                <th style="width:44px;"></th>
                                <th>Device / Label</th>
                                <th>Pemilik</th>
                                <th>Nomor WhatsApp</th>
                                <th>Database (Isolated)</th>
                                <th>Status</th>
                                <th>Terakhir Terhubung</th>
                                <th style="width:200px;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="device-tbody">
                        </tbody>
                    </table>
                </div>
            </div>
        </main>

        <footer class="footer"><p>&copy; 2026 TEAM <?php echo htmlspecialchars(webCompanyName(), ENT_QUOTES, 'UTF-8'); ?></p></footer>
    </div>

    <!-- ============================
         MODAL: Create Device
         ============================ -->
    <div id="modal-create" class="modal-overlay" style="display:none;" onclick="gowaCloseCreateModal(event)">
        <div class="modal-box" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 class="modal-title">Buat Device Baru</h3>
                <button class="modal-close" onclick="gowaCloseCreateModal()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Nama / Label Device <span style="color:var(--danger);">*</span></label>
                    <input type="text" id="create-device-label" class="form-input" placeholder="contoh: Kantor Pusat, CS-1, Marketing" maxlength="80">
                    <p style="font-size:0.75rem;color:var(--text-secondary);margin-top:0.375rem;">Nama unik untuk mengidentifikasi device ini</p>
                </div>
                <details style="margin-bottom:1rem;border:1px solid var(--border-color);border-radius:8px;background:var(--bg-surface);">
                    <summary style="cursor:pointer;list-style:none;padding:0.75rem 0.875rem;font-size:0.8125rem;font-weight:600;color:var(--text-primary);">
                        Advanced (opsional)
                    </summary>
                    <div style="padding:0 0.875rem 0.875rem;">
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Webhook URL (opsional)</label>
                            <input type="text" id="create-device-webhook" class="form-input" placeholder="https://yourapp.com/webhook/wa">
                            <p style="font-size:0.75rem;color:var(--text-secondary);margin-top:0.375rem;">Isi jika Anda ingin menerima notifikasi event dari device ke endpoint aplikasi Anda.</p>
                        </div>
                    </div>
                </details>
                <div style="background:var(--bg-surface);border-radius:8px;padding:1rem;margin-bottom:1rem;">
                    <div style="display:flex;align-items:flex-start;gap:0.75rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" style="color:var(--accent);flex-shrink:0;margin-top:1px;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        <div>
                            <p style="font-size:0.8125rem;font-weight:600;color:var(--text-primary);margin-bottom:0.25rem;">Data Isolation Aktif</p>
                            <p style="font-size:0.75rem;color:var(--text-secondary);">Admin dan user lain tidak dapat mengakses data device ini.</p>
                        </div>
                    </div>
                </div>
                <div id="create-error" style="display:none;padding:0.75rem 1rem;background:rgba(239,68,68,0.08);border-radius:8px;border:1px solid rgba(239,68,68,0.2);margin-bottom:1rem;">
                    <p style="font-size:0.875rem;color:var(--danger);" id="create-error-msg"></p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="gowaCloseCreateModal()">Batal</button>
                <button class="btn btn-primary" id="btn-create-device" onclick="gowaCreateDevice()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Buat Device
                </button>
            </div>
        </div>
    </div>

    <!-- ============================
         MODAL: QR Code Scanner
         ============================ -->
    <div id="modal-qr" class="modal-overlay" style="display:none;" onclick="gowaCloseQRModal(event)">
        <div class="modal-box modal-box-qr" onclick="event.stopPropagation()">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title">Scan QR Code</h3>
                    <p style="font-size:0.8125rem;color:var(--text-secondary);" id="qr-device-name">Menghubungkan device...</p>
                </div>
                <button class="modal-close" onclick="gowaCloseQRModal()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
            </div>
            <div class="modal-body" style="text-align:center;">
                <!-- Status Banner -->
                <div id="qr-status-banner" class="qr-status-banner qr-status-waiting">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <span id="qr-status-text">Menunggu QR Code...</span>
                </div>

                <!-- QR via PHP proxy iframe —— GoWA login page with Basic Auth stripped -->
                <div class="qr-image-wrapper" id="qr-image-wrapper" style="flex-direction:column;padding:0;overflow:hidden;">
                    <iframe id="qr-iframe"
                        src="about:blank"
                        style="display:none;"
                        sandbox="allow-scripts allow-same-origin allow-forms allow-popups"
                        title="WhatsApp QR Login">
                    </iframe>
                    <img id="qr-img" src="" alt="QR Code"
                        style="width:260px;height:260px;object-fit:contain;display:none;border-radius:8px;margin:0 auto;"
                        onerror="document.getElementById('qr-status-text').textContent='Gagal memuat QR'">
                    <div id="qr-success" class="qr-success-state" style="display:none;padding:1.5rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="56" height="56" style="color:var(--success);"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        <p style="font-weight:600;color:var(--success);margin-top:0.75rem;">Device Terhubung!</p>
                        <p style="font-size:0.875rem;color:var(--text-secondary);">WhatsApp berhasil terhubung ke device ini</p>
                    </div>
                </div>

                <!-- Instructions -->
                <div id="qr-instructions" style="margin-top:1.25rem;text-align:left;background:var(--bg-surface);border-radius:8px;padding:1rem;">
                    <p style="font-size:0.8125rem;font-weight:600;color:var(--text-primary);margin-bottom:0.5rem;">Cara scan QR:</p>
                    <ol style="font-size:0.8125rem;color:var(--text-secondary);padding-left:1.25rem;line-height:1.8;">
                        <li>Buka WhatsApp di HP Anda</li>
                        <li>Ketuk <strong>Menu (⋮)</strong> atau <strong>Settings</strong></li>
                        <li>Pilih <strong>Linked Devices</strong></li>
                        <li>Ketuk <strong>Link a Device</strong></li>
                        <li>Arahkan kamera ke QR di atas</li>
                    </ol>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" id="btn-refresh-qr" onclick="gowaRefreshQR()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.5"/></svg>
                    Refresh QR
                </button>
                <button class="btn btn-secondary" onclick="gowaCloseQRModal()">Tutup</button>
            </div>
        </div>
    </div>

    <!-- ============================
         MODAL: Delete Confirm
         ============================ -->
    <div id="modal-delete" class="modal-overlay" style="display:none;" onclick="gowaCloseDeleteModal(event)">
        <div class="modal-box" style="max-width:420px;" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 class="modal-title" style="color:var(--danger);">Hapus Device</h3>
                <button class="modal-close" onclick="gowaCloseDeleteModal()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
            </div>
            <div class="modal-body">
                <p style="color:var(--text-primary);margin-bottom:0.5rem;">Hapus device <strong id="delete-device-name">ini</strong>?</p>
                <p style="font-size:0.875rem;color:var(--text-secondary);">Sesi WhatsApp akan diputus dan data device dihapus dari server. Tindakan ini <strong>tidak dapat dibatalkan</strong>.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="gowaCloseDeleteModal()">Batal</button>
                <button class="btn" id="btn-confirm-delete" style="background:var(--danger);color:white;" onclick="gowaConfirmDelete()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                    Ya, Hapus Device
                </button>
            </div>
        </div>
    </div>

    <!-- ============================
         MODAL: API URL (ditampilkan setelah buat device)
         ============================ -->
    <div id="modal-apiurl" class="modal-overlay" style="display:none;" onclick="gowaCloseApiUrlModal(event)">
        <div class="modal-box" style="max-width:600px;" onclick="event.stopPropagation()">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title">API URL Kirim Pesan</h3>
                    <p style="font-size:0.8125rem;color:var(--text-secondary);margin-top:0.25rem;">Device: <strong id="apiurl-device-name"></strong></p>
                </div>
                <button class="modal-close" onclick="gowaCloseApiUrlModal()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
            </div>
            <div class="modal-body">
                <div style="background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.2);border-radius:8px;padding:1rem;margin-bottom:1.25rem;">
                    <div style="display:flex;align-items:flex-start;gap:0.75rem;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="color:var(--success);flex-shrink:0;margin-top:2px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        <div>
                            <p style="font-size:0.875rem;font-weight:600;color:var(--success);margin-bottom:0.25rem;">Device berhasil dibuat!</p>
                            <p style="font-size:0.8125rem;color:var(--text-secondary);">Salin URL di bawah dan gunakan di aplikasi Anda untuk mengirim pesan WhatsApp.</p>
                        </div>
                    </div>
                </div>

                <div style="margin-bottom:1rem;">
                    <label class="form-label">API Secret Key</label>
                    <div style="display:flex;align-items:center;gap:0.75rem;">
                        <code id="apiurl-secret" style="font-size:0.875rem;background:var(--bg-surface);padding:0.5rem 0.75rem;border-radius:6px;border:1px solid var(--border-color);flex:1;word-break:break-all;"></code>
                    </div>
                    <p style="font-size:0.75rem;color:var(--text-secondary);margin-top:0.375rem;">Simpan secret ini — jangan bagikan ke publik</p>
                </div>

                <div style="margin-bottom:1rem;">
                    <label class="form-label">URL Kirim Pesan</label>
                    <div style="background:var(--bg-surface);border:1px solid var(--border-color);border-radius:8px;padding:0.75rem;position:relative;">
                        <code id="apiurl-url" style="font-size:0.8125rem;word-break:break-all;line-height:1.6;"></code>
                    </div>
                </div>

                <div style="background:var(--bg-surface);border-radius:8px;padding:1rem;">
                    <p style="font-size:0.8125rem;font-weight:600;color:var(--text-primary);margin-bottom:0.5rem;">Cara penggunaan:</p>
                    <ol style="font-size:0.8125rem;color:var(--text-secondary);padding-left:1.25rem;line-height:1.8;">
                        <li>Ganti <code>[number]</code> dengan nomor tujuan (format: 628xxxxxxxxx)</li>
                        <li>Ganti <code>[text]</code> dengan pesan yang ingin dikirim (URL-encoded)</li>
                        <li>Pastikan device sudah terhubung (scan QR terlebih dahulu)</li>
                        <li>Akses URL via GET request dari aplikasi/script Anda</li>
                    </ol>
                    <div style="margin-top:0.75rem;padding:0.75rem;background:var(--bg-card);border-radius:6px;border:1px solid var(--border-color);">
                        <p style="font-size:0.75rem;color:var(--text-secondary);margin-bottom:0.25rem;">Contoh respons sukses:</p>
                        <code style="font-size:0.75rem;color:var(--success);">{&quot;status&quot;:&quot;success&quot;,&quot;message&quot;:&quot;Message sent successfully&quot;,&quot;code&quot;:200}</code>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" id="btn-copy-apiurl">Salin URL</button>
                <button class="btn btn-primary" onclick="gowaCloseApiUrlModal()">Selesai, Scan QR</button>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="toast-container" class="toast-container"></div>

    <!-- ============================
         MODAL: Test WA
         ============================ -->
    <div id="modal-test-wa" class="modal-overlay" style="display:none;" onclick="gowaCloseTestModal(event)">
        <div class="modal-box" style="max-width:460px;" onclick="event.stopPropagation()">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title">Test Kirim Pesan</h3>
                    <p style="font-size:0.8125rem;color:var(--text-secondary);margin-top:0.25rem;">Device: <strong id="test-device-name"></strong></p>
                </div>
                <button class="modal-close" onclick="gowaCloseTestModal()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
            </div>
            <div class="modal-body" style="display:flex;flex-direction:column;gap:1rem;">
                <div class="form-group">
                    <label class="form-label" for="test-phone">Nomor Tujuan</label>
                    <input type="text" id="test-phone" class="form-input" placeholder="628123456789" autocomplete="off">
                    <p style="font-size:0.75rem;color:var(--text-secondary);margin-top:0.25rem;">Format: kode negara + nomor (contoh: 628123456789)</p>
                </div>
                <div class="form-group">
                    <label class="form-label" for="test-message">Pesan</label>
                    <textarea id="test-message" class="form-input" rows="4" placeholder="Ketik pesan test..." style="resize:vertical;"></textarea>
                </div>
                <div id="test-result" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="gowaCloseTestModal()">Batal</button>
                <button class="btn btn-primary" id="btn-send-test" onclick="gowaSendTestMessage()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    Kirim
                </button>
            </div>
        </div>
    </div>

    <!-- Device Settings Modal -->
    <div id="modal-device-settings" class="modal-overlay" style="display:none;" onclick="gowaCloseDeviceSettings(event)">
        <div class="modal-box" style="max-width:460px;" onclick="event.stopPropagation()">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title">Pengaturan Device</h3>
                    <p style="font-size:0.8125rem;color:var(--text-secondary);margin-top:0.25rem;">Device: <strong id="settings-device-name"></strong></p>
                </div>
                <button class="modal-close" onclick="gowaCloseDeviceSettings()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
            </div>
            <div class="modal-body" style="display:flex;flex-direction:column;gap:1.25rem;">
                <div id="settings-error" style="display:none;background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);border-radius:8px;padding:0.75rem 1rem;font-size:0.875rem;color:var(--danger);">
                    <strong>Error:</strong> <span id="settings-error-msg"></span>
                </div>
                <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:0.75rem;background:rgba(99,102,241,0.05);border-radius:8px;border:1px solid rgba(99,102,241,0.15);">
                    <div>
                        <p style="font-weight:600;color:#1f2937;margin:0;">Queue WA Messages</p>
                        <p style="font-size:0.75rem;color:#6b7280;margin:0.25rem 0 0 0;">Aktifkan antrian untuk pengiriman pesan yang terkontrol</p>
                    </div>
                    <label style="position:relative;display:inline-block;width:56px;height:32px;cursor:pointer;user-select:none;">
                        <input type="checkbox" id="settings-queue-enabled" style="position:absolute;top:0;left:0;opacity:0;width:100%;height:100%;cursor:pointer;margin:0;z-index:10;">
                        <div id="settings-queue-track" style="position:absolute;top:50%;left:0;right:0;height:20px;background-color:#d1d5db;border-radius:10px;transform:translateY(-50%);transition:all 0.3s ease;margin:0;pointer-events:none;"></div>
                        <div id="settings-queue-thumb" style="position:absolute;top:50%;left:2px;width:28px;height:28px;background-color:white;border-radius:50%;transform:translateY(-50%);transition:all 0.3s ease;box-shadow:0 2px 4px rgba(0,0,0,0.2);margin:0;pointer-events:none;"></div>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="gowaCloseDeviceSettings()">Batal</button>
                <button class="btn btn-primary" id="btn-save-settings" onclick="gowaSaveDeviceSettings()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    Simpan
                </button>
            </div>
        </div>
    </div>

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

