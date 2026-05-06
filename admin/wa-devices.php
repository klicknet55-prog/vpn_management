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
if ($sessionRole !== 'admin') {
    header('Location: ../user/wa-devices.php');
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
        window.GOWA_PAGE_MODE = 'admin';
        window.DAYNIGHT_SESSION = <?php echo json_encode([
            'userId'      => (int) ($_SESSION['user_id'] ?? 0),
            'fullName'    => $sessionUserName,
            'email'       => $sessionUserEmail,
            'phoneNumber' => $sessionPhoneNumber,
            'role'        => $sessionRole,
            'roles'       => $sessionRoles,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        localStorage.setItem('daynight-user-name', window.DAYNIGHT_SESSION.fullName || 'Admin');
        localStorage.setItem('daynight-user-email', window.DAYNIGHT_SESSION.email || '');
        localStorage.setItem('daynight-user-role', window.DAYNIGHT_SESSION.role || 'admin');
        localStorage.setItem('daynight-user-roles', JSON.stringify(window.DAYNIGHT_SESSION.roles || []));
        if(localStorage.getItem("daynight-theme")==="carbon"){document.documentElement.classList.add("carbon");}
    </script>
    <link rel="stylesheet" href="../templatemo-daynight-style.css?v=<?php echo (int) (file_exists(__DIR__ . '/../templatemo-daynight-style.css') ? filemtime(__DIR__ . '/../templatemo-daynight-style.css') : time()); ?>">
    <style>
        .wa-filter-header {
            gap: 0.75rem;
        }

        .wa-filter-toolbar {
            display: flex;
            align-items: center;
            gap: .55rem;
            flex-wrap: nowrap;
            overflow-x: auto;
            max-width: 100%;
            padding-bottom: 2px;
        }

        .wa-filter-control {
            flex: 0 0 auto;
        }

        @media (max-width: 768px) {
            .wa-filter-header {
                align-items: stretch;
            }

            .wa-filter-toolbar {
                display: grid;
                grid-template-columns: 1fr;
                overflow: visible;
                width: 100%;
                gap: .5rem;
            }

            .wa-filter-control {
                min-width: 0 !important;
                max-width: none !important;
                width: 100%;
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
            <a href="wa-devices.php" class="active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12" y2="18"/></svg>WA Devices</a>
            <a href="vpn-users.php">VPN Management</a>
            <a href="proxy-routes.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M2 12h3M19 12h3M12 2v3M12 19v3"/><path d="M4.93 4.93l2.12 2.12M16.95 16.95l2.12 2.12M19.07 4.93l-2.12 2.12M7.05 16.95l-2.12 2.12"/></svg>Proxy Routes</a>
        <a href="config.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>Config</a>
            <a href="audit-log.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3h18v18H3z"/><path d="M7 7h10"/><path d="M7 12h10"/><path d="M7 17h6"/></svg>Audit Log</a>
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
                        <div class="nav-item"><a href="wa-devices.php" class="nav-link active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>WA Devices</a></div>
                        <div class="nav-item"><a href="vpn-users.php" class="nav-link">VPN Management</a></div>
                                            <div class="nav-item"><a href="proxy-routes.php" class="nav-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M2 12h3M19 12h3M12 2v3M12 19v3"/><path d="M4.93 4.93l2.12 2.12M16.95 16.95l2.12 2.12M19.07 4.93l-2.12 2.12M7.05 16.95l-2.12 2.12"/></svg>Proxy Routes</a></div>
                    <div class="nav-item"><a href="config.php" class="nav-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>Config</a></div>
                        <div class="nav-item"><a href="audit-log.php" class="nav-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3h18v18H3z"/><path d="M7 7h10"/><path d="M7 12h10"/><path d="M7 17h6"/></svg>Audit Log</a></div>
                    </div>
                </div>
                <div class="nav-right">
                    <div class="theme-toggle">
                        <button class="theme-btn theme-btn-snow active" onclick="setTheme('snow')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/></svg></button>
                        <button class="theme-btn theme-btn-carbon" onclick="setTheme('carbon')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg></button>
                    </div>
                    <div class="account-menu-wrap" id="account-menu-wrap">
                        <button class="user-menu" onclick="toggleAccountDropdown()"><div class="user-avatar">A</div><span class="user-name" data-current-user>Admin</span></button>
                        <div class="account-dropdown">
                            <button onclick="openAccountModal('edit-profile')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Edit Profil</button>
                            <button onclick="openAccountModal('change-password')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Ganti Password</button>
                        </div>
                    </div>
                    <a href="../api/logout.php" class="btn-logout" title="Logout"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></a>
                    <button class="mobile-menu-btn" onclick="toggleMobileMenu()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
                </div>
            </div>
        </nav>

        <main class="main-content">
            <!-- Page Header -->
            <div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
                <div>
                    <h1 class="greeting">WA Device Oversight</h1>
                    <p class="greeting-sub">Halaman admin untuk memonitor device user. Device milik user hanya bisa dihapus.</p>
                </div>
                <div style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
                    <button class="btn btn-secondary" onclick="gowaLoadDevices()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.5"/></svg>
                        Refresh
                    </button>
                    <button class="btn btn-primary" onclick="gowaOpenCreateModal()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Buat Device Admin
                    </button>
                </div>
            </div>

            <!-- GoWA Server Info Bar -->
            <div class="card" style="margin-bottom:1.5rem;padding:1rem 1.5rem;">
                <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="color:var(--accent);flex-shrink:0;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    <div style="flex:1;">
                        <p style="font-size:0.875rem;font-weight:600;color:var(--text-primary);margin-bottom:0.125rem;">Mode Moderasi Admin Aktif</p>
                        <p style="font-size:0.8125rem;color:var(--text-secondary);">Admin memonitor seluruh device dan dapat membuat device admin untuk notifikasi. Device milik user lain dibatasi hanya hapus device.</p>
                    </div>
                    <div style="text-align:right;">
                        <p style="font-size:0.75rem;color:var(--text-secondary);margin-bottom:0.25rem;">Kebijakan</p>
                        <code style="font-size:0.75rem;background:var(--bg-surface);padding:0.25rem 0.5rem;border-radius:6px;color:var(--accent);">Admin create: allowed | User device: delete-only</code>
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
                    <div class="stat-change negative">Perlu tindak lanjut user</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Menunggu Scan</div>
                    <div class="stat-value" id="stat-pending" style="color:var(--warning);">0</div>
                    <div class="stat-change">Menunggu aktivasi dari user</div>
                </div>
            </div>

            <!-- Device List Card -->
            <div class="card">
                <div class="card-header wa-filter-header">
                    <div>
                        <h3 class="card-title">Daftar Device</h3>
                        <p class="card-subtitle">Monitoring device seluruh user. Device milik user lain hanya tersedia aksi hapus.</p>
                    </div>
                    <div class="wa-filter-toolbar">
                        <select id="device-filter-owner" class="form-select wa-filter-control" style="min-width:165px;max-width:200px;">
                            <option value="all">Owner: Semua</option>
                            <option value="mine">Owner: Saya</option>
                            <option value="others">Owner: User Lain</option>
                        </select>
                        <select id="device-filter-status" class="form-select wa-filter-control" style="min-width:170px;max-width:220px;">
                            <option value="all">Status: Semua</option>
                            <option value="connected">Status: Connected</option>
                            <option value="disconnected">Status: Disconnected</option>
                            <option value="pending">Status: Pending / QR Ready</option>
                            <option value="error">Status: Error</option>
                        </select>
                        <input id="device-search-input" class="form-input wa-filter-control" type="text" placeholder="Cari device, owner, nomor, db..." style="min-width:240px;max-width:320px;">
                        <div id="device-loading" class="wa-filter-control" style="display:none;font-size:0.8125rem;color:var(--text-secondary);">
                            Memuat...
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div id="device-empty" style="text-align:center;padding:3rem 1rem;display:none;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="48" height="48" style="color:var(--border-color);margin:0 auto 1rem;display:block;"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
                    <p style="color:var(--text-secondary);margin-bottom:1rem;">Belum ada device yang terdaftar.</p>
                    <button class="btn btn-primary" onclick="gowaOpenCreateModal()">Buat Device Admin Pertama</button>
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
                                <th style="width:240px;">Aksi</th>
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
                            <p style="font-size:0.75rem;color:var(--text-secondary);">Device ini akan mendapatkan database terpisah secara otomatis. Data antar device tidak pernah bercampur.</p>
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
                    <label style="position:relative;display:inline-flex;width:50px;height:28px;cursor:pointer;">
                        <input type="checkbox" id="settings-queue-enabled" style="position:absolute;opacity:0;cursor:pointer;width:100%;height:100%;">
                        <span style="position:absolute;top:0;left:0;right:0;bottom:0;background-color:var(--border);border-radius:14px;transition:0.3s;"></span>
                        <span style="position:absolute;top:2px;left:2px;width:24px;height:24px;background-color:white;border-radius:12px;transition:0.3s;"></span>
                        <style>
                            #settings-queue-enabled:checked + span { background-color: var(--success); }
                            #settings-queue-enabled:checked + span + span { left: 24px; }
                        </style>
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

    <script src="../templatemo-daynight-script.js?v=<?php echo (int) $dayNightScriptVer; ?>"></script>
    <script src="../gowa-devices.js?v=<?php echo (int) $gowaScriptVer; ?>"></script>

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

