<?php

define('CRON_RUNNING', true);
$isCli = PHP_SAPI === 'cli';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gowa.php';
require_once __DIR__ . '/wa-send-runtime.php';

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');

    $envSecret = trim((string) env('CRON_SECRET', ''));
    $reqToken = trim((string) ($_GET['token'] ?? ''));

    if ($envSecret === '' || $reqToken === '' || !hash_equals($envSecret, $reqToken)) {
        http_response_code(403);
        echo "403 Forbidden\n";
        exit;
    }
}

function queueCronLog(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function writeQueueWorkerHeartbeat(PDO $db): void
{
    try {
        $db->prepare(
            'INSERT INTO app_settings (config_key, config_value, updated_by, created_at, updated_at)
             VALUES (:config_key, :config_value, NULL, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                config_value = VALUES(config_value),
                updated_by = NULL,
                updated_at = NOW()'
        )->execute([
            'config_key' => 'cron.queue_worker.last_run',
            'config_value' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        queueCronLog('WARN: gagal tulis heartbeat queue worker: ' . $e->getMessage());
    }
}

try {
    $db = getDB();
    ensureWaMessageQueueTable($db);
    writeQueueWorkerHeartbeat($db);
    $config = waSendRuntimeConfigFromDb($db);
    $jobs = claimQueuedMessages($db, (int) $config['queue_batch_limit']);
} catch (Throwable $e) {
    queueCronLog('ERROR: ' . $e->getMessage());
    exit(1);
}

if (!$jobs) {
    queueCronLog('Tidak ada queue WA pending.');
    exit(0);
}

queueCronLog('Memproses ' . count($jobs) . ' job queue WA.');

foreach ($jobs as $job) {
    $queueId = (int) ($job['id'] ?? 0);
    $deviceId = (string) ($job['device_id'] ?? '');
    $phone = (string) ($job['phone_number'] ?? '');
    $message = (string) ($job['message_text'] ?? '');
    $source = (string) ($job['source'] ?? 'wa_queue');

    try {
        $sendResult = waSendNow($db, $deviceId, $phone, $message, $source . '_queue_worker');
        markQueuedMessageSent($db, $queueId, $sendResult);
        queueCronLog('SENT queue_id=' . $queueId . ' device=' . $deviceId . ' phone=' . $phone);
    } catch (Throwable $e) {
        markQueuedMessageFailed($db, $job, $e->getMessage());
        queueCronLog('FAIL queue_id=' . $queueId . ' device=' . $deviceId . ' error=' . $e->getMessage());
    }
}

exit(0);