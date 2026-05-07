<?php
session_start();
if (empty($_SESSION['logged_in'])) {
    header('Location: ../login.php');
    exit();
}

$sessionRoles = $_SESSION['roles'] ?? [];
$isAdmin = in_array('admin', $sessionRoles, true) || in_array('super_admin', $sessionRoles, true);
if ($isAdmin) {
    header('Location: ../admin/index.php');
    exit();
}

$sessionUserName  = $_SESSION['full_name'] ?? 'User';
$sessionUserEmail = $_SESSION['email'] ?? '';

// Pre-select plan dari query string (opsional)
$preselectedPlanId = (int) ($_GET['plan_id'] ?? 0);

require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../system/seo.php';

// Ambil daftar metode pembayaran aktif
$paymentMethods = [];
$paymentMethodLabels = [
    'BC' => 'BCA Virtual Account',
    'M2' => 'Mandiri Virtual Account',
    'VA' => 'Maybank Virtual Account',
    'I1' => 'BNI Virtual Account',
    'B1' => 'CIMB Niaga Virtual Account',
    'BT' => 'Permata Virtual Account',
    'A1' => 'ATM Bersama',
    'BR' => 'BRIVA',
    'IR' => 'Indomaret',
    'FT' => 'Retail (Pegadaian/ALFA/Pos)',
    'OV' => 'OVO',
    'DA' => 'DANA',
    'SP' => 'QRIS ShopeePay',
    'NQ' => 'QRIS Nobu',
    'GQ' => 'QRIS Gudang Voucher',
    'SQ' => 'QRIS Nusapay',
    'VC' => 'Credit Card',
    'JP' => 'Jenius Pay',
    'DN' => 'Indodana Paylater',
    'AT' => 'ATOME',
    'T1' => 'Tokopedia Card',
    'T2' => 'Tokopedia E-Wallet',
    'T3' => 'Tokopedia Others',
];

try {
    $pgConfig = getPaymentGatewayConfig(getDB());
    $paymentMethods = is_array($pgConfig['enabled_payment_methods'] ?? null) ? $pgConfig['enabled_payment_methods'] : ['BC'];
} catch (Throwable) {
    $paymentMethods = ['BC'];
}

if (empty($paymentMethods)) {
    $paymentMethods = ['BC'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php seoRenderHeadTags('Upgrade Paket - ' . webCompanyName()); ?>
    <script>
        window.PRESELECTED_PLAN_ID = <?php echo $preselectedPlanId; ?>;
        window.PAYMENT_METHODS = <?php echo json_encode($paymentMethods, JSON_UNESCAPED_UNICODE); ?>;
        window.PAYMENT_METHOD_LABELS = <?php echo json_encode($paymentMethodLabels, JSON_UNESCAPED_UNICODE); ?>;
        window.DAYNIGHT_SESSION = <?php echo json_encode([
            'fullName' => $sessionUserName,
            'email'    => $sessionUserEmail,
            'role'     => 'user',
            'roles'    => $sessionRoles,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        localStorage.setItem('daynight-user-name', window.DAYNIGHT_SESSION.fullName || 'User');
        localStorage.setItem('daynight-user-email', window.DAYNIGHT_SESSION.email || '');
        localStorage.setItem('daynight-user-role', 'user');
        localStorage.setItem('daynight-user-roles', JSON.stringify(window.DAYNIGHT_SESSION.roles || []));
        if (localStorage.getItem('daynight-theme') === 'carbon') {
            document.documentElement.classList.add('carbon');
        }
    </script>
    <link rel="stylesheet" href="../templatemo-daynight-style.css?v=<?php echo (int)(file_exists(__DIR__ . '/../templatemo-daynight-style.css') ? filemtime(__DIR__ . '/../templatemo-daynight-style.css') : time()); ?>">
    <style>
        .plan-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:1rem; margin-bottom:1.5rem; }
        .plan-card {
            border: 2px solid var(--border-color);
            border-radius: 14px;
            padding: 1.25rem;
            background: var(--bg-surface);
            cursor: pointer;
            transition: border-color .15s, background .15s;
            display: flex;
            flex-direction: column;
            gap: .5rem;
        }
        .plan-card:hover { border-color: var(--accent); }
        .plan-card.selected {
            border-color: var(--accent);
            background: var(--accent-light, rgba(56,189,248,.07));
        }
        .plan-card.disabled-plan { opacity:.5; cursor:not-allowed; }

        .sub-badge { display:inline-block; padding:.25rem .75rem; border-radius:999px; font-size:.75rem; font-weight:600; letter-spacing:.03em; text-transform:uppercase; }
        .sub-badge.free    { background:rgba(100,116,139,.15); color:var(--text-secondary); }
        .sub-badge.basic   { background:rgba(59,130,246,.15);  color:#3b82f6; }
        .sub-badge.premium { background:rgba(168,85,247,.15);  color:#a855f7; }

        .order-summary {
            border:1px solid var(--border-color);
            border-radius:14px;
            padding:1.25rem 1.5rem;
            background:var(--bg-surface);
            font-size:.875rem;
        }
        .order-row { display:flex; justify-content:space-between; padding:.4rem 0; }
        .order-row.total {
            border-top: 1px solid var(--border-color);
            margin-top:.5rem;
            padding-top:.75rem;
            font-weight:700;
            font-size:1rem;
        }
        .order-row .label { color:var(--text-secondary); }

        .form-label { display:block; font-size:.8125rem; margin-bottom:.35rem; color:var(--text-secondary); }
        .form-input  { width:100%; border:1px solid var(--border-color); border-radius:10px; background:var(--bg-surface); color:var(--text-primary); padding:.65rem .75rem; font-size:.875rem; }
        .form-input:focus { outline:none; border-color:var(--accent); }
        select.form-input { cursor:pointer; padding:.65rem .75rem; }
        select.form-input option { background:var(--bg-surface); color:var(--text-primary); padding:.5rem; }

        .admin-alert { padding:.625rem .75rem; border-radius:10px; font-size:.8125rem; }
        .admin-alert.error   { background:rgba(239,68,68,.08);   color:var(--danger);  border:1px solid rgba(239,68,68,.28); }
        .admin-alert.success { background:rgba(34,197,94,.08);   color:var(--success); border:1px solid rgba(34,197,94,.28); }
        .admin-alert.info    { background:rgba(56,189,248,.08);  color:var(--accent);  border:1px solid rgba(56,189,248,.28); }

        #pay-btn:disabled { opacity:.6; cursor:not-allowed; }
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
            <button class="mobile-menu-close" onclick="closeMobileMenu()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <nav class="mobile-menu-nav">
            <a href="index.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>Dashboard</a>
            <div class="mobile-group" id="mobile-group-produk">
                <div class="mobile-group-label" onclick="toggleMobileGroup('produk')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>Produk<svg class="mobile-group-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg></div>
                <div class="mobile-group-children">
                    <a href="wa-devices.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/></svg>WA Devices</a>
                    <a href="vpn-users.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>VPN Management</a>
                    <a href="proxy-routes.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M2 12h3M19 12h3M12 2v3M12 19v3"/></svg>Proxy Routes</a>
                </div>
            </div>
            <a href="subscription.php" class="active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>Subscription</a>
            <a href="#" onclick="openInfoModal('about');closeMobileMenu();return false;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="8"/><line x1="12" y1="12" x2="12" y2="16"/></svg>Tentang Kami</a>
            <a href="#" onclick="openInfoModal('contact');closeMobileMenu();return false;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2A19.72 19.72 0 0 1 3.1 5.18 2 2 0 0 1 5.09 3h3a2 2 0 0 1 2 1.72c.13 1 .37 1.97.72 2.9a2 2 0 0 1-.45 2.11L9.09 10.91A16 16 0 0 0 14 15.86l1.27-1.27a2 2 0 0 1 2.11-.45c.93.35 1.9.59 2.9.72A2 2 0 0 1 22 16.92z"/></svg>Kontak</a>
            <a href="#" onclick="openInfoModal('tc');closeMobileMenu();return false;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>T&amp;C</a>
        </nav>
        <div class="mobile-menu-footer">
            <a href="../api/logout.php" class="mobile-logout-btn">Logout</a>
            <div class="theme-toggle">
                <button class="theme-btn theme-btn-snow active" onclick="setTheme('snow')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/></svg></button>
                <button class="theme-btn theme-btn-carbon" onclick="setTheme('carbon')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg></button>
            </div>
        </div>
    </div>

    <div class="app-container">
        <nav class="top-nav">
            <div class="nav-container">
                <div class="nav-left">
                    <a href="index.php" class="logo">
                        <div class="logo-icon"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg></div>
                        <?php echo htmlspecialchars(webCompanyName(), ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                    <div class="nav-menu">
                        <div class="nav-item"><a href="index.php" class="nav-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>Dashboard</a></div>
                        <div class="nav-item has-dropdown" id="desktop-dropdown-produk">
                            <button class="nav-dropdown-btn" onclick="toggleDesktopDropdown('produk')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>Produk<svg class="nav-dropdown-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg></button>
                            <div class="nav-dropdown-panel">
                                <a href="wa-devices.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>WA Devices</a>
                                <a href="vpn-users.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>VPN Management</a>
                                <a href="proxy-routes.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><circle cx="12" cy="12" r="3"/><path d="M2 12h3M19 12h3M12 2v3M12 19v3"/></svg>Proxy Routes</a>
                            </div>
                        </div>
                        <div class="nav-item"><a href="subscription.php" class="nav-link active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>Subscription</a></div>
                        <div class="nav-item"><a href="#" class="nav-link" onclick="openInfoModal('about');return false;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="8"/><line x1="12" y1="12" x2="12" y2="16"/></svg>Tentang Kami</a></div>
                        <div class="nav-item"><a href="#" class="nav-link" onclick="openInfoModal('contact');return false;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2A19.72 19.72 0 0 1 3.1 5.18 2 2 0 0 1 5.09 3h3a2 2 0 0 1 2 1.72c.13 1 .37 1.97.72 2.9a2 2 0 0 1-.45 2.11L9.09 10.91A16 16 0 0 0 14 15.86l1.27-1.27a2 2 0 0 1 2.11-.45c.93.35 1.9.59 2.9.72A2 2 0 0 1 22 16.92z"/></svg>Kontak</a></div>
                        <div class="nav-item"><a href="#" class="nav-link" onclick="openInfoModal('tc');return false;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>T&amp;C</a></div>
                    </div>
                </div>
                <div class="nav-right">
                    <div class="theme-toggle">
                        <button class="theme-btn theme-btn-snow active" onclick="setTheme('snow')" title="Snow Edition"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/></svg></button>
                        <button class="theme-btn theme-btn-carbon" onclick="setTheme('carbon')" title="Carbon Edition"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg></button>
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
                    <a href="../api/logout.php" class="btn-logout" title="Logout">Logout</a>
                    <button class="mobile-menu-btn" onclick="toggleMobileMenu()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                    </button>
                </div>
            </div>
        </nav>

        <main class="main-content">
            <div class="page-header">
                <h1 class="greeting">Upgrade Paket</h1>
                <p class="greeting-sub">Pilih paket dan lakukan pembayaran</p>
            </div>

            <div id="page-alert" hidden class="admin-alert" style="margin-bottom:1rem;"></div>

            <div style="display:grid;grid-template-columns:1fr;gap:1.5rem;">

                <!-- Kiri / atas: pilih paket -->
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">Pilih Paket</h3>
                            <p class="card-subtitle">Klik paket yang ingin dibeli</p>
                        </div>
                    </div>
                    <div id="plan-grid-wrap" class="plan-grid">
                        <p style="color:var(--text-secondary);font-size:.875rem;grid-column:1/-1;">Memuat paket...</p>
                    </div>
                </div>

                <!-- Kanan / bawah: order summary + form -->
                <div class="card" id="order-wrap" hidden>
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">Ringkasan Pesanan</h3>
                        </div>
                    </div>

                    <div class="order-summary">
                        <div class="order-row"><span class="label">Paket</span><span id="ord-name">—</span></div>
                        <div class="order-row"><span class="label">Durasi</span><span id="ord-dur">—</span></div>
                        <div class="order-row"><span class="label">Harga</span><span id="ord-price">—</span></div>
                        <div class="order-row total"><span>Total</span><span id="ord-total">—</span></div>
                    </div>

                    <div style="margin-top:1.25rem;">
                        <label class="form-label">Nama Lengkap</label>
                        <input id="inp-name" type="text" class="form-input" placeholder="Nama sesuai identitas"
                               value="<?php echo htmlspecialchars($sessionUserName, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div style="margin-top:.75rem;">
                        <label class="form-label">Email</label>
                        <input id="inp-email" type="email" class="form-input" placeholder="email@anda.com"
                               value="<?php echo htmlspecialchars($sessionUserEmail, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div style="margin-top:.75rem;">
                        <label class="form-label">No. HP (untuk notifikasi pembayaran)</label>
                        <input id="inp-phone" type="tel" class="form-input" placeholder="08xxxxxxxxxx">
                    </div>

                    <div style="margin-top:.75rem;">
                        <label class="form-label">Metode Pembayaran</label>
                        <select id="inp-payment-method" class="form-input" style="cursor:pointer;">
                            <?php foreach ($paymentMethods as $method): ?>
                                <option value="<?php echo htmlspecialchars($method, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($paymentMethodLabels[$method] ?? $method, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="display:block;margin-top:.25rem;color:var(--text-secondary);font-size:.75rem;">Pilih metode pembayaran yang Anda inginkan</small>
                    </div>

                    <div style="margin-top:1rem;">
                        <div class="admin-alert info" style="font-size:.8125rem;">
                            Pembayaran diproses melalui Duitku. Anda akan diarahkan ke halaman pembayaran setelah mengklik tombol di bawah.
                        </div>
                    </div>

                    <button id="pay-btn" class="btn btn-primary" style="width:100%;margin-top:1rem;font-size:.9375rem;padding:.75rem;" onclick="submitOrder()">
                        Bayar Sekarang
                    </button>

                    <div id="pay-error" hidden style="margin-top:.75rem;padding:.65rem 1rem;border-radius:8px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.35);color:#ef4444;font-size:.8125rem;"></div>
                </div>

            </div>
        </main>
    </div>

    <script src="../templatemo-daynight-script.js?v=<?php echo (int)(file_exists(__DIR__ . '/../templatemo-daynight-script.js') ? filemtime(__DIR__ . '/../templatemo-daynight-script.js') : time()); ?>"></script>
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
    function toggleDesktopDropdown(key){var el=document.getElementById('desktop-dropdown-'+key);if(!el)return;var o=el.classList.contains('open');document.querySelectorAll('.nav-item.has-dropdown.open').forEach(function(d){d.classList.remove('open');});if(!o)el.classList.add('open');}
    document.addEventListener('click',function(e){if(!e.target.closest('.nav-item.has-dropdown')){document.querySelectorAll('.nav-item.has-dropdown.open').forEach(function(d){d.classList.remove('open');});}});
    function toggleMobileGroup(key){var el=document.getElementById('mobile-group-'+key);if(el)el.classList.toggle('open');}
    function openInfoModal(key){var ids={about:'modal-info-about',contact:'modal-info-contact',tc:'modal-info-tc'};var el=document.getElementById(ids[key]);if(el){el.style.display='flex';document.body.style.overflow='hidden';}}
    function closeInfoModal(key){var ids={about:'modal-info-about',contact:'modal-info-contact',tc:'modal-info-tc'};var el=document.getElementById(ids[key]);if(el){el.style.display='none';document.body.style.overflow='';}}
    </script>
    <script>
    let allPlans     = [];
    let selectedPlan = null;

    function fmt(n) { return new Intl.NumberFormat('id-ID').format(n); }
    function fmtRp(n) { return 'Rp ' + fmt(n); }
    function planPrice(plan) {
        const raw = plan?.price_idr ?? plan?.price ?? 0;
        const val = parseInt(raw, 10);
        return Number.isFinite(val) ? val : 0;
    }

    function showAlert(msg, type = 'error') {
        const el = document.getElementById('page-alert');
        el.className = `admin-alert ${type}`;
        el.textContent = msg;
        el.hidden = false;
        el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    function hideAlert() { document.getElementById('page-alert').hidden = true; }

    function selectPlan(planId) {
        selectedPlan = allPlans.find(p => p.id == planId) || null;
        if (!selectedPlan) return;

        document.querySelectorAll('.plan-card').forEach(c => c.classList.remove('selected'));
        const card = document.querySelector(`.plan-card[data-plan-id="${planId}"]`);
        if (card) card.classList.add('selected');

        const price = planPrice(selectedPlan);
        document.getElementById('ord-name').textContent  = selectedPlan.label || selectedPlan.name;
        document.getElementById('ord-dur').textContent   = selectedPlan.duration_days + ' hari';
        document.getElementById('ord-price').textContent = price === 0 ? 'Gratis' : fmtRp(price);
        document.getElementById('ord-total').textContent  = price === 0 ? 'Gratis' : fmtRp(price);
        document.getElementById('order-wrap').hidden = false;
        hideAlert();
    }

    async function loadPlans() {
        try {
            const res  = await fetch('../api/subscription.php?action=plans');
            const json = await res.json();
            if (!json.ok) throw new Error(json.error);
            allPlans = json.data;

            const wrap = document.getElementById('plan-grid-wrap');
            wrap.innerHTML = '';

            allPlans.forEach(plan => {
                const name  = (plan.name || '').toLowerCase();
                const price = planPrice(plan);
                const isFree = price === 0;

                const card = document.createElement('div');
                card.className = `plan-card${isFree ? ' disabled-plan' : ''}`;
                card.setAttribute('data-plan-id', plan.id);
                if (!isFree) card.onclick = () => selectPlan(plan.id);
                card.innerHTML = `
                    <div style="display:flex;align-items:center;justify-content:space-between;">
                        <span class="sub-badge ${name}">${plan.label || plan.name}</span>
                        <span style="font-size:.75rem;color:var(--text-secondary);">${plan.duration_days} hari</span>
                    </div>
                    <p style="font-size:1.25rem;font-weight:700;margin:.25rem 0;">${isFree ? 'Gratis' : fmtRp(price) + '/bln'}</p>
                    <ul style="font-size:.8125rem;color:var(--text-secondary);list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:.3rem;">
                        <li>✓ ${plan.vpn_limit} akun VPN</li>
                        <li>✓ ${plan.wa_device_limit} WA device</li>
                        <li>✓ ${plan.proxy_route_limit} proxy route</li>
                    </ul>
                    ${isFree ? '<p style="font-size:.75rem;color:var(--text-secondary);margin-top:.25rem;">Tidak perlu dibeli</p>' : ''}`;
                wrap.appendChild(card);
            });

            // Pre-select jika ada ?plan_id=
            const pre = window.PRESELECTED_PLAN_ID;
            if (pre) selectPlan(pre);

        } catch (e) {
            document.getElementById('plan-grid-wrap').innerHTML =
                `<p style="color:var(--danger);font-size:.875rem;grid-column:1/-1;">Gagal memuat paket: ${e.message}</p>`;
        }
    }

    async function submitOrder() {
        hideAlert();
        hidePayError();
        if (!selectedPlan) { showAlert('Pilih paket terlebih dahulu.'); return; }

        const name  = document.getElementById('inp-name').value.trim();
        const email = document.getElementById('inp-email').value.trim();
        const phone = document.getElementById('inp-phone').value.trim();
        const paymentMethod = document.getElementById('inp-payment-method').value.trim();

        if (!name)  { showPayError('Nama lengkap wajib diisi.'); return; }
        if (!email) { showPayError('Email wajib diisi.'); return; }
        if (!phone) { showPayError('No. HP wajib diisi.'); return; }

        const btn = document.getElementById('pay-btn');
        btn.disabled = true;
        btn.textContent = 'Memproses...';

        try {
            const res  = await fetch('../api/payment-controller.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action:  'create_invoice',
                    plan_id: selectedPlan.id,
                    name,
                    email,
                    phone,
                    payment_method: paymentMethod,
                }),
            });
            const raw = await res.text();
            let json;
            try {
                json = JSON.parse(raw);
            } catch (_err) {
                throw new Error('Server mengembalikan respon tidak valid. Cek konfigurasi payment gateway.');
            }

            if (!json.ok) throw new Error(json.error || 'Gagal membuat invoice.');

            // Redirect ke payment URL Duitku
            if (json.data?.payment_url) {
                window.location.href = json.data.payment_url;
            } else {
                showAlert('Invoice dibuat. Menunggu konfirmasi pembayaran.', 'success');
                btn.disabled = false;
                btn.textContent = 'Bayar Sekarang';
            }
        } catch (e) {
            showPayError(e.message);
            btn.disabled = false;
            btn.textContent = 'Bayar Sekarang';
        }
    }

    function showPayError(msg) {
        const el = document.getElementById('pay-error');
        el.textContent = msg;
        el.hidden = false;
        el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    function hidePayError() {
        const el = document.getElementById('pay-error');
        if (el) el.hidden = true;
    }

    loadPlans();
    </script>
</body>
</html>
