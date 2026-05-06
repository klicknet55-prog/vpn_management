<?php
session_start();

require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/invoice-link.php';
require_once __DIR__ . '/../system/models/Payment.php';

$invoiceNumber = trim((string) ($_GET['invoice'] ?? ''));
$exp = (int) ($_GET['exp'] ?? 0);
$sig = trim((string) ($_GET['sig'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));

$isValidLink = invoicePublicValidate($invoiceNumber, $exp, $sig);
$payment = null;
$latestExpiresAt = null;
$errorMessage = '';

if (!$isValidLink) {
    $errorMessage = 'Link invoice tidak valid atau sudah kedaluwarsa.';
} elseif ($invoiceNumber === '') {
    $errorMessage = 'Nomor invoice tidak ditemukan.';
} else {
    try {
        $db = getDB();
        $paymentModel = new Payment($db);
        $payment = $paymentModel->findByInvoiceNumber($invoiceNumber) ?: null;
        if (!$payment) {
            $errorMessage = 'Invoice tidak ditemukan.';
        } else {
            $subStmt = $db->prepare(
                'SELECT expires_at
                 FROM user_subscriptions
                 WHERE user_id = ?
                 ORDER BY expires_at DESC
                 LIMIT 1'
            );
            $subStmt->execute([(int) ($payment['user_id'] ?? 0)]);
            $subRow = $subStmt->fetch();
            if ($subRow && !empty($subRow['expires_at'])) {
                $latestExpiresAt = (string) $subRow['expires_at'];
            }
        }
    } catch (Throwable) {
        $errorMessage = 'Terjadi gangguan saat mengambil detail invoice.';
    }
}

function fmtDateId(?string $dateTime): string
{
    $dateTime = trim((string) $dateTime);
    if ($dateTime === '') {
        return '-';
    }

    $ts = strtotime($dateTime);
    if ($ts === false) {
        return '-';
    }

    $months = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];

    $day = date('d', $ts);
    $month = $months[(int) date('n', $ts)] ?? date('m', $ts);
    $year = date('Y', $ts);
    return $day . ' ' . $month . ' ' . $year;
}

function publicInvoiceStatusLabel(string $status): string
{
    return match ($status) {
        'paid' => 'Sudah Dibayar',
        'pending' => 'Menunggu Pembayaran',
        'expired' => 'Kedaluwarsa',
        'failed' => 'Gagal',
        default => 'Tidak Diketahui',
    };
}

function publicInvoiceStatusClass(string $status): string
{
    return match ($status) {
        'paid' => 'ok',
        'pending' => 'warn',
        'expired', 'failed' => 'bad',
        default => 'neutral',
    };
}

$displayStatus = $payment['status'] ?? (($status === 'success') ? 'pending' : 'failed');
$planLabel = $payment['plan_label'] ?? '-';
$amount = (int) ($payment['amount'] ?? 0);
$paidAt = fmtDateId((string) ($payment['paid_at'] ?? ''));
$expiredAt = fmtDateId($latestExpiresAt);
$methodRaw = (string) ($payment['payment_method'] ?? '');
$method = $methodRaw !== '' ? duitkuMethodLabel($methodRaw) : '-';

$sessionRoles = $_SESSION['roles'] ?? [];
$isLoggedIn = !empty($_SESSION['logged_in']);
$isAdmin = in_array('admin', $sessionRoles, true) || in_array('super_admin', $sessionRoles, true);
$dashboardUrl = $isLoggedIn
    ? ($isAdmin ? '../admin/index.php' : '../user/index.php')
    : '../login.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Invoice</title>
    <link rel="stylesheet" href="../templatemo-daynight-style.css?v=<?php echo (int)(file_exists(__DIR__ . '/../templatemo-daynight-style.css') ? filemtime(__DIR__ . '/../templatemo-daynight-style.css') : time()); ?>">
    <style>
        body { margin: 0; }
        .wrap {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 1rem;
            background: radial-gradient(circle at top right, rgba(59,130,246,.1), transparent 45%),
                        radial-gradient(circle at bottom left, rgba(16,185,129,.1), transparent 45%);
        }
        .card {
            width: 100%;
            max-width: 560px;
            border: 1px solid var(--border-color);
            border-radius: 16px;
            background: var(--bg-surface);
            padding: 1.25rem;
            box-shadow: 0 12px 40px rgba(0,0,0,.08);
        }
        .title { margin: 0 0 .25rem; font-size: 1.2rem; }
        .sub { margin: 0 0 1rem; color: var(--text-secondary); font-size: .9rem; }
        .badge {
            display: inline-block;
            padding: .35rem .6rem;
            border-radius: 999px;
            font-size: .8rem;
            font-weight: 600;
            margin-bottom: .9rem;
        }
        .badge.ok { background: rgba(34,197,94,.15); color: #15803d; }
        .badge.warn { background: rgba(245,158,11,.15); color: #b45309; }
        .badge.bad { background: rgba(239,68,68,.15); color: #b91c1c; }
        .badge.neutral { background: rgba(100,116,139,.15); color: #334155; }
        .row {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: .62rem 0;
            border-bottom: 1px solid var(--border-color);
            font-size: .9rem;
        }
        .row:last-child { border-bottom: none; }
        .k { color: var(--text-secondary); }
        .err {
            color: #b91c1c;
            background: rgba(239,68,68,.1);
            border: 1px solid rgba(239,68,68,.25);
            border-radius: 10px;
            padding: .75rem .9rem;
        }
        .actions {
            display: flex;
            gap: .6rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: .6rem .9rem;
            border-radius: 10px;
            text-decoration: none;
            font-size: .86rem;
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            background: var(--bg-page);
        }
        .btn.primary {
            background: var(--accent);
            color: #fff;
            border-color: transparent;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <section class="card">
            <h1 class="title">Detail Invoice</h1>
            <p class="sub">Informasi detail invoice dan status pembayaran Anda.</p>

            <?php if ($errorMessage !== ''): ?>
                <div class="err"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php else: ?>
                <div class="badge <?php echo htmlspecialchars(publicInvoiceStatusClass($displayStatus), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars(publicInvoiceStatusLabel($displayStatus), ENT_QUOTES, 'UTF-8'); ?>
                </div>

                <div class="row"><span class="k">No. Invoice</span><strong><?php echo htmlspecialchars((string) ($payment['invoice_number'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="row"><span class="k">Paket</span><span><?php echo htmlspecialchars((string) $planLabel, ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div class="row"><span class="k">Total</span><span>Rp <?php echo number_format($amount, 0, ',', '.'); ?></span></div>
                <div class="row"><span class="k">Metode</span><span><?php echo htmlspecialchars($method, ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div class="row"><span class="k">Tanggal Bayar</span><span><?php echo htmlspecialchars($paidAt, ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div class="row"><span class="k">Tanggal Expired</span><span><?php echo htmlspecialchars($expiredAt, ENT_QUOTES, 'UTF-8'); ?></span></div>

                <div class="actions">
                    <a class="btn primary" href="<?php echo htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8'); ?>">Ke Dashboard</a>
                    <?php if (!$isLoggedIn): ?>
                        <a class="btn" href="../login.php">Login</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</body>
</html>
