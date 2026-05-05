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

require_once __DIR__ . '/../system/seo.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php seoRenderHeadTags('Upgrade Paket - ' . webCompanyName()); ?>
    <script>
        window.PRESELECTED_PLAN_ID = <?php echo $preselectedPlanId; ?>;
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

        .admin-alert { padding:.625rem .75rem; border-radius:10px; font-size:.8125rem; }
        .admin-alert.error   { background:rgba(239,68,68,.08);   color:var(--danger);  border:1px solid rgba(239,68,68,.28); }
        .admin-alert.success { background:rgba(34,197,94,.08);   color:var(--success); border:1px solid rgba(34,197,94,.28); }
        .admin-alert.info    { background:rgba(56,189,248,.08);  color:var(--accent);  border:1px solid rgba(56,189,248,.28); }

        #pay-btn:disabled { opacity:.6; cursor:not-allowed; }
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
            <a href="wa-devices.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/></svg>WA Devices</a>
            <a href="vpn-users.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>VPN Management</a>
            <a href="proxy-routes.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M2 12h3M19 12h3M12 2v3M12 19v3"/></svg>Proxy Routes</a>
            <a href="subscription.php" class="active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>Subscription</a>
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
                        <div class="nav-item"><a href="wa-devices.php" class="nav-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>WA Devices</a></div>
                        <div class="nav-item"><a href="vpn-users.php" class="nav-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>VPN Management</a></div>
                        <div class="nav-item"><a href="proxy-routes.php" class="nav-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M2 12h3M19 12h3M12 2v3M12 19v3"/></svg>Proxy Routes</a></div>
                        <div class="nav-item"><a href="subscription.php" class="nav-link active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>Subscription</a></div>
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

                    <div style="margin-top:1rem;">
                        <div class="admin-alert info" style="font-size:.8125rem;">
                            Pembayaran diproses melalui iPaymu. Anda akan diarahkan ke halaman pembayaran setelah mengklik tombol di bawah.
                        </div>
                    </div>

                    <button id="pay-btn" class="btn btn-primary" style="width:100%;margin-top:1rem;font-size:.9375rem;padding:.75rem;" onclick="submitOrder()">
                        Bayar Sekarang
                    </button>
                </div>

            </div>
        </main>
    </div>

    <script src="../templatemo-daynight-script.js?v=<?php echo (int)(file_exists(__DIR__ . '/../templatemo-daynight-script.js') ? filemtime(__DIR__ . '/../templatemo-daynight-script.js') : time()); ?>"></script>
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
        if (!selectedPlan) { showAlert('Pilih paket terlebih dahulu.'); return; }

        const name  = document.getElementById('inp-name').value.trim();
        const email = document.getElementById('inp-email').value.trim();
        const phone = document.getElementById('inp-phone').value.trim();

        if (!name)  { showAlert('Nama lengkap wajib diisi.'); return; }
        if (!email) { showAlert('Email wajib diisi.'); return; }
        if (!phone) { showAlert('No. HP wajib diisi.'); return; }

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
                }),
            });
            const json = await res.json();

            if (!json.ok) throw new Error(json.error || 'Gagal membuat invoice.');

            // Redirect ke payment URL iPaymu
            if (json.data?.payment_url) {
                window.location.href = json.data.payment_url;
            } else {
                showAlert('Invoice dibuat. Menunggu konfirmasi pembayaran.', 'success');
                btn.disabled = false;
                btn.textContent = 'Bayar Sekarang';
            }
        } catch (e) {
            showAlert(e.message);
            btn.disabled = false;
            btn.textContent = 'Bayar Sekarang';
        }
    }

    loadPlans();
    </script>
</body>
</html>
