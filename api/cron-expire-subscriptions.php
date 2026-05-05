<?php
/**
 * Cron: Block Expired Subscriptions
 * ─────────────────────────────────────────────────────────────────────────────
 * Tandai subscription yang sudah melewati expires_at sebagai tidak aktif,
 * dan kirim email notifikasi jika dikonfigurasi.
 *
 * Jalankan via CLI (direkomendasikan):
 *   php /path/to/templatemo/api/cron-expire-subscriptions.php
 *
 * Atau via HTTP dengan token:
 *   GET /api/cron-expire-subscriptions.php?token=<CRON_SECRET>
 *
 * Crontab (sekali per jam):
 *   0 * * * * php /path/to/templatemo/api/cron-expire-subscriptions.php >> /var/log/cron-subscriptions.log 2>&1
 *
 * Variabel .env yang dibutuhkan:
 *   CRON_SECRET=<random-strong-secret>
 *   MAIL_FROM=noreply@yourdomain.com   (opsional, untuk notifikasi email)
 *   MAIL_FROM_NAME=YourApp             (opsional)
 * ─────────────────────────────────────────────────────────────────────────────
 */

define('CRON_RUNNING', true);
$isCli = PHP_SAPI === 'cli';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../system/services/SubscriptionService.php';
require_once __DIR__ . '/../system/services/NotificationService.php';

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

function subCronLog(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

try {
    $db      = getDB();
    $service = new SubscriptionService($db);
    $notif   = new NotificationService();

    subCronLog('Mulai cek expired subscriptions...');

    // Ambil daftar yang akan diblock sebelum di-disable (untuk email + WA)
    $expiredRows = $db->query(
        "SELECT us.user_id, us.expires_at, u.email, u.full_name, u.phone_number
         FROM user_subscriptions us
         JOIN users u ON u.id = us.user_id
         WHERE us.is_active = 1
           AND us.expires_at <= NOW()"
    )->fetchAll();

    // Block semua
    $count = $service->blockExpired();

    subCronLog("Subscription diblokir: {$count}");

    // Kirim email + WA notifikasi ke setiap user yang diblock
    $mailSent = 0;
    $waSent   = 0;
    foreach ($expiredRows as $row) {
        $name      = $row['full_name'] ?? 'User';
        $expiredAt = $row['expires_at'];

        if (!empty($row['email'])) {
            $sent = $notif->sendSubscriptionExpired($row['email'], $name, $expiredAt);
            if ($sent) $mailSent++;
        }

        if (!empty($row['phone_number'])) {
            $sent = $notif->sendWaSubscriptionExpired($row['phone_number'], $name, $expiredAt);
            if ($sent) $waSent++;
        }
    }

    if ($mailSent > 0) {
        subCronLog("Email notifikasi terkirim: {$mailSent}");
    }
    if ($waSent > 0) {
        subCronLog("WA notifikasi terkirim: {$waSent}");
    }

    // Catat heartbeat ke app_settings
    try {
        $db->prepare(
            'INSERT INTO app_settings (config_key, config_value, updated_by, created_at, updated_at)
             VALUES ("cron.subscription_expire.last_run", :val, NULL, NOW(), NOW())
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), updated_at = NOW()'
        )->execute(['val' => date('Y-m-d H:i:s')]);
    } catch (Throwable) { /* ignore */ }

    subCronLog('Selesai.');
    exit(0);

} catch (Throwable $e) {
    subCronLog('ERROR: ' . $e->getMessage());
    exit(1);
}
