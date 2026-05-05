<?php
/**
 * Cron: Kirim Notifikasi Subscription Hampir Habis
 * ─────────────────────────────────────────────────────────────────────────────
 * Kirim email ke user yang subscriptionnya akan berakhir dalam N hari.
 * Default: 3 hari & 1 hari sebelum expired.
 *
 * Crontab (sekali sehari pagi):
 *   0 8 * * * php /path/to/templatemo/api/cron-notify-expiring.php >> /var/log/cron-notify.log 2>&1
 * ─────────────────────────────────────────────────────────────────────────────
 */

define('CRON_RUNNING', true);
$isCli = PHP_SAPI === 'cli';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
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

function notifyCronLog(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

// Notifikasi dikirim saat N hari sebelum expired
const NOTIFY_DAYS = [3, 1];

try {
    $db    = getDB();
    $notif = new NotificationService();
    $total = 0;

    foreach (NOTIFY_DAYS as $days) {
        // Cari user yang subscriptionnya persis N hari lagi (window ±12 jam)
        $stmt = $db->prepare(
            "SELECT us.user_id, us.expires_at, u.email, u.full_name, u.phone_number
             FROM user_subscriptions us
             JOIN users u ON u.id = us.user_id
             WHERE us.is_active = 1
               AND us.expires_at BETWEEN DATE_ADD(NOW(), INTERVAL :days_min HOUR)
                                    AND DATE_ADD(NOW(), INTERVAL :days_max HOUR)"
        );
        $stmt->execute([
            'days_min' => ($days * 24) - 12,
            'days_max' => ($days * 24) + 12,
        ]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $name      = $row['full_name'] ?? 'User';
            $expiredAt = $row['expires_at'];

            if (!empty($row['email'])) {
                $sent = $notif->sendSubscriptionExpiringSoon(
                    $row['email'], $name, $days, $expiredAt
                );
                if ($sent) {
                    $total++;
                    notifyCronLog("Email {$days}d-warning → {$row['email']}");
                }
            }

            if (!empty($row['phone_number'])) {
                $sent = $notif->sendWaSubscriptionExpiringSoon(
                    $row['phone_number'], $name, $days, $expiredAt
                );
                if ($sent) {
                    notifyCronLog("WA {$days}d-warning → {$row['phone_number']}");
                }
            }
        }
    }

    notifyCronLog("Total email terkirim: {$total}");

    // Heartbeat
    try {
        $db->prepare(
            'INSERT INTO app_settings (config_key, config_value, updated_by, created_at, updated_at)
             VALUES ("cron.notify_expiring.last_run", :val, NULL, NOW(), NOW())
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), updated_at = NOW()'
        )->execute(['val' => date('Y-m-d H:i:s')]);
    } catch (Throwable) { /* ignore */ }

    notifyCronLog('Selesai.');
    exit(0);

} catch (Throwable $e) {
    notifyCronLog('ERROR: ' . $e->getMessage());
    exit(1);
}
