<?php
session_start();
if (empty($_SESSION['logged_in'])) {
    header('Location: ../login.php');
    exit();
}

$sessionRoles = $_SESSION['roles'] ?? [];
$isAdmin = in_array('admin', $sessionRoles, true) || in_array('super_admin', $sessionRoles, true);
if ($isAdmin) {
    header('Location: ../admin/vpn-users.php');
    exit();
}

$sessionUserName = $_SESSION['full_name'] ?? 'User';
$sessionUserEmail = $_SESSION['email'] ?? '';
$sessionPhoneNumber = $_SESSION['phone_number'] ?? '';

require_once __DIR__ . '/../system/seo.php';

// Load VPN subnet config for the IP template field
$vpnSubnetPrefix = '192.168.12';
$vpnIpRangeStart = 2;
$vpnIpRangeEnd   = 254;
try {
    require_once __DIR__ . '/../api/db.php';
    $db = getDB();
    $subnetStmt = $db->query(
        "SELECT config_key, config_value FROM app_settings
         WHERE config_key IN ('vpn.subnet_prefix','vpn.ip_range_start','vpn.ip_range_end')"
    );
    foreach ($subnetStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['config_key'] === 'vpn.subnet_prefix' && $row['config_value'] !== '') {
            $vpnSubnetPrefix = $row['config_value'];
        } elseif ($row['config_key'] === 'vpn.ip_range_start') {
            $vpnIpRangeStart = max(1, (int) $row['config_value']);
        } elseif ($row['config_key'] === 'vpn.ip_range_end') {
            $vpnIpRangeEnd = min(254, (int) $row['config_value']);
        }
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
    <?php seoRenderHeadTags('VPN Management - ' . webCompanyName()); ?>
    <script>
        window.VPN_API = '../api/vpn-controller.php';
        window.VPN_PAGE_MODE = 'user';
        window.VPN_SUBNET = <?php echo json_encode([
            'prefix'     => $vpnSubnetPrefix,
            'rangeStart' => $vpnIpRangeStart,
            'rangeEnd'   => $vpnIpRangeEnd,
        ], JSON_UNESCAPED_SLASHES); ?>;
        window.DAYNIGHT_SESSION = <?php echo json_encode([
            'fullName' => $sessionUserName,
            'email' => $sessionUserEmail,
            'phoneNumber' => $sessionPhoneNumber,
            'role' => 'user',
            'roles' => $sessionRoles,
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
        .admin-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:.875rem; }
        .form-label { display:block; font-size:.8125rem; margin-bottom:.35rem; color:var(--text-secondary); }
        .form-input,.form-select { width:100%; border:1px solid var(--border-color); border-radius:10px; background:var(--bg-surface); color:var(--text-primary); padding:.65rem .75rem; font-size:.875rem; }
        .admin-alert { padding:.625rem .75rem; border-radius:10px; font-size:.8125rem; white-space:pre-wrap; margin-bottom:1rem; }
        .admin-alert.error { background:rgba(239,68,68,.08); color:var(--danger); border:1px solid rgba(239,68,68,.28); }
        .admin-alert.success { background:rgba(34,197,94,.08); color:var(--success); border:1px solid rgba(34,197,94,.28); }
        .vpn-modal-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:1000; align-items:center; justify-content:center; }
        .vpn-modal-backdrop.open { display:flex; }
        .vpn-modal { background:var(--bg-primary); color:var(--text-primary); border:1px solid var(--border-color); border-radius:16px; padding:1.5rem; width:100%; max-width:480px; margin:1rem; box-shadow:0 8px 32px rgba(0,0,0,.25); }
        .vpn-modal-title { font-size:1.05rem; font-weight:600; margin-bottom:1.1rem; color:var(--text-primary); }
        .vpn-modal-grid { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; }
        .vpn-modal-grid .full { grid-column:1 / -1; }
        .vpn-modal-footer { display:flex; justify-content:flex-end; gap:.5rem; margin-top:1.1rem; }
        .vpn-modal-confirm-text { font-size:.9rem; margin-bottom:1rem; line-height:1.5; }
        .vpn-modal td { color:var(--text-primary); }
        .btn-action { padding:.22rem .55rem; font-size:.72rem; border-radius:7px; cursor:pointer; border:1px solid var(--border-color); background:var(--bg-surface); color:var(--text-primary); transition:opacity .15s; }
        .btn-action:hover { opacity:.75; }
        .btn-action.success { border-color:rgba(34,197,94,.4); color:var(--success); }
        .btn-action.danger { border-color:rgba(239,68,68,.4); color:var(--danger); }
        .btn-action.warn { border-color:rgba(234,179,8,.4); color:#ca8a04; }
        .badge { display:inline-block; padding:.1rem .45rem; border-radius:6px; font-size:.72rem; font-weight:600; }
        .badge.active { background:rgba(34,197,94,.12); color:var(--success); }
        .badge.suspended { background:rgba(239,68,68,.1); color:var(--danger); }
        .badge.expired { background:rgba(148,163,184,.12); color:var(--text-secondary); }
        @media (max-width:600px){ .vpn-modal-grid { grid-template-columns:1fr; } }
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
        <a href="vpn-users.php" class="active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>VPN Management</a>
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
                    <div class="nav-item"><a href="vpn-users.php" class="nav-link active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>VPN Management</a></div>
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
                <h1 class="greeting">My VPN Management</h1>
                <p class="greeting-sub">Kelola akun VPN dan NAT</p>
            </div>
            <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                <button class="btn btn-secondary" type="button" onclick="vpnRefreshData()">Refresh</button>
                <button class="btn btn-primary" type="button" onclick="vpnOpenCreatePf()">+ Port Forwarding</button>
                <button class="btn btn-primary" type="button" onclick="vpnOpenModal('modal-create-user')">Buat Akun VPN</button>
            </div>
        </div>

        <div id="vpn-alert" class="admin-alert" style="display:none;"></div>

        <div class="stats-grid" style="margin-bottom:1.25rem;">
            <div class="stat-card">
                <div class="stat-label">My VPN User</div>
                <div class="stat-value" id="vpn-stat-total-users">0</div>
                <div class="stat-change">Akun VPN Anda</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Active</div>
                <div class="stat-value" id="vpn-stat-active-users" style="color:var(--success);">0</div>
                <div class="stat-change positive">User aktif</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Suspended</div>
                <div class="stat-value" id="vpn-stat-suspended-users" style="color:var(--danger);">0</div>
                <div class="stat-change negative">User nonaktif</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">My Port Forwarding</div>
                <div class="stat-value" id="vpn-stat-total-pf">0</div>
                <div class="stat-change">Rule NAT Anda</div>
            </div>
        </div>

        <!-- VPN Users Table -->
        <div class="card" style="margin-bottom:1.25rem;">
            <div class="card-header">
                <div><h3 class="card-title">My VPN Users</h3></div>
                <div id="vpn-users-count" style="font-size:.8rem;color:var(--text-secondary);"></div>
            </div>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:.8rem;">
                    <thead>
                        <tr>
                            <th style="text-align:left;padding:.5rem .6rem;border-bottom:1px solid var(--border-color);">Username</th>
                            <th style="text-align:left;padding:.5rem .6rem;border-bottom:1px solid var(--border-color);">Status</th>
                            <th style="text-align:left;padding:.5rem .6rem;border-bottom:1px solid var(--border-color);">IP / Ext ID</th>
                            <th style="text-align:left;padding:.5rem .6rem;border-bottom:1px solid var(--border-color);">Dibuat</th>
                            <th style="text-align:left;padding:.5rem .6rem;border-bottom:1px solid var(--border-color);">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="vpn-users-body"></tbody>
                </table>
            </div>
        </div>

        <!-- Port Forwardings Table -->
        <div class="card" style="margin-bottom:1.25rem;">
            <div class="card-header">
                <div><h3 class="card-title">My Port Forwardings</h3></div>
                <div id="vpn-pf-count" style="font-size:.8rem;color:var(--text-secondary);"></div>
            </div>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:.8rem;">
                    <thead>
                        <tr>
                            <th style="text-align:left;padding:.5rem .6rem;border-bottom:1px solid var(--border-color);">Rule Name</th>
                            <th style="text-align:left;padding:.5rem .6rem;border-bottom:1px solid var(--border-color);">Proto</th>
                            <th style="text-align:left;padding:.5rem .6rem;border-bottom:1px solid var(--border-color);">Listen Port</th>
                            <th style="text-align:left;padding:.5rem .6rem;border-bottom:1px solid var(--border-color);">Dest IP:Port</th>
                            <th style="text-align:left;padding:.5rem .6rem;border-bottom:1px solid var(--border-color);">Dibuat</th>
                            <th style="text-align:left;padding:.5rem .6rem;border-bottom:1px solid var(--border-color);">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="vpn-pf-body"></tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Modal: Create VPN User -->
    <div class="vpn-modal-backdrop" id="modal-create-user" onclick="vpnBackdropClose(event,'modal-create-user')">
        <div class="vpn-modal">
            <div class="vpn-modal-title">Buat Akun VPN</div>
            <div id="modal-create-user-alert" class="admin-alert" style="display:none;margin-bottom:.75rem;"></div>
            <div class="vpn-modal-grid">
                <div><label class="form-label">Username</label><input class="form-input" id="vpn_create_username" type="text" placeholder="user1" autocomplete="off"></div>
                <div><label class="form-label">Password</label><input class="form-input" id="vpn_create_password" type="password" placeholder="min 6 karakter"></div>
                <div class="full" style="font-size:.8rem;color:var(--text-secondary);background:var(--bg-surface);border:1px solid var(--border-color);border-radius:10px;padding:.6rem .75rem;">
                    RANGE IP <strong id="vpn_create_ip_info"><?php echo htmlspecialchars($vpnSubnetPrefix, ENT_QUOTES, 'UTF-8'); ?>.<?php echo $vpnIpRangeStart; ?> &ndash; <?php echo htmlspecialchars($vpnSubnetPrefix, ENT_QUOTES, 'UTF-8'); ?>.<?php echo $vpnIpRangeEnd; ?></strong>
                </div>
                <div class="full" style="font-size:.84rem;display:grid;gap:.55rem;">
                    <label style="display:flex;align-items:center;gap:.55rem;">
                        <input id="vpn_tpl_winbox_enabled" type="checkbox" checked>
                        <span>Create/aktifkan port forwarding Winbox</span>
                    </label>
                    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                        <input class="form-input" id="vpn_tpl_winbox_dest_port" type="number" min="1" max="65535" placeholder="8291 (default)" style="width:170px;">
                        <span style="color:var(--text-secondary);">Kosongkan untuk menggunakan default</span>
                        
                    </div>
                </div>
                <div class="full" style="font-size:.84rem;display:grid;gap:.55rem;">
                    <label style="display:flex;align-items:center;gap:.55rem;">
                        <input id="vpn_tpl_api_enabled" type="checkbox" checked>
                        <span>Create/aktifkan port forwarding API Mikrotik</span>
                    </label>
                    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                        <input class="form-input" id="vpn_tpl_api_dest_port" type="number" min="1" max="65535" placeholder="8728 (default)" style="width:170px;">
                        <span style="color:var(--text-secondary);">Kosongkan untuk menggunakan default</span>
                        
                    </div>
                </div>
            </div>
            <div class="vpn-modal-footer">
                <button class="btn btn-secondary" type="button" onclick="vpnCloseModal('modal-create-user')">Batal</button>
                <button class="btn btn-primary" type="button" id="btn-do-create-user" onclick="vpnDoCreateUser()">Buat User</button>
            </div>
        </div>
    </div>

    <!-- Modal: Mikrotik Install Script -->
    <div class="vpn-modal-backdrop" id="modal-install-script" onclick="vpnBackdropClose(event,'modal-install-script')">
        <div class="vpn-modal" style="max-width:680px;">
            <div class="vpn-modal-title">Script Install VPN Mikrotik</div>
            <div style="font-size:.82rem;color:var(--text-secondary);margin-bottom:.55rem;">Interface otomatis: <strong>VPN-REMOTE</strong></div>
            <textarea id="mikrotik-install-script" class="form-input" style="min-height:180px;font-family:Consolas,monospace;font-size:.8rem;line-height:1.45;resize:vertical;" readonly></textarea>
            <div id="mikrotik-pf-summary" style="margin-top:.6rem;font-size:.8rem;color:var(--text-secondary);"></div>
            <div class="vpn-modal-footer">
                <button class="btn btn-secondary" type="button" onclick="vpnCloseModal('modal-install-script')">Tutup</button>
                <button class="btn btn-primary" type="button" onclick="vpnCopyInstallScript()">Copy Script</button>
            </div>
        </div>
    </div>

    <!-- Modal: Create Port Forwarding -->
    <div class="vpn-modal-backdrop" id="modal-create-pf" onclick="vpnBackdropClose(event,'modal-create-pf')">
        <div class="vpn-modal" style="max-width:520px;">
            <div class="vpn-modal-title">Buat Port Forwarding Baru</div>
            <div id="modal-create-pf-alert" class="admin-alert" style="display:none;margin-bottom:.75rem;"></div>
            <div class="vpn-modal-grid">
                <div><label class="form-label">Rule Name</label><input class="form-input" id="vpn_pf_name" type="text" placeholder="web-1" autocomplete="off"></div>
                <div><label class="form-label">Protocol</label><select class="form-select" id="vpn_pf_protocol" onchange="vpnPfPreviewScript()"><option value="tcp">tcp</option><option value="udp">udp</option></select></div>
                <div><label class="form-label">Akun VPN</label><select class="form-select" id="vpn_pf_vpn_user" onchange="vpnPfOnUserChange()"><option value="">-- pilih akun --</option></select></div>
                <div><label class="form-label">Listen Port</label><input class="form-input" id="vpn_pf_listen_port" type="number" min="1" max="65535" placeholder="3005" readonly></div>
                <div class="full"><label class="form-label">Destination Port <span style="font-size:.72rem;color:var(--text-secondary);font-weight:400;">(port layanan di client VPN)</span></label><input class="form-input" id="vpn_pf_destination_port" type="number" min="1" max="65535" placeholder="89"></div>
            </div>
            <div style="margin:.9rem 0 .4rem;font-size:.8rem;font-weight:600;color:var(--text-secondary);border-top:1px solid var(--border-color);padding-top:.75rem;">To Address <span style="font-weight:400;font-size:.75rem;">(opsional &mdash; kosongkan jika tidak diperlukan)</span></div>
            <div class="vpn-modal-grid">
                <div><label class="form-label">IP</label><input class="form-input" id="vpn_pf_to_ip" type="text" placeholder="192.168.1.100" autocomplete="off" oninput="vpnPfPreviewScript()"></div>
                <div><label class="form-label">Port</label><input class="form-input" id="vpn_pf_to_port" type="number" min="1" max="65535" placeholder="80" oninput="vpnPfPreviewScript()"></div>
                <div class="full"><label class="form-label">Protocol NAT</label><select class="form-select" id="vpn_pf_to_protocol" onchange="vpnPfPreviewScript()"><option value="tcp">tcp</option><option value="udp">udp</option></select></div>
            </div>
            <div id="vpn_pf_script_wrap" style="display:none;margin-top:.6rem;">
                <label class="form-label">Script dst-nat Mikrotik</label>
                <pre id="vpn_pf_script_out" style="background:var(--code-bg,rgba(0,0,0,.04));border:1px solid var(--border-color);border-radius:.4rem;padding:.6rem .75rem;font-size:.75rem;white-space:pre-wrap;word-break:break-all;margin:0;"></pre>
            </div>
            <div class="vpn-modal-footer">
                <button class="btn btn-secondary" type="button" onclick="vpnCloseModal('modal-create-pf')">Batal</button>
                <button class="btn btn-primary" type="button" id="btn-do-create-pf" onclick="vpnDoCreatePortForwarding()">Buat Rule</button>
            </div>
        </div>
    </div>

    <!-- Modal: Detail VPN User -->
    <div class="vpn-modal-backdrop" id="modal-user-detail" onclick="vpnBackdropClose(event,'modal-user-detail')">
        <div class="vpn-modal" style="max-width:500px;">
            <div class="vpn-modal-title">Detail VPN User</div>
            <div id="modal-user-detail-alert" class="admin-alert" style="display:none;margin-bottom:.75rem;"></div>
            <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
                <tr>
                    <td style="padding:.45rem .5rem;color:var(--text-secondary);white-space:nowrap;width:40%;">Username</td>
                    <td style="padding:.45rem .5rem;"><strong id="detail-username">&hellip;</strong></td>
                </tr>
                <tr style="background:rgba(0,0,0,.025);">
                    <td style="padding:.45rem .5rem;color:var(--text-secondary);white-space:nowrap;">Password</td>
                    <td style="padding:.45rem .5rem;">
                        <span id="detail-password-wrap" style="filter:blur(5px);transition:filter .2s;"><span id="detail-password">&hellip;</span></span>
                        &nbsp;<button id="detail-password-toggle" class="btn-action" data-shown="0" onclick="(function(btn){var wrap=document.getElementById('detail-password-wrap');if(btn.dataset.shown==='0'){wrap.style.filter='none';btn.textContent='Sembunyikan';btn.dataset.shown='1';}else{wrap.style.filter='blur(5px)';btn.textContent='Tampilkan';btn.dataset.shown='0';}})(this)" style="font-size:.7rem;">Tampilkan</button>
                    </td>
                </tr>
                <tr>
                    <td style="padding:.45rem .5rem;color:var(--text-secondary);">IP Static</td>
                    <td style="padding:.45rem .5rem;" id="detail-ip">&hellip;</td>
                </tr>
                <tr style="background:rgba(0,0,0,.025);">
                    <td style="padding:.45rem .5rem;color:var(--text-secondary);">Status</td>
                    <td style="padding:.45rem .5rem;" id="detail-status">&hellip;</td>
                </tr>
                <tr>
                    <td style="padding:.45rem .5rem;color:var(--text-secondary);">Dibuat</td>
                    <td style="padding:.45rem .5rem;" id="detail-created">&hellip;</td>
                </tr>
                <tr style="background:rgba(0,0,0,.025);">
                    <td style="padding:.45rem .5rem;color:var(--text-secondary);">Diperbarui</td>
                    <td style="padding:.45rem .5rem;" id="detail-updated">&hellip;</td>
                </tr>
            </table>
            <div class="vpn-modal-footer">
                <button class="btn btn-secondary" type="button" onclick="vpnCloseModal('modal-user-detail')">Tutup</button>
            </div>
        </div>
    </div>

    <!-- Modal: Konfirmasi Aksi User -->
    <div class="vpn-modal-backdrop" id="modal-confirm-user" onclick="vpnBackdropClose(event,'modal-confirm-user')">
        <div class="vpn-modal" style="max-width:400px;">
            <div class="vpn-modal-title" id="modal-confirm-user-title">Konfirmasi</div>
            <p class="vpn-modal-confirm-text" id="modal-confirm-user-text"></p>
            <div id="modal-confirm-user-alert" class="admin-alert" style="display:none;margin-bottom:.75rem;"></div>
            <div class="vpn-modal-footer">
                <button class="btn btn-secondary" type="button" onclick="vpnCloseModal('modal-confirm-user')">Batal</button>
                <button class="btn btn-primary" type="button" id="btn-do-confirm-user" onclick="vpnDoConfirmUser()">Konfirmasi</button>
            </div>
        </div>
    </div>

    <!-- Modal: Konfirmasi Delete Port Forwarding -->
    <div class="vpn-modal-backdrop" id="modal-confirm-pf" onclick="vpnBackdropClose(event,'modal-confirm-pf')">
        <div class="vpn-modal" style="max-width:400px;">
            <div class="vpn-modal-title">Hapus Port Forwarding</div>
            <p class="vpn-modal-confirm-text">Hapus rule <strong id="modal-confirm-pf-name"></strong>? Aksi ini tidak dapat dibatalkan.</p>
            <div id="modal-confirm-pf-alert" class="admin-alert" style="display:none;margin-bottom:.75rem;"></div>
            <div class="vpn-modal-footer">
                <button class="btn btn-secondary" type="button" onclick="vpnCloseModal('modal-confirm-pf')">Batal</button>
                <button class="btn btn-primary" style="background:var(--danger);border-color:var(--danger);" type="button" id="btn-do-delete-pf" onclick="vpnDoDeletePortForwarding()">Hapus</button>
            </div>
        </div>
    </div>

    <!-- Modal: Detail Port Forwarding -->
    <div class="vpn-modal-backdrop" id="modal-pf-detail" onclick="vpnBackdropClose(event,'modal-pf-detail')">
        <div class="vpn-modal" style="max-width:500px;">
            <div class="vpn-modal-title">Detail Port Forwarding</div>
            <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
                <tr>
                    <td style="padding:.45rem .5rem;color:var(--text-secondary);white-space:nowrap;width:40%;">Rule Name</td>
                    <td style="padding:.45rem .5rem;"><strong id="pf-detail-name">&hellip;</strong></td>
                </tr>
                <tr style="background:rgba(0,0,0,.025);">
                    <td style="padding:.45rem .5rem;color:var(--text-secondary);">Owner</td>
                    <td style="padding:.45rem .5rem;" id="pf-detail-owner">&hellip;</td>
                </tr>
                <tr>
                    <td style="padding:.45rem .5rem;color:var(--text-secondary);">IP Remote</td>
                    <td style="padding:.45rem .5rem;" id="pf-detail-ip-remote">&hellip;</td>
                </tr>
                <tr style="background:rgba(0,0,0,.025);">
                    <td style="padding:.45rem .5rem;color:var(--text-secondary);">Tanggal Dibuat</td>
                    <td style="padding:.45rem .5rem;" id="pf-detail-created">&hellip;</td>
                </tr>
            </table>
            <div class="vpn-modal-footer">
                <button class="btn btn-secondary" type="button" onclick="vpnCloseModal('modal-pf-detail')">Tutup</button>
            </div>
        </div>
    </div>

    <footer class="footer"><p>&copy; 2026 TEAM <?php echo htmlspecialchars(webCompanyName(), ENT_QUOTES, 'UTF-8'); ?></p></footer>
</div>

<script src="../templatemo-daynight-script.js?v=<?php echo (int) (file_exists(__DIR__ . '/../templatemo-daynight-script.js') ? filemtime(__DIR__ . '/../templatemo-daynight-script.js') : time()); ?>"></script>
<script src="../vpn-management.js?v=<?php echo (int) (file_exists(__DIR__ . '/../vpn-management.js') ? filemtime(__DIR__ . '/../vpn-management.js') : time()); ?>"></script>

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
