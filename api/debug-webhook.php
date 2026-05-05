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

require_once __DIR__ . '/db.php';

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
<p>Generated: <?= date('Y-m-d H:i:s') ?></p>

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
