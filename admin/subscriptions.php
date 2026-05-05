<?php
session_start();
if (empty($_SESSION['logged_in'])) {
    header('Location: ../login.php');
    exit();
}

$sessionUserName    = $_SESSION['full_name']    ?? 'Admin';
$sessionUserEmail   = $_SESSION['email']        ?? '';
$sessionPhoneNumber = $_SESSION['phone_number'] ?? '';
$sessionRoles       = $_SESSION['roles']        ?? [];
$isAdmin = in_array('admin', $sessionRoles, true) || in_array('super_admin', $sessionRoles, true);
if (!$isAdmin) {
    header('Location: ../user/subscription.php');
    exit();
}

require_once __DIR__ . '/../system/seo.php';
$dnVer  = file_exists(__DIR__ . '/../templatemo-daynight-script.js') ? filemtime(__DIR__ . '/../templatemo-daynight-script.js') : time();
$cssVer = file_exists(__DIR__ . '/../templatemo-daynight-style.css') ? filemtime(__DIR__ . '/../templatemo-daynight-style.css') : time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php seoRenderHeadTags('Subscription Management - ' . webCompanyName()); ?>
    <link rel="stylesheet" href="../templatemo-daynight-style.css?v=<?php echo (int) $cssVer; ?>">
    <style>
        .badge { display:inline-flex;align-items:center;gap:4px;padding:2px 10px;border-radius:999px;font-size:.75rem;font-weight:600; }
        .badge-active   { background:#d1fae5;color:#065f46; }
        .badge-inactive { background:#fee2e2;color:#991b1b; }
        .badge-free     { background:#e0e7ff;color:#3730a3; }
        .badge-paid     { background:#fef9c3;color:#854d0e; }
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
            <a href="vpn-users.php">VPN Management</a>
            <a href="proxy-routes.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M2 12h3M19 12h3M12 2v3M12 19v3"/></svg>Proxy Routes</a>
            <a href="subscriptions.php" class="active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>Subscriptions</a>
            <a href="config.php">Config</a>
            <a href="audit-log.php">Audit Log</a>
        </nav>
        <div class="mobile-menu-footer">
            <a href="../api/logout.php" class="mobile-logout-btn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>Logout</a>
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
                        <div class="nav-item"><a href="vpn-users.php" class="nav-link">VPN Management</a></div>
                        <div class="nav-item"><a href="proxy-routes.php" class="nav-link">Proxy Routes</a></div>
                        <div class="nav-item"><a href="subscriptions.php" class="nav-link active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>Subscriptions</a></div>
                        <div class="nav-item"><a href="config.php" class="nav-link">Config</a></div>
                        <div class="nav-item"><a href="audit-log.php" class="nav-link">Audit Log</a></div>
                    </div>
                </div>
                <div class="nav-right">
                    <div class="theme-toggle">
                        <button class="theme-btn theme-btn-snow active" onclick="setTheme('snow')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/></svg></button>
                        <button class="theme-btn theme-btn-carbon" onclick="setTheme('carbon')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg></button>
                    </div>
                    <div class="account-menu-wrap" id="account-menu-wrap">
                        <button class="user-menu" onclick="toggleAccountDropdown()">
                            <div class="user-avatar"><?php echo strtoupper(substr($sessionUserName, 0, 1)); ?></div>
                            <span class="user-name" data-current-user><?php echo htmlspecialchars($sessionUserName, ENT_QUOTES, 'UTF-8'); ?></span>
                        </button>
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
            <div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
                <div>
                    <h1 class="greeting">Subscription Management</h1>
                    <p class="greeting-sub">Kelola dan pantau subscription seluruh pengguna.</p>
                </div>
                <div style="display:flex;gap:0.5rem;align-items:center;">
                    <input id="subSearch" type="search" placeholder="Cari user / email..."
                           style="padding:0.5rem 0.75rem;border:1px solid var(--border);border-radius:8px;font-size:0.875rem;background:var(--bg-surface);color:var(--text-primary);outline:none;min-width:220px;">
                    <button class="btn btn-secondary" onclick="loadData()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.5"/></svg>
                        Refresh
                    </button>
                </div>
            </div>

            <div class="card" style="overflow:hidden;padding:0;">
                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:0.875rem;">
                        <thead>
                            <tr style="background:var(--bg-alt);color:var(--text-secondary);font-size:0.75rem;text-transform:uppercase;letter-spacing:.05em;">
                                <th style="padding:10px 16px;text-align:left;">User</th>
                                <th style="padding:10px 16px;text-align:left;">Email</th>
                                <th style="padding:10px 16px;text-align:left;">Paket</th>
                                <th style="padding:10px 16px;text-align:left;">Status</th>
                                <th style="padding:10px 16px;text-align:left;">Mulai</th>
                                <th style="padding:10px 16px;text-align:left;">Berakhir</th>
                                <th style="padding:10px 16px;text-align:left;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="subTbody">
                            <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-secondary);">Memuat data...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div><!-- /app-container -->

    <!-- Modal: Manual Activate / Extend -->
    <div id="modalActivate" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;padding:1rem;">
        <div style="background:var(--bg-surface);border-radius:12px;box-shadow:var(--shadow-lg);width:100%;max-width:420px;padding:1.5rem;">
            <h2 style="font-size:1.125rem;font-weight:600;margin-bottom:1rem;">Aktifkan Subscription</h2>
            <form id="formActivate">
                <input type="hidden" id="activateUserId">
                <div style="margin-bottom:1rem;">
                    <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:4px;">User</label>
                    <input id="activateUserName" type="text" disabled style="width:100%;padding:0.5rem 0.75rem;border:1px solid var(--border);border-radius:8px;font-size:0.875rem;background:var(--bg-alt);color:var(--text-primary);box-sizing:border-box;">
                </div>
                <div style="margin-bottom:1rem;">
                    <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:4px;">Paket</label>
                    <select id="activatePlanId" style="width:100%;padding:0.5rem 0.75rem;border:1px solid var(--border);border-radius:8px;font-size:0.875rem;background:var(--bg-surface);color:var(--text-primary);box-sizing:border-box;"></select>
                </div>
                <div style="margin-bottom:1rem;">
                    <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:4px;">Durasi (hari)</label>
                    <input id="activateDays" type="number" min="1" value="30" style="width:100%;padding:0.5rem 0.75rem;border:1px solid var(--border);border-radius:8px;font-size:0.875rem;background:var(--bg-surface);color:var(--text-primary);box-sizing:border-box;">
                </div>
                <div id="activateError" style="display:none;color:#ef4444;font-size:0.875rem;margin-bottom:0.75rem;"></div>
                <div style="display:flex;justify-content:flex-end;gap:0.5rem;padding-top:0.5rem;">
                    <button type="button" onclick="closeModal()" class="btn btn-secondary">Batal</button>
                    <button type="submit" id="btnActivateSubmit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Konfirmasi Disable -->
    <div id="modalDisable" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;padding:1rem;">
        <div style="background:var(--bg-surface);border-radius:12px;box-shadow:var(--shadow-lg);width:100%;max-width:360px;padding:1.5rem;text-align:center;">
            <div style="font-size:2.5rem;margin-bottom:0.75rem;">⚠️</div>
            <h2 style="font-size:1.125rem;font-weight:600;margin-bottom:0.5rem;">Nonaktifkan Subscription?</h2>
            <p style="font-size:0.875rem;color:var(--text-secondary);margin-bottom:1rem;">User <strong id="disableUserName"></strong> tidak akan dapat menggunakan fitur premium.</p>
            <div style="display:flex;justify-content:center;gap:0.5rem;">
                <button onclick="closeModal()" class="btn btn-secondary">Batal</button>
                <button id="btnDisableConfirm" class="btn" style="background:#ef4444;color:#fff;">Ya, Nonaktifkan</button>
            </div>
        </div>
    </div>

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

    <script src="../templatemo-daynight-script.js?v=<?php echo (int) $dnVer; ?>"></script>
    <script>
    window.__currentUser = {
        fullName:    <?php echo json_encode($sessionUserName); ?>,
        email:       <?php echo json_encode($sessionUserEmail); ?>,
        phoneNumber: <?php echo json_encode($sessionPhoneNumber); ?>,
    };
    </script>
    <script>
    const API = '../api/admin-subscription.php';
    let allRows = [];
    let plans   = [];

    async function loadData() {
        const [subRes, planRes] = await Promise.all([
            fetch(API + '?action=list'),
            fetch(API + '?action=plans'),
        ]);
        const sub  = await subRes.json();
        const plan = await planRes.json();
        if (sub.ok)  allRows = sub.data || [];
        if (plan.ok) plans   = plan.data || [];

        const sel = document.getElementById('activatePlanId');
        sel.innerHTML = plans.map(p =>
            `<option value="${p.id}">${esc(p.label)} (${esc(p.name)})</option>`
        ).join('');

        renderTable(allRows);
    }

    function renderTable(rows) {
        const tbody = document.getElementById('subTbody');
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-secondary);">Tidak ada data</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map(r => {
            const active    = r.is_active == 1;
            const isFree    = r.plan_name === 'free';
            const badge     = active
                ? `<span class="badge badge-active">Aktif</span>`
                : `<span class="badge badge-inactive">Tidak Aktif</span>`;
            const planBadge = r.plan_label
                ? (isFree
                    ? `<span class="badge badge-free">${esc(r.plan_label)}</span>`
                    : `<span class="badge badge-paid">${esc(r.plan_label)}</span>`)
                : `<span style="color:var(--text-secondary)">–</span>`;
            const safeNameJs = esc(r.full_name ?? '').replace(/'/g, '&#39;');
            return `<tr style="border-bottom:1px solid var(--border);">
                <td style="padding:10px 16px;font-weight:500;">${esc(r.full_name ?? '-')}</td>
                <td style="padding:10px 16px;color:var(--text-secondary);">${esc(r.email ?? '-')}</td>
                <td style="padding:10px 16px;">${planBadge}</td>
                <td style="padding:10px 16px;">${badge}</td>
                <td style="padding:10px 16px;font-size:0.8125rem;color:var(--text-secondary);">${r.started_at ? r.started_at.slice(0,10) : '–'}</td>
                <td style="padding:10px 16px;font-size:0.8125rem;color:var(--text-secondary);">${r.expires_at ? r.expires_at.slice(0,10) : '–'}</td>
                <td style="padding:10px 16px;">
                    <div style="display:flex;gap:0.375rem;flex-wrap:wrap;">
                        <button onclick="openActivate(${r.user_id},'${safeNameJs}',${r.plan_id ?? 0})"
                                class="btn btn-primary" style="padding:4px 12px;font-size:0.8rem;">Aktifkan</button>
                        ${active ? `<button onclick="openDisable(${r.user_id},'${safeNameJs}')"
                                class="btn" style="padding:4px 12px;font-size:0.8rem;background:#ef4444;color:#fff;">Nonaktifkan</button>` : ''}
                    </div>
                </td>
            </tr>`;
        }).join('');
    }

    document.getElementById('subSearch').addEventListener('input', function() {
        const q = this.value.toLowerCase().trim();
        const filtered = q ? allRows.filter(r =>
            ((r.full_name ?? '') + (r.email ?? '')).toLowerCase().includes(q)
        ) : allRows;
        renderTable(filtered);
    });

    function openActivate(userId, userName, currentPlanId) {
        document.getElementById('activateUserId').value   = userId;
        document.getElementById('activateUserName').value = userName;
        if (currentPlanId) document.getElementById('activatePlanId').value = currentPlanId;
        document.getElementById('activateError').style.display = 'none';
        document.getElementById('modalActivate').style.display = 'flex';
    }

    document.getElementById('formActivate').addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = document.getElementById('btnActivateSubmit');
        btn.disabled = true; btn.textContent = 'Menyimpan...';

        const body = {
            user_id:       +document.getElementById('activateUserId').value,
            plan_id:       +document.getElementById('activatePlanId').value,
            duration_days: +document.getElementById('activateDays').value,
        };

        try {
            const res  = await fetch(API + '?action=manual_activate', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(body),
            });
            const raw = await res.text();
            let data = null;
            try {
                data = JSON.parse(raw);
            } catch (parseErr) {
                throw new Error('Response API tidak valid: ' + raw.slice(0, 180));
            }

            if (data.ok) {
                closeModal();
                await loadData();
            } else {
                throw new Error(data.message || 'Gagal menyimpan.');
            }
        } catch (err) {
            const errBox = document.getElementById('activateError');
            errBox.textContent = err && err.message ? err.message : 'Terjadi kesalahan saat menyimpan.';
            errBox.style.display = 'block';
        } finally {
            btn.disabled = false;
            btn.textContent = 'Simpan';
        }
    });

    let disableTargetId = 0;
    function openDisable(userId, userName) {
        disableTargetId = userId;
        document.getElementById('disableUserName').textContent = userName;
        document.getElementById('modalDisable').style.display = 'flex';
    }

    document.getElementById('btnDisableConfirm').addEventListener('click', async function() {
        this.disabled = true; this.textContent = 'Memproses...';
        try {
            const res  = await fetch(API + '?action=manual_disable', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({user_id: disableTargetId}),
            });
            const raw = await res.text();
            let data = null;
            try {
                data = JSON.parse(raw);
            } catch (parseErr) {
                throw new Error('Response API tidak valid: ' + raw.slice(0, 180));
            }
            if (data.ok) {
                closeModal();
                await loadData();
            }
        } catch (err) {
            alert(err && err.message ? err.message : 'Gagal menonaktifkan subscription.');
        } finally {
            this.disabled = false;
            this.textContent = 'Ya, Nonaktifkan';
        }
    });

    function closeModal() {
        document.getElementById('modalActivate').style.display = 'none';
        document.getElementById('modalDisable').style.display  = 'none';
    }

    function esc(s) {
        return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    loadData();
    </script>
</body>
</html>
