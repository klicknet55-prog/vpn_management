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
    header('Location: ../admin/index.php');
    exit();
}

require_once __DIR__ . '/../system/seo.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php seoRenderHeadTags('User Dashboard - VPN & WhatsApp API Manager'); ?>
    <script>
        window.DAYNIGHT_SESSION = <?php echo json_encode([
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
        if (localStorage.getItem('daynight-theme') === 'carbon') {
            document.documentElement.classList.add('carbon');
        }
    </script>
    <link rel="stylesheet" href="../templatemo-daynight-style.css?v=<?php echo (int) (file_exists(__DIR__ . '/../templatemo-daynight-style.css') ? filemtime(__DIR__ . '/../templatemo-daynight-style.css') : time()); ?>">
    <style>
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

        .admin-alert {
            padding: 0.625rem 0.75rem;
            border-radius: 10px;
            font-size: 0.8125rem;
            white-space: pre-wrap;
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

        /* Produk dropdown */
        .nav-item.has-dropdown {
            position: relative;
        }
        .nav-dropdown-btn {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 0.875rem;
            font-family: inherit;
            color: var(--text-secondary);
            padding: 0.4rem 0.5rem;
            border-radius: 8px;
            transition: color 0.15s, background 0.15s;
        }
        .nav-dropdown-btn:hover,
        .nav-item.has-dropdown.open .nav-dropdown-btn {
            color: var(--text-primary);
            background: var(--bg-hover, rgba(0,0,0,0.05));
        }
        .nav-dropdown-btn svg { flex-shrink: 0; }
        .nav-dropdown-arrow {
            width: 12px;
            height: 12px;
            transition: transform 0.2s;
        }
        .nav-item.has-dropdown.open .nav-dropdown-arrow {
            transform: rotate(180deg);
        }
        .nav-dropdown-panel {
            display: none;
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            min-width: 190px;
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            padding: 0.4rem;
            z-index: 200;
        }
        .nav-item.has-dropdown.open .nav-dropdown-panel {
            display: block;
        }
        .nav-dropdown-panel a {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            padding: 0.55rem 0.75rem;
            border-radius: 8px;
            font-size: 0.8125rem;
            color: var(--text-secondary);
            text-decoration: none;
            transition: background 0.12s, color 0.12s;
        }
        .nav-dropdown-panel a:hover {
            background: var(--bg-hover, rgba(0,0,0,0.05));
            color: var(--text-primary);
        }

        /* Mobile Produk accordion */
        .mobile-group-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.65rem 1rem;
            font-size: 0.875rem;
            color: var(--text-secondary);
            cursor: pointer;
            user-select: none;
            border-radius: 8px;
            transition: background 0.12s;
        }
        .mobile-group-label:hover { background: var(--bg-hover, rgba(0,0,0,0.05)); }
        .mobile-group-label svg { flex-shrink: 0; }
        .mobile-group-arrow {
            margin-left: auto;
            width: 14px;
            height: 14px;
            transition: transform 0.2s;
        }
        .mobile-group.open .mobile-group-arrow { transform: rotate(180deg); }
        .mobile-group-children {
            display: none;
            padding-left: 1.25rem;
        }
        .mobile-group.open .mobile-group-children { display: block; }
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
                <div class="logo-icon">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                </div>
                <?php echo htmlspecialchars(webCompanyName(), ENT_QUOTES, 'UTF-8'); ?>
            </a>
            <button class="mobile-menu-close" onclick="closeMobileMenu()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <nav class="mobile-menu-nav">
            <a href="index.php" class="active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>Dashboard</a>
            <div class="mobile-group" id="mobile-group-produk">
                <div class="mobile-group-label" onclick="toggleMobileGroup('produk')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    Produk
                    <svg class="mobile-group-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                </div>
                <div class="mobile-group-children">
                    <a href="wa-devices.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12" y2="18"/></svg>WA Devices</a>
                    <a href="vpn-users.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>VPN Management</a>
                    <a href="proxy-routes.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M2 12h3M19 12h3M12 2v3M12 19v3"/><path d="M4.93 4.93l2.12 2.12M16.95 16.95l2.12 2.12M19.07 4.93l-2.12 2.12M7.05 16.95l-2.12 2.12"/></svg>Proxy Routes</a>
                </div>
            </div>
            <a href="subscription.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>Subscription</a>
            <a href="#" onclick="openInfoModal('about');closeMobileMenu();return false;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="8"/><line x1="12" y1="12" x2="12" y2="16"/></svg>Tentang Kami</a>
            <a href="#" onclick="openInfoModal('contact');closeMobileMenu();return false;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2A19.72 19.72 0 0 1 3.1 5.18 2 2 0 0 1 5.09 3h3a2 2 0 0 1 2 1.72c.13 1 .37 1.97.72 2.9a2 2 0 0 1-.45 2.11L9.09 10.91A16 16 0 0 0 14 15.86l1.27-1.27a2 2 0 0 1 2.11-.45c.93.35 1.9.59 2.9.72A2 2 0 0 1 22 16.92z"/></svg>Kontak</a>
            <a href="#" onclick="openInfoModal('tc');closeMobileMenu();return false;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>T&amp;C</a>
        </nav>
        <div class="mobile-menu-footer">
            <a href="../api/logout.php" class="mobile-logout-btn">Logout</a>
            <div class="theme-toggle">
                <button class="theme-btn theme-btn-snow active" onclick="setTheme('snow')" title="Snow Edition">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/></svg>
                </button>
                <button class="theme-btn theme-btn-carbon" onclick="setTheme('carbon')" title="Carbon Edition">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                </button>
            </div>
        </div>
    </div>

    <div class="app-container">
        <nav class="top-nav">
            <div class="nav-container">
                <div class="nav-left">
                    <a href="index.php" class="logo">
                        <div class="logo-icon">
                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                        </div>
                        <?php echo htmlspecialchars(webCompanyName(), ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                    <div class="nav-menu">
                        <div class="nav-item"><a href="index.php" class="nav-link active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>Dashboard</a></div>
                        <div class="nav-item has-dropdown" id="desktop-dropdown-produk">
                            <button class="nav-dropdown-btn" onclick="toggleDesktopDropdown('produk')">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                                Produk
                                <svg class="nav-dropdown-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                            </button>
                            <div class="nav-dropdown-panel">
                                <a href="wa-devices.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>WA Devices</a>
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
                        <button class="theme-btn theme-btn-snow active" onclick="setTheme('snow')" title="Snow Edition">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/></svg>
                        </button>
                        <button class="theme-btn theme-btn-carbon" onclick="setTheme('carbon')" title="Carbon Edition">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                        </button>
                    </div>
                    <div class="account-menu-wrap" id="account-menu-wrap">
                        <button class="user-menu" onclick="toggleAccountDropdown()">
                            <div class="user-avatar">U</div>
                            <span class="user-name" data-current-user>User</span>
                        </button>
                        <div class="account-dropdown">
                            <button onclick="openAccountModal('edit-profile')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Edit Profil</button>
                            <button onclick="openAccountModal('change-password')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Ganti Password</button>
                        </div>
                    </div>
                    <a href="../api/logout.php" class="btn-logout desktop-only" title="Logout">Logout</a>
                    <button class="mobile-menu-btn" onclick="toggleMobileMenu()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                    </button>
                </div>
            </div>
        </nav>

        <main class="main-content">
            <div class="page-header">
                <h1 class="greeting" id="greeting">User Workspace</h1>
                <p class="greeting-sub">Kelola layanan VPN dan WhatsApp Gateway API</p>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">My Role</div>
                    <div class="stat-value"><?php echo strtoupper(htmlspecialchars($sessionRole, ENT_QUOTES, 'UTF-8')); ?></div>
                    <div class="stat-change positive">Scoped to your own resources</div>
                </div>
                <div class="stat-card clickable" id="dash-stat-wa" onclick="dashToggle('wa')" title="Klik untuk lihat tabel WA device">
                    <div class="stat-label">WA API GATEWAY</div>
                    <div class="stat-value">Manage</div>
                    <div class="stat-change">Klik untuk lihat device ↓</div>
                </div>
                <div class="stat-card clickable" id="dash-stat-vpn" onclick="dashToggle('vpn')" title="Klik untuk lihat daftar akun VPN">
                    <div class="stat-label">AKUN VPN</div>
                    <div class="stat-value">VPN REMOTE</div>
                    <div class="stat-change">Klik untuk lihat akun ↓</div>
                </div>
                <div class="stat-card" id="dash-sub-card" style="cursor:pointer;" onclick="location.href='subscription.php'" title="Klik untuk kelola subscription">
                    <div class="stat-label">SUBSCRIPTION</div>
                    <div class="stat-value" id="dash-sub-plan" style="font-size:1.1rem;">—</div>
                    <div class="stat-change" id="dash-sub-days">Memuat...</div>
                </div>
            </div>

            <!-- Expired subscription banner -->
            <div id="dash-expired-banner" hidden style="margin-top:.75rem;margin-bottom:.25rem;">
                <div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.3);border-radius:12px;padding:.875rem 1.25rem;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="var(--danger)" stroke-width="2" width="18" height="18"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <span style="font-size:.875rem;color:var(--danger);font-weight:600;">Subscription expired.</span>
                    <span style="font-size:.8125rem;color:var(--text-secondary);">Fitur tambah VPN, WA device, dan proxy route dinonaktifkan.</span>
                    <a href="upgrade.php" class="btn btn-primary" style="margin-left:auto;font-size:.8125rem;white-space:nowrap;">Upgrade Sekarang</a>
                </div>
            </div>

            <div id="dash-wa-panel" hidden style="margin-top:1rem;">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">WA API GATEWAY</h3>
                            <p class="card-subtitle">Daftar WA device Anda</p>
                        </div>
                        <button class="btn btn-secondary" style="font-size:0.75rem;padding:0.35rem 0.75rem;" onclick="dashToggle('wa')">Tutup ×</button>
                    </div>
                    <div id="dash-wa-body" style="overflow:auto;"><p style="color:var(--text-secondary);font-size:0.875rem;">Memuat data...</p></div>
                </div>
            </div>

            <div id="dash-vpn-panel" hidden style="margin-top:1rem;">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">AKUN VPN</h3>
                            <p class="card-subtitle">Daftar akun VPN Anda</p>
                        </div>
                        <button class="btn btn-secondary" style="font-size:0.75rem;padding:0.35rem 0.75rem;" onclick="dashToggle('vpn')">Tutup ×</button>
                    </div>
                    <div id="dash-vpn-body" style="overflow:auto;"><p style="color:var(--text-secondary);font-size:0.875rem;">Memuat data...</p></div>
                </div>
            </div>

            <div class="two-col" style="margin-top:1.5rem;">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">Quick Actions</h3>
                            <p class="card-subtitle">Masuk ke modul yang paling sering dipakai</p>
                        </div>
                    </div>
                    <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
                        <a href="wa-devices.php" class="btn btn-primary">Open WA Devices</a>
                        <a href="vpn-users.php" class="btn btn-secondary">Create VPN</a>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">Access Scope</h3>
                            <p class="card-subtitle">Aturan akses untuk role user</p>
                        </div>
                    </div>
                    <ul class="activity-list">
                        <li class="activity-line"><span>Kelola WA device yang Anda buat sendiri</span><span class="activity-time">Allowed</span></li>
                        <li class="activity-line"><span>Kelola konfigurasi milik workspace Anda</span><span class="activity-time">Allowed</span></li>
                        <li class="activity-line"><span>Akses dashboard admin dan data user lain</span><span class="activity-time">Blocked</span></li>
                    </ul>
                </div>
            </div>

        </main>

        <footer class="footer">
            <p>&copy; 2026 TEAM <?php echo htmlspecialchars(webCompanyName(), ENT_QUOTES, 'UTF-8'); ?></p>
        </footer>

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
                    var h = '<table class="dash-mini-table"><thead><tr><th>Label</th><th>Phone</th><th>Status</th></tr></thead><tbody>';
                    list.forEach(function (d) {
                        var dot = d.status === 'connected' ? 'connected' : 'disconnected';
                        h += '<tr><td>' + esc(d.label || d.device_id || '-') + '</td><td>' + esc(d.phone_jid || '-') + '</td>';
                        h += '<td><span class="dash-status-dot ' + dot + '"></span>' + esc(d.status || '-') + '</td></tr>';
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
                    var h = '<table class="dash-mini-table"><thead><tr><th>Username</th><th>Status</th></tr></thead><tbody>';
                    list.forEach(function (u) {
                        var dot = u.vpn_status === 'active' ? 'active' : 'inactive';
                        h += '<tr><td>' + esc(u.username || '-') + '</td>';
                        h += '<td><span class="dash-status-dot ' + dot + '"></span>' + esc(u.vpn_status || '-') + '</td></tr>';
                    });
                    h += '</tbody></table>';
                    wrap.innerHTML = h;
                })
                .catch(function () { wrap.innerHTML = '<p style="color:var(--danger);font-size:0.875rem;">Gagal memuat data.</p>'; });
        }

        // ── Subscription widget ───────────────────────────────
        (function loadSubWidget() {
            fetch('../api/subscription.php?action=info', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.ok || !res.data) return;
                    var d = res.data;
                    var planEl  = document.getElementById('dash-sub-plan');
                    var daysEl  = document.getElementById('dash-sub-days');
                    var card    = document.getElementById('dash-sub-card');
                    var banner  = document.getElementById('dash-expired-banner');

                    planEl.textContent = d.plan ? (d.plan.label || d.plan.name || 'Free') : 'Free';

                    if (!d.is_active) {
                        daysEl.textContent = 'Expired – klik untuk upgrade';
                        daysEl.style.color = 'var(--danger)';
                        card.style.borderColor = 'var(--danger)';
                        if (banner) banner.hidden = false;
                    } else if (d.days_remaining <= 3) {
                        daysEl.textContent = d.days_remaining + ' hari lagi – segera perpanjang';
                        daysEl.style.color = 'var(--warning,#f59e0b)';
                        card.style.borderColor = 'var(--warning,#f59e0b)';
                    } else {
                        daysEl.textContent = d.days_remaining + ' hari tersisa';
                        daysEl.style.color = 'var(--success)';
                    }
                })
                .catch(function () {
                    var daysEl = document.getElementById('dash-sub-days');
                    if (daysEl) daysEl.textContent = 'Gagal memuat';
                });
        })();
    })();
    </script>
    </div>

    <script>
    function toggleDesktopDropdown(key) {
        var el = document.getElementById('desktop-dropdown-' + key);
        if (!el) return;
        var isOpen = el.classList.contains('open');
        // close all dropdowns
        document.querySelectorAll('.nav-item.has-dropdown.open').forEach(function(d) { d.classList.remove('open'); });
        if (!isOpen) el.classList.add('open');
    }
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.nav-item.has-dropdown')) {
            document.querySelectorAll('.nav-item.has-dropdown.open').forEach(function(d) { d.classList.remove('open'); });
        }
    });
    function toggleMobileGroup(key) {
        var el = document.getElementById('mobile-group-' + key);
        if (el) el.classList.toggle('open');
    }
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