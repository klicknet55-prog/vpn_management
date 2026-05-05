<?php
/**
 * Cron: Expire Unpaid Invoices / Payments
 * ─────────────────────────────────────────────────────────────────────────────
 * Tandai payment & invoice yang masih pending tapi sudah melewati batas waktu.
 *
 * Crontab (setiap 30 menit):
 *   30 * * * * php /path/to/templatemo/api/cron-expire-payments.php >> /var/log/cron-payments.log 2>&1
 * ─────────────────────────────────────────────────────────────────────────────
 */

define('CRON_RUNNING', true);
$isCli = PHP_SAPI === 'cli';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');

    $envSecret = trim((string) env('CRON_SECRET', ''));
    $reqToken  = trim((string) ($_GET['token'] ?? ''));

    if ($envSecret === '' || $reqToken === '' || !hash_equals($envSecret, $reqToken)) {
        http_response_code(403);
        echo "403 Forbidden\n";
        exit;
    }
}

function payExpCronLog(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

try {
    $db = getDB();

    payExpCronLog('Mulai expire pending payments...');

    // Expire payment yang sudah melewati invoice_expired_at
    $stmt = $db->prepare(
        "UPDATE payments
         SET status = 'expired', updated_at = NOW()
         WHERE status = 'pending'
           AND invoice_expired_at IS NOT NULL
           AND invoice_expired_at <= NOW()"
    );
    $stmt->execute();
    $paymentCount = $stmt->rowCount();

    payExpCronLog("Payments di-expire: {$paymentCount}");

    // Expire invoice yang terkait
    $stmt2 = $db->prepare(
        "UPDATE invoices inv
         JOIN payments pay ON pay.invoice_number = inv.invoice_number
         SET inv.status = 'cancelled', inv.updated_at = NOW()
         WHERE pay.status = 'expired'
           AND inv.status NOT IN ('paid', 'cancelled')"
    );
    $stmt2->execute();
    $invoiceCount = $stmt2->rowCount();

    payExpCronLog("Invoices di-cancel: {$invoiceCount}");

    // Heartbeat
    try {
        $db->prepare(
            'INSERT INTO app_settings (config_key, config_value, updated_by, created_at, updated_at)
             VALUES ("cron.payment_expire.last_run", :val, NULL, NOW(), NOW())
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), updated_at = NOW()'
        )->execute(['val' => date('Y-m-d H:i:s')]);
    } catch (Throwable) { /* ignore */ }

    payExpCronLog('Selesai.');
    exit(0);

} catch (Throwable $e) {
    payExpCronLog('ERROR: ' . $e->getMessage());
    exit(1);
}
