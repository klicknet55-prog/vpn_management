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

$invoiceNumber = trim($_GET['invoice'] ?? '');
$status        = trim($_GET['status']  ?? '');  // 'success' | 'cancel'

require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../system/models/Payment.php';
require_once __DIR__ . '/../system/seo.php';

// Ambil detail payment jika ada invoice number
$payment = null;
if ($invoiceNumber !== '') {
    try {
        $db           = getDB();
        $paymentModel = new Payment($db);
        $payment      = $paymentModel->findByInvoiceNumber($invoiceNumber) ?: null;
    } catch (Throwable) {
        // ignore – tampilkan halaman tanpa detail
    }
}

$isCancelled = ($status === 'cancel');
$isSuccess   = ($status === 'success');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php seoRenderHeadTags(($isCancelled ? 'Pembayaran Dibatalkan' : 'Status Pembayaran') . ' - ' . webCompanyName()); ?>
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
        .result-card {
            max-width: 480px;
            margin: 3rem auto;
            border-radius: 16px;
            border: 1px solid var(--border-color);
            background: var(--bg-surface);
            padding: 2rem 2.25rem;
            text-align: center;
        }
        .result-icon {
            width: 64px; height: 64px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1.25rem;
        }
        .result-icon.success { background: rgba(34,197,94,.12); }
        .result-icon.pending { background: rgba(245,158,11,.12); }
        .result-icon.cancel  { background: rgba(239,68,68,.12); }

        .result-title { font-size: 1.25rem; font-weight: 700; margin: 0 0 .5rem; }
        .result-sub   { font-size: .875rem; color: var(--text-secondary); margin: 0 0 1.5rem; }

        .inv-row { display:flex; justify-content:space-between; font-size:.8125rem; padding:.4rem 0; border-bottom:1px solid var(--border-color); }
        .inv-row .label { color:var(--text-secondary); }
        .inv-row:last-child { border-bottom:none; }

        .btn-group { display:flex; gap:.75rem; justify-content:center; margin-top:1.5rem; flex-wrap:wrap; }
    </style>
</head>
<body>
    <?php seoRenderBodyOpenTags(); ?>

    <div class="app-container">
        <nav class="top-nav">
            <div class="nav-container">
                <div class="nav-left">
                    <a href="index.php" class="logo">
                        <div class="logo-icon"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg></div>
                        <?php echo htmlspecialchars(webCompanyName(), ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                </div>
                <div class="nav-right">
                    <a href="../api/logout.php" class="btn-logout" title="Logout">Logout</a>
                </div>
            </div>
        </nav>

        <main class="main-content">

            <?php if ($isCancelled): ?>
            <div class="result-card">
                <div class="result-icon cancel">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" width="32" height="32"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                </div>
                <h2 class="result-title" style="color:var(--danger);">Pembayaran Dibatalkan</h2>
                <p class="result-sub">Pembayaran untuk invoice <strong><?php echo htmlspecialchars($invoiceNumber, ENT_QUOTES, 'UTF-8'); ?></strong> dibatalkan.</p>
                <div class="btn-group">
                    <a href="upgrade.php" class="btn btn-primary">Coba Lagi</a>
                    <a href="subscription.php" class="btn btn-secondary">Lihat Subscription</a>
                </div>
            </div>

            <?php elseif ($isSuccess): ?>
            <div class="result-card" id="result-card">
                <!-- Status polling otomatis -->
                <div id="state-pending">
                    <div class="result-icon pending">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" width="32" height="32"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <h2 class="result-title">Menunggu Konfirmasi</h2>
                    <p class="result-sub">Pembayaran sedang diverifikasi. Halaman ini akan otomatis diperbarui...</p>
                    <p style="font-size:.8125rem;color:var(--text-secondary);">No. Invoice: <strong><?php echo htmlspecialchars($invoiceNumber, ENT_QUOTES, 'UTF-8'); ?></strong></p>
                    <div style="margin-top:1rem;"><svg class="spinner" viewBox="0 0 50 50" width="32" height="32" style="animation:spin 1s linear infinite;"><circle cx="25" cy="25" r="20" fill="none" stroke="var(--accent)" stroke-width="4" stroke-dasharray="80 20"/></svg></div>
                </div>
                <div id="state-paid" hidden>
                    <div class="result-icon success">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" width="32" height="32"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <h2 class="result-title" style="color:var(--success);">Pembayaran Berhasil!</h2>
                    <p class="result-sub">Subscription Anda sudah diaktifkan. Selamat menikmati layanan premium!</p>
                    <div id="inv-detail"></div>
                    <div class="btn-group">
                        <a href="subscription.php" class="btn btn-primary">Lihat Subscription</a>
                        <a href="index.php" class="btn btn-secondary">Dashboard</a>
                    </div>
                </div>
                <div id="state-failed" hidden>
                    <div class="result-icon cancel">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" width="32" height="32"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    </div>
                    <h2 class="result-title" style="color:var(--danger);">Pembayaran Gagal</h2>
                    <p class="result-sub">Mohon maaf, pembayaran tidak berhasil. Silakan coba kembali.</p>
                    <div class="btn-group">
                        <a href="upgrade.php" class="btn btn-primary">Coba Lagi</a>
                        <a href="subscription.php" class="btn btn-secondary">Lihat Subscription</a>
                    </div>
                </div>
            </div>

            <?php else: ?>
            <div class="result-card">
                <div class="result-icon pending">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" width="32" height="32"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                </div>
                <h2 class="result-title">Status Pembayaran</h2>
                <p class="result-sub">Tidak ada informasi pembayaran yang tersedia.</p>
                <div class="btn-group">
                    <a href="subscription.php" class="btn btn-primary">Lihat Subscription</a>
                </div>
            </div>
            <?php endif; ?>

        </main>
    </div>

    <style>
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
    <script src="../templatemo-daynight-script.js?v=<?php echo (int)(file_exists(__DIR__ . '/../templatemo-daynight-script.js') ? filemtime(__DIR__ . '/../templatemo-daynight-script.js') : time()); ?>"></script>
    <?php if ($isSuccess && $invoiceNumber !== ''): ?>
    <script>
    (function () {
        const INVOICE = <?php echo json_encode($invoiceNumber); ?>;
        let attempts = 0;
        const MAX    = 20;   // ~2 menit max polling
        const DELAY  = 6000; // 6 detik

        function fmt(n) { return new Intl.NumberFormat('id-ID').format(n); }
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

        function showState(state) {
            ['pending','paid','failed'].forEach(s => {
                document.getElementById('state-' + s).hidden = (s !== state);
            });
        }

        async function poll() {
            attempts++;
            try {
                const res  = await fetch('../api/payment-controller.php?action=history', { credentials: 'same-origin' });
                const json = await res.json();
                if (!json.ok) { scheduleNext(); return; }

                const payments = json.data || [];
                const p = payments.find(x => x.invoice_number === INVOICE);

                if (!p) { scheduleNext(); return; }

                if (p.status === 'paid') {
                    document.getElementById('inv-detail').innerHTML = `
                        <div style="text-align:left;margin:1rem 0;border:1px solid var(--border-color);border-radius:10px;padding:.875rem 1rem;">
                            <div class="inv-row"><span class="label">No. Invoice</span><strong>${p.invoice_number}</strong></div>
                            <div class="inv-row"><span class="label">Paket</span><span>${p.plan_label || '—'}</span></div>
                            <div class="inv-row"><span class="label">Total Dibayar</span><span>Rp ${fmt(p.amount)}</span></div>
                            <div class="inv-row"><span class="label">Metode</span><span>${p.payment_method_label || p.payment_method || '—'}</span></div>
                            <div class="inv-row"><span class="label">Tanggal</span><span>${fmtDateId(p.paid_at)}</span></div>
                        </div>`;
                    showState('paid');
                    return;
                }

                if (['failed','expired'].includes(p.status)) {
                    showState('failed');
                    return;
                }

                scheduleNext();
            } catch {
                scheduleNext();
            }
        }

        function scheduleNext() {
            if (attempts < MAX) {
                setTimeout(poll, DELAY);
            } else {
                // Timeout — minta user refresh manual
                document.querySelector('#state-pending .result-sub').textContent =
                    'Mohon tunggu beberapa menit, lalu refresh halaman ini untuk melihat status terbaru.';
            }
        }

        // Mulai polling setelah 3 detik (beri waktu webhook tiba)
        setTimeout(poll, 3000);
    })();
    </script>
    <?php endif; ?>
</body>
</html>
