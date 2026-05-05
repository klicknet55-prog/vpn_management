<?php
/**
 * Debug endpoint: lihat log callback + status payment/subscription
 * Akses: GET /api/debug-webhook.php?key=debug1234
 * HAPUS FILE INI SETELAH SELESAI DEBUG
 */

$secretKey = 'debug1234';
if (($_GET['key'] ?? '') !== $secretKey) {
    http_response_code(403);
    exit('Forbidden');
}

// Mode: show PHP error log
if (isset($_GET['log'])) {
    header('Content-Type: text/plain; charset=utf-8');
    $candidates = array_filter([
        ini_get('error_log'),
        '/var/log/apache2/error.log',
        '/var/log/apache/error.log',
        '/var/log/nginx/error.log',
        '/var/log/php_errors.log',
        '/tmp/php_errors.log',
    ]);
    $found = false;
    foreach ($candidates as $logFile) {
        if ($logFile && is_readable($logFile)) {
            $lines = file($logFile);
            $webhookLines = array_filter($lines, fn($l) => str_contains($l, '[webhook]') || str_contains($l, 'payment-webhook'));
            $last = array_slice(array_values($webhookLines), -80);
            echo "=== Log file: $logFile ===\n\n";
            echo implode('', $last) ?: '(tidak ada baris [webhook] di log ini)';
            $found = true;
            break;
        }
    }
    if (!$found) {
        echo "Tidak ada log file yang bisa dibaca.\n";
        echo "error_log setting: " . (ini_get('error_log') ?: '(kosong)') . "\n";
        echo "Kandidat dicoba:\n" . implode("\n", $candidates) . "\n\n";
        echo "Gunakan ?simulate=1 untuk test webhook langsung di browser.\n";
    }
    exit;
}

// Mode: fix ENUM status di payments table
if (isset($_GET['fix_enum'])) {
    header('Content-Type: text/plain; charset=utf-8');
    require_once __DIR__ . '/db.php';
    try {
        $db = getDB();
        // Cek ENUM saat ini
        $col = $db->query("SHOW COLUMNS FROM payments LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        echo "BEFORE: " . ($col['Type'] ?? 'unknown') . "\n";

        $db->exec("ALTER TABLE payments MODIFY COLUMN status ENUM('pending','paid','success','failed','expired') NOT NULL DEFAULT 'pending'");
        echo "ALTER TABLE OK\n";

        $col2 = $db->query("SHOW COLUMNS FROM payments LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        echo "AFTER: " . ($col2['Type'] ?? 'unknown') . "\n";
    } catch (Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
    exit;
}


if (isset($_GET['simulate'])) {
    header('Content-Type: text/plain; charset=utf-8');
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/config.php';

    $logs = [];
    $log = function(string $msg) use (&$logs) {
        $logs[] = '[' . date('H:i:s') . '] ' . $msg;
        echo '[' . date('H:i:s') . '] ' . $msg . "\n";
        flush();
    };

    $invoiceNumber = $_GET['invoice'] ?? 'INV-20260505-0001';
    $log("=== SIMULATE WEBHOOK FLOW ===");
    $log("Invoice: $invoiceNumber");

    try {
        $db = getDB();
        $log("DB connected OK");

        // 1. getPaymentGatewayConfig
        $pgCfg = getPaymentGatewayConfig($db);
        $log("merchant_code=" . $pgCfg['merchant_code'] . " api_key=" . substr($pgCfg['api_key'], 0, 6) . "...");

        // 2. Hitung expected signature untuk invoice ini
        $fakeAmount = '15000';
        $expectedSig = md5($pgCfg['merchant_code'] . $fakeAmount . $invoiceNumber . $pgCfg['api_key']);
        $log("expected_sig (amount=15000): $expectedSig");

        // 3. Cari payment
        require_once __DIR__ . '/../system/models/Payment.php';
        require_once __DIR__ . '/../system/models/Invoice.php';
        $paymentModel = new Payment($db);
        $invoiceModel = new Invoice($db);
        $payment = $paymentModel->findByInvoiceNumber($invoiceNumber);
        if ($payment) {
            $log("payment FOUND: id=" . $payment['id'] . " user_id=" . $payment['user_id'] . " plan_id=" . $payment['plan_id'] . " status=" . $payment['status']);
        } else {
            $invoiceRow = $invoiceModel->findByNumber($invoiceNumber);
            if ($invoiceRow) {
                $log("invoice found in invoices table: id=" . $invoiceRow['id'] . " payment_id=" . ($invoiceRow['payment_id'] ?? 'NULL'));
                if (!empty($invoiceRow['payment_id'])) {
                    $payment = $paymentModel->findById((int)$invoiceRow['payment_id']);
                    $log($payment ? "payment FOUND via invoice: id=" . $payment['id'] . " status=" . $payment['status'] : "payment NOT FOUND via invoice");
                }
            } else {
                $log("INVOICE NOT FOUND in payments or invoices table! Invoice: $invoiceNumber");
            }
        }

        if (!$payment) {
            $log("STOP: tidak bisa lanjut, payment tidak ditemukan.");
            exit;
        }

        if ($payment['status'] === 'paid') {
            $log("payment sudah PAID — tidak perlu update.");
        } else {
            $log("status saat ini: " . $payment['status'] . " → akan di-update ke 'paid'");
        }

        // 4. Cek plan
        require_once __DIR__ . '/../system/models/Plan.php';
        $planModel = new Plan($db);
        $plan = $planModel->findById((int)$payment['plan_id']);
        if ($plan) {
            $log("plan FOUND: id=" . $plan['id'] . " name=" . $plan['name'] . " duration=" . $plan['duration_days'] . " days");
        } else {
            $log("PLAN NOT FOUND: plan_id=" . $payment['plan_id'] . " — ini penyebab activate() return false!");
        }

        // 5. Cek user_subscriptions
        $subRow = $db->prepare('SELECT * FROM user_subscriptions WHERE user_id = ? LIMIT 1');
        $subRow->execute([(int)$payment['user_id']]);
        $sub = $subRow->fetch();
        if ($sub) {
            $log("subscription EXISTS: plan_id=" . $sub['plan_id'] . " is_active=" . $sub['is_active'] . " expires_at=" . $sub['expires_at']);
        } else {
            $log("subscription NOT FOUND untuk user_id=" . $payment['user_id']);
        }

        // 6. Test updateAfterPayment (dry-run: rollback setelah test)
        if (isset($_GET['full'])) {
            $log("--- DRY RUN updateAfterPayment (akan di-rollback) ---");
            $db->beginTransaction();
            try {
                $result = $paymentModel->updateAfterPayment((int)$payment['id'], [
                    'status'         => 'paid',
                    'transaction_id' => 'TEST-TXN',
                    'session_id'     => null,
                    'payment_method' => 'duitku',
                    'paid_at'        => date('Y-m-d H:i:s'),
                ]);
                $log("updateAfterPayment result=" . ($result ? 'OK' : 'FALSE'));
                $db->rollBack();
                $log("(rollback done - DB tidak berubah)");
            } catch (Throwable $e2) {
                $db->rollBack();
                $log("updateAfterPayment EXCEPTION: " . $e2->getMessage());
            }

            $log("--- DRY RUN activate ---");
            $db->beginTransaction();
            try {
                require_once __DIR__ . '/../system/models/UserSubscription.php';
                require_once __DIR__ . '/../system/models/UserFeaturesUsage.php';
                require_once __DIR__ . '/../system/services/SubscriptionService.php';
                $subSvc = new SubscriptionService($db);
                $activated = $subSvc->activate((int)$payment['user_id'], (int)$payment['plan_id']);
                $log("activate result=" . ($activated ? 'OK' : 'FALSE'));
                $db->rollBack();
                $log("(rollback done - DB tidak berubah)");
            } catch (Throwable $e3) {
                $db->rollBack();
                $log("activate EXCEPTION: " . $e3->getMessage());
            }
        } else {
            $log("Tambah &full=1 ke URL untuk test updateAfterPayment + activate (dry-run).");
        }

    } catch (Throwable $e) {
        $log("EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    }

    $log("=== DONE ===");
    exit;
}

header('Content-Type: text/html; charset=utf-8');

$db = getDB();

// ── 1. Payment Notifications (raw callback log) ──────────────────────────────
$notifs = $db->query(
    'SELECT id, payment_id, status_code, trx_id, raw_payload, created_at
     FROM payment_notifications ORDER BY id DESC LIMIT 10'
)->fetchAll(PDO::FETCH_ASSOC);

// ── 2. Payments ──────────────────────────────────────────────────────────────
$payments = $db->query(
    'SELECT id, user_id, plan_id, invoice_number, status, amount,
            payment_method, gateway_transaction_id, paid_at, created_at
     FROM payments ORDER BY id DESC LIMIT 10'
)->fetchAll(PDO::FETCH_ASSOC);

// ── 3. User Subscriptions ────────────────────────────────────────────────────
$subs = $db->query(
    'SELECT us.id, us.user_id, us.plan_id, p.name AS plan_name,
            us.is_active, us.started_at, us.expires_at, us.disabled_reason, us.updated_at
     FROM user_subscriptions us
     LEFT JOIN plans p ON p.id = us.plan_id
     ORDER BY us.updated_at DESC LIMIT 10'
)->fetchAll(PDO::FETCH_ASSOC);

function tbl(array $rows): string {
    if (empty($rows)) return '<p style="color:gray">— kosong —</p>';
    $cols = array_keys($rows[0]);
    $html = '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse:collapse;font-size:13px;word-break:break-all">';
    $html .= '<tr style="background:#333;color:#fff">';
    foreach ($cols as $c) $html .= '<th>' . htmlspecialchars($c) . '</th>';
    $html .= '</tr>';
    foreach ($rows as $i => $row) {
        $bg = ($i % 2 === 0) ? '#f9f9f9' : '#fff';
        $html .= "<tr style=\"background:{$bg}\">";
        foreach ($row as $k => $v) {
            $val = is_string($v) && strlen($v) > 300 ? substr($v, 0, 300) . '…' : $v;
            if ($k === 'status' || $k === 'is_active') {
                $color = match((string)$val) { 'paid','1' => 'green', 'pending' => 'orange', 'failed','expired','0' => 'red', default => 'inherit' };
                $html .= '<td style="color:' . $color . ';font-weight:bold">' . htmlspecialchars((string)($val ?? 'NULL')) . '</td>';
            } else {
                $html .= '<td>' . htmlspecialchars((string)($val ?? 'NULL')) . '</td>';
            }
        }
        $html .= '</tr>';
    }
    return $html . '</table>';
}
?>
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Webhook Debug</title>
<style>body{font-family:monospace;margin:20px;background:#fafafa} h2{margin-top:30px;color:#333} .box{background:#fff;border:1px solid #ddd;padding:15px;border-radius:6px;overflow-x:auto}</style>
</head>
<body>
<h1 style="color:#c00">⚠ DEBUG PAGE — Hapus setelah selesai!</h1>
<p>Generated: <?= date('Y-m-d H:i:s') ?> 
| <a href="?key=debug1234&log=1" target="_blank">📋 Lihat Error Log</a>
| <a href="?key=debug1234&simulate=1&invoice=INV-20260505-0001" target="_blank">🔬 Simulate Flow</a>
</p>

<h2>1. Payment Notifications (10 terbaru)</h2>
<div class="box"><?= tbl($notifs) ?></div>

<h2>2. Payments (10 terbaru)</h2>
<div class="box"><?= tbl($payments) ?></div>

<h2>3. User Subscriptions (10 terbaru)</h2>
<div class="box"><?= tbl($subs) ?></div>

<h2>4. Raw Payload Callback Terakhir</h2>
<div class="box">
<?php if ($notifs): ?>
<pre style="background:#111;color:#0f0;padding:15px;border-radius:4px;overflow-x:auto"><?php
    $last = $notifs[0];
    $raw  = $last['raw_payload'];
    $decoded = json_decode($raw, true);
    echo htmlspecialchars($decoded ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $raw);
?></pre>
<p><b>status_code:</b> <?= htmlspecialchars($last['status_code'] ?? '-') ?>
   &nbsp;|&nbsp; <b>trx_id:</b> <?= htmlspecialchars($last['trx_id'] ?? '-') ?>
   &nbsp;|&nbsp; <b>payment_id:</b> <?= htmlspecialchars((string)($last['payment_id'] ?? 'NULL')) ?>
   &nbsp;|&nbsp; <b>created_at:</b> <?= htmlspecialchars($last['created_at'] ?? '-') ?>
</p>
<?php else: ?>
<p style="color:gray">Belum ada callback masuk.</p>
<?php endif ?>
</div>

</body>
</html>
