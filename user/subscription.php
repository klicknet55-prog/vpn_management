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

require_once __DIR__ . '/../system/seo.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php seoRenderHeadTags('Subscription - ' . webCompanyName()); ?>
    <script>
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
        /* ── Subscription cards ── */
        .sub-status-card {
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
            border: 1px solid var(--border-color);
            background: var(--bg-surface);
            margin-bottom: 1.5rem;
        }
        .sub-status-card.active  { border-color: var(--success); }
        .sub-status-card.warning { border-color: var(--warning, #f59e0b); }
        .sub-status-card.inactive { border-color: var(--danger); }

        .sub-badge {
            display: inline-block;
            padding: .25rem .75rem;
            border-radius: 999px;
            font-size: .75rem;
            font-weight: 600;
            letter-spacing: .03em;
            text-transform: uppercase;
        }
        .sub-badge.free    { background: rgba(100,116,139,.15); color: var(--text-secondary); }
        .sub-badge.basic   { background: rgba(59,130,246,.15);  color: #3b82f6; }
        .sub-badge.premium { background: rgba(168,85,247,.15);  color: #a855f7; }

        .limit-bar-wrap { margin-top: .35rem; }
        .limit-bar-track {
            height: 6px;
            border-radius: 3px;
            background: var(--border-color);
            overflow: hidden;
            margin-top: .35rem;
        }
        .limit-bar-fill {
            height: 100%;
            border-radius: 3px;
            background: var(--accent);
            transition: width .4s;
        }
        .limit-bar-fill.warn  { background: var(--warning, #f59e0b); }
        .limit-bar-fill.full  { background: var(--danger); }

        .limit-row {
            display: flex;
            justify-content: space-between;
            font-size: .8125rem;
            color: var(--text-secondary);
        }
        .limit-row strong { color: var(--text-primary); }

        .days-pill {
            font-size: .8125rem;
            padding: .2rem .6rem;
            border-radius: 999px;
            background: rgba(34,197,94,.12);
            color: var(--success);
            font-weight: 600;
        }
        .days-pill.expiring { background: rgba(245,158,11,.12); color: var(--warning,#f59e0b); }
        .days-pill.expired  { background: rgba(239,68,68,.12);  color: var(--danger); }

        .history-table { width:100%; border-collapse:collapse; font-size:.875rem; }
        .history-table th,
        .history-table td { text-align:left; padding:.6rem .5rem; border-bottom:1px solid var(--border-color); }
        .history-table th { font-size:.75rem; color:var(--text-secondary); text-transform:uppercase; letter-spacing:.03em; }
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
            <a href="wa-devices.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12" y2="18"/></svg>WA Devices</a>
            <a href="vpn-users.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>VPN Management</a>
            <a href="proxy-routes.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M2 12h3M19 12h3M12 2v3M12 19v3"/></svg>Proxy Routes</a>
            <a href="subscription.php" class="active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>Subscription</a>
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
                    <a href="../api/logout.php" class="btn-logout" title="Logout">Logout</a>
                    <button class="mobile-menu-btn" onclick="toggleMobileMenu()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                    </button>
                </div>
            </div>
        </nav>

        <main class="main-content">
            <div class="page-header">
                <h1 class="greeting">Subscription</h1>
                <p class="greeting-sub">Status dan penggunaan paket langganan Anda</p>
            </div>

            <!-- Status aktif -->
            <div id="sub-status-wrap">
                <div class="sub-status-card" id="sub-status-card">
                    <p style="color:var(--text-secondary);font-size:.875rem;">Memuat info subscription...</p>
                </div>
            </div>

            <!-- Usage bars -->
            <div class="card" id="sub-usage-card" hidden>
                <div class="card-header">
                    <div>
                        <h3 class="card-title">Penggunaan Fitur</h3>
                        <p class="card-subtitle">Kuota yang sudah digunakan bulan ini</p>
                    </div>
                    <a href="upgrade.php" class="btn btn-primary" id="upgrade-btn" style="font-size:.8125rem;">Upgrade Paket</a>
                </div>
                <div id="sub-usage-body" style="padding:.25rem 0;"></div>
            </div>

            <!-- Expired warning banner -->
            <div id="sub-expired-banner" hidden style="margin-bottom:1.25rem;">
                <div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.3);border-radius:12px;padding:1rem 1.25rem;display:flex;align-items:center;gap:.75rem;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="var(--danger)" stroke-width="2" width="20" height="20"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <div>
                        <p style="margin:0;font-size:.875rem;font-weight:600;color:var(--danger);">Subscription expired</p>
                        <p style="margin:.25rem 0 0;font-size:.8125rem;color:var(--text-secondary);">Fitur tambah VPN, WA device, dan proxy route tidak tersedia. Silakan upgrade untuk melanjutkan.</p>
                    </div>
                    <a href="upgrade.php" class="btn btn-primary" style="margin-left:auto;white-space:nowrap;font-size:.8125rem;">Upgrade Sekarang</a>
                </div>
            </div>

            <!-- Pilihan paket tersedia -->
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3 class="card-title">Paket Tersedia</h3>
                        <p class="card-subtitle">Pilih paket yang sesuai kebutuhan Anda</p>
                    </div>
                </div>
                <div id="plan-list-wrap" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem;padding:.5rem 0;">
                    <p style="color:var(--text-secondary);font-size:.875rem;grid-column:1/-1;">Memuat paket...</p>
                </div>
            </div>
        </main>
    </div>

    <script src="../templatemo-daynight-script.js?v=<?php echo (int)(file_exists(__DIR__ . '/../templatemo-daynight-script.js') ? filemtime(__DIR__ . '/../templatemo-daynight-script.js') : time()); ?>"></script>
    <script>
    // ── helpers ──────────────────────────────────────────────
    function fmt(n) { return new Intl.NumberFormat('id-ID').format(n); }
    function fmtRp(n) { return 'Rp ' + fmt(n); }
    function fmtDateId(value) {
        if (!value) return '—';
        const dt = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(dt.getTime())) return '—';
        return new Intl.DateTimeFormat('id-ID', {
            day: '2-digit',
            month: 'long',
            year: 'numeric'
        }).format(dt);
    }

    function daysPill(days, isActive) {
        if (!isActive) return '<span class="days-pill expired">Expired</span>';
        if (days <= 3) return `<span class="days-pill expiring">${days} hari lagi</span>`;
        return `<span class="days-pill">${days} hari lagi</span>`;
    }

    function usageBar(used, limit, label) {
        const pct = limit > 0 ? Math.min(100, Math.round(used / limit * 100)) : 0;
        const cls = pct >= 100 ? 'full' : pct >= 80 ? 'warn' : '';
        return `
        <div class="limit-bar-wrap" style="margin-bottom:.875rem;">
            <div class="limit-row">
                <strong>${label}</strong>
                <span>${used} / ${limit}</span>
            </div>
            <div class="limit-bar-track">
                <div class="limit-bar-fill ${cls}" style="width:${pct}%"></div>
            </div>
        </div>`;
    }

    // ── Load subscription info ────────────────────────────────
    async function loadSubInfo() {
        try {
            const res  = await fetch('../api/subscription.php?action=info');
            const json = await res.json();
            if (!json.ok) throw new Error(json.error);
            const d = json.data;

            const card = document.getElementById('sub-status-card');
            const statusClass = !d.is_active ? 'inactive' : d.days_remaining <= 3 ? 'warning' : 'active';

            card.className = `sub-status-card ${statusClass}`;

            if (!d.has_subscription) {
                card.innerHTML = `<p style="color:var(--text-secondary);">Tidak ada subscription aktif.</p>`;
                document.getElementById('sub-expired-banner').hidden = false;
                return;
            }

            const planName = (d.plan?.name || 'free').toLowerCase();
            card.innerHTML = `
                <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
                    <span class="sub-badge ${planName}">${d.plan?.label || d.plan?.name || 'Free'}</span>
                    ${daysPill(d.days_remaining, d.is_active)}
                    ${!d.is_active && d.disabled_reason ? `<span style="font-size:.75rem;color:var(--danger);">— ${d.disabled_reason}</span>` : ''}
                </div>
                <div style="margin-top:.875rem;display:flex;gap:1.5rem;flex-wrap:wrap;font-size:.8125rem;color:var(--text-secondary);">
                    <span>Mulai: <strong style="color:var(--text-primary);">${fmtDateId(d.started_at)}</strong></span>
                    <span>Berakhir: <strong style="color:var(--text-primary);">${fmtDateId(d.expires_at)}</strong></span>
                </div>`;

            if (!d.is_active) {
                document.getElementById('sub-expired-banner').hidden = false;
            }

            // Usage card
            const usageCard = document.getElementById('sub-usage-card');
            usageCard.hidden = false;

            if (planName !== 'free') {
                document.getElementById('upgrade-btn').textContent = 'Perpanjang / Upgrade';
            }

            const p = d.plan;
            const u = d.usage;
            document.getElementById('sub-usage-body').innerHTML =
                usageBar(u.vpn_count,         p.vpn_limit,         'Akun VPN') +
                usageBar(u.wa_device_count,    p.wa_device_limit,   'WA Device') +
                usageBar(u.proxy_route_count,  p.proxy_route_limit, 'Proxy Route');

        } catch (e) {
            document.getElementById('sub-status-card').innerHTML =
                `<p style="color:var(--danger);font-size:.875rem;">Gagal memuat subscription: ${e.message}</p>`;
        }
    }

    // ── Load plan list ─────────────────────────────────────────
    async function loadPlans() {
        try {
            const res  = await fetch('../api/subscription.php?action=plans');
            const json = await res.json();
            if (!json.ok) throw new Error(json.error);

            const wrap = document.getElementById('plan-list-wrap');
            wrap.innerHTML = '';

            json.data.forEach(plan => {
                const name = (plan.name || '').toLowerCase();
                const label = String(plan.label || plan.name || 'Paket');
                const price = parseInt(plan.price_idr ?? plan.price ?? 0, 10) || 0;
                const dur = (parseInt(plan.duration_days, 10) || 30) + ' hari';
                const isTrial = /trial/i.test(name) || /trial/i.test(label);
                const priceMain = price === 0 ? 'Rp 0' : fmtRp(price);
                const priceNote = price === 0 ? 'Paket gratis' : `per ${dur}`;

                const card = document.createElement('div');
                card.style.cssText = 'border:1px solid var(--border-color);border-radius:14px;padding:1.125rem 1.25rem;background:var(--bg-surface);display:flex;flex-direction:column;gap:.5rem;';
                card.innerHTML = `
                    <div style="display:flex;align-items:center;justify-content:space-between;">
                        <span class="sub-badge ${name}">${label}</span>
                        <span style="font-size:.8rem;color:var(--text-secondary);">${dur}</span>
                    </div>
                    <div>
                        <p style="font-size:1.25rem;font-weight:700;margin:.25rem 0 .1rem 0;">${priceMain}</p>
                        <p style="font-size:.75rem;color:var(--text-secondary);margin:0;">${priceNote}</p>
                    </div>
                    <ul style="font-size:.8125rem;color:var(--text-secondary);list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:.3rem;">
                        <li>✓ ${plan.vpn_limit} akun VPN</li>
                        <li>✓ ${plan.wa_device_limit} WA device</li>
                        <li>✓ ${plan.proxy_route_limit} proxy route</li>
                    </ul>
                    ${isTrial
                        ? ''
                        : (price > 0
                            ? `<a href="upgrade.php?plan_id=${plan.id}" class="btn btn-primary" style="margin-top:.5rem;text-align:center;font-size:.8125rem;">Pilih Paket</a>`
                            : `<span class="btn btn-secondary" style="margin-top:.5rem;text-align:center;font-size:.8125rem;opacity:.6;cursor:default;">Pilih Paket</span>`)}`;
                wrap.appendChild(card);
            });
        } catch (e) {
            document.getElementById('plan-list-wrap').innerHTML =
                `<p style="color:var(--danger);font-size:.875rem;grid-column:1/-1;">Gagal memuat paket: ${e.message}</p>`;
        }
    }

    loadSubInfo();
    loadPlans();
    </script>
</body>
</html>
