<?php

function waRuntimeBool(mixed $value, bool $default = false): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if ($value === null) {
        return $default;
    }

    $normalized = strtolower(trim((string) $value));
    if ($normalized === '') {
        return $default;
    }

    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }

    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    return $default;
}

function waRequestedQueueValue(mixed $value): ?bool
{
    if ($value === null) {
        return null;
    }

    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower(trim((string) $value));
    if ($normalized === '') {
        return null;
    }

    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }

    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    return null;
}

function waSendRuntimeConfig(): array
{
    $minDelayMs = max(0, (int) WA_SEND_DELAY_MIN_MS);
    $maxDelayMs = max($minDelayMs, (int) WA_SEND_DELAY_MAX_MS);
    $burstPauseMinMs = max(0, (int) WA_SEND_BURST_PAUSE_MIN_MS);
    $burstPauseMaxMs = max($burstPauseMinMs, (int) WA_SEND_BURST_PAUSE_MAX_MS);

    return [
        'delay_min_ms' => $minDelayMs,
        'delay_max_ms' => $maxDelayMs,
        'burst_limit' => max(1, (int) WA_SEND_BURST_LIMIT),
        'burst_window_seconds' => max(1, (int) WA_SEND_BURST_WINDOW_SECONDS),
        'burst_pause_min_ms' => $burstPauseMinMs,
        'burst_pause_max_ms' => $burstPauseMaxMs,
        'admin_queue_enabled' => waRuntimeBool(WA_ADMIN_QUEUE_ENABLED),
        'user_queue_enabled' => waRuntimeBool(WA_USER_QUEUE_ENABLED),
        'queue_batch_limit' => max(1, (int) WA_QUEUE_BATCH_LIMIT),
        'queue_max_attempts' => max(1, (int) WA_QUEUE_MAX_ATTEMPTS),
    ];
}

function waLoadRuntimeOverrides(PDO $db): array
{
    static $cache = [];

    $cacheKey = spl_object_hash($db);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $keys = [
        'wa_runtime.delay_min_ms',
        'wa_runtime.delay_max_ms',
        'wa_runtime.burst_limit',
        'wa_runtime.burst_window_seconds',
        'wa_runtime.burst_pause_min_ms',
        'wa_runtime.burst_pause_max_ms',
        'wa_runtime.admin_queue_enabled',
        'wa_runtime.user_queue_enabled',
        'wa_runtime.queue_batch_limit',
        'wa_runtime.queue_max_attempts',
    ];

    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $overrides = [];

    try {
        $stmt = $db->prepare('SELECT config_key, config_value FROM app_settings WHERE config_key IN (' . $placeholders . ')');
        $stmt->execute($keys);
        foreach ($stmt->fetchAll() as $row) {
            $key = (string) ($row['config_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $overrides[$key] = (string) ($row['config_value'] ?? '');
        }
    } catch (Throwable $e) {
        // app_settings may not exist in early bootstrap contexts.
    }

    $cache[$cacheKey] = $overrides;
    return $overrides;
}

function waSendRuntimeConfigFromDb(?PDO $db = null): array
{
    $config = waSendRuntimeConfig();
    if ($db === null) {
        return $config;
    }

    $overrides = waLoadRuntimeOverrides($db);

    $intMap = [
        'wa_runtime.delay_min_ms' => 'delay_min_ms',
        'wa_runtime.delay_max_ms' => 'delay_max_ms',
        'wa_runtime.burst_limit' => 'burst_limit',
        'wa_runtime.burst_window_seconds' => 'burst_window_seconds',
        'wa_runtime.burst_pause_min_ms' => 'burst_pause_min_ms',
        'wa_runtime.burst_pause_max_ms' => 'burst_pause_max_ms',
        'wa_runtime.queue_batch_limit' => 'queue_batch_limit',
        'wa_runtime.queue_max_attempts' => 'queue_max_attempts',
    ];

    foreach ($intMap as $key => $target) {
        if (!array_key_exists($key, $overrides)) {
            continue;
        }
        $raw = trim((string) $overrides[$key]);
        if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) {
            continue;
        }
        $config[$target] = (int) $raw;
    }

    if (array_key_exists('wa_runtime.admin_queue_enabled', $overrides)) {
        $config['admin_queue_enabled'] = waRuntimeBool($overrides['wa_runtime.admin_queue_enabled'], $config['admin_queue_enabled']);
    }
    if (array_key_exists('wa_runtime.user_queue_enabled', $overrides)) {
        $config['user_queue_enabled'] = waRuntimeBool($overrides['wa_runtime.user_queue_enabled'], $config['user_queue_enabled']);
    }

    $config['delay_min_ms'] = max(0, (int) $config['delay_min_ms']);
    $config['delay_max_ms'] = max((int) $config['delay_min_ms'], (int) $config['delay_max_ms']);
    $config['burst_limit'] = max(1, (int) $config['burst_limit']);
    $config['burst_window_seconds'] = max(1, (int) $config['burst_window_seconds']);
    $config['burst_pause_min_ms'] = max(0, (int) $config['burst_pause_min_ms']);
    $config['burst_pause_max_ms'] = max((int) $config['burst_pause_min_ms'], (int) $config['burst_pause_max_ms']);
    $config['queue_batch_limit'] = max(1, (int) $config['queue_batch_limit']);
    $config['queue_max_attempts'] = max(1, (int) $config['queue_max_attempts']);

    return $config;
}

function waShouldUseQueue(string $scope, ?bool $requestedValue = null, ?PDO $db = null): bool
{
    if ($requestedValue !== null) {
        return $requestedValue;
    }

    $config = waSendRuntimeConfigFromDb($db);
    if ($scope === 'admin') {
        return $config['admin_queue_enabled'];
    }

    return $config['user_queue_enabled'];
}

function waDeviceQueueEnabled(PDO $db, string $deviceId): bool
{
    try {
        $stmt = $db->prepare('SELECT queue_enabled FROM wa_accounts WHERE device_id = :device_id LIMIT 1');
        $stmt->execute(['device_id' => $deviceId]);
        $row = $stmt->fetch();
        if ($row !== false && isset($row['queue_enabled'])) {
            return (bool) $row['queue_enabled'];
        }
    } catch (Throwable $e) {
        // If query fails, return true (assume queue is enabled by default)
    }
    return true;
}

function ensureWaMessageQueueTable(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS wa_message_queue (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device_id VARCHAR(100) NOT NULL,
            phone_number VARCHAR(30) NOT NULL,
            message_text TEXT NOT NULL,
            source VARCHAR(40) NOT NULL DEFAULT 'user_api',
            queue_scope VARCHAR(20) NOT NULL DEFAULT 'user',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
            available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            locked_at DATETIME NULL,
            sent_at DATETIME NULL,
            worker_token VARCHAR(64) NULL,
            last_error TEXT NULL,
            payload JSON NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_wa_queue_status_available (status, available_at, id),
            INDEX idx_wa_queue_worker (worker_token),
            INDEX idx_wa_queue_device_created (device_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function countRecentDeviceSendRequests(PDO $db, string $deviceId): int
{
    $config = waSendRuntimeConfigFromDb($db);
    $windowSeconds = max(1, (int) $config['burst_window_seconds']);
    $stmt = $db->prepare(
        'SELECT COUNT(*)
         FROM audit_logs
         WHERE action = :action
           AND target_type = :target_type
           AND target_id = :target_id
           AND created_at >= DATE_SUB(NOW(), INTERVAL ' . $windowSeconds . ' SECOND)'
    );

    $stmt->bindValue(':action', 'wa_send_request');
    $stmt->bindValue(':target_type', 'wa_device');
    $stmt->bindValue(':target_id', $deviceId);
    $stmt->execute();

    return (int) $stmt->fetchColumn();
}

function writeDeviceSendAuditLog(PDO $db, string $deviceId, array $detail): void
{
    $stmt = $db->prepare(
        'INSERT INTO audit_logs (actor_user_id, action, target_type, target_id, detail, created_at)
         VALUES (NULL, :action, :target_type, :target_id, :detail, NOW())'
    );

    $stmt->execute([
        'action' => 'wa_send_request',
        'target_type' => 'wa_device',
        'target_id' => $deviceId,
        'detail' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function waPrepareSendDelay(PDO $db, string $deviceId, string $source, string $phone): array
{
    $config = waSendRuntimeConfigFromDb($db);
    $baseDelayMs = random_int($config['delay_min_ms'], $config['delay_max_ms']);
    $recentRequestCount = countRecentDeviceSendRequests($db, $deviceId);
    $rateLimitPauseMs = 0;

    if ($recentRequestCount >= $config['burst_limit']) {
        $rateLimitPauseMs = random_int($config['burst_pause_min_ms'], $config['burst_pause_max_ms']);
    }

    $delay = [
        'source' => $source,
        'phone' => $phone,
        'recent_count_before' => $recentRequestCount,
        'base_delay_ms' => $baseDelayMs,
        'rate_limit_pause_ms' => $rateLimitPauseMs,
        'total_delay_ms' => $baseDelayMs + $rateLimitPauseMs,
        'burst_limit' => $config['burst_limit'],
        'burst_window_seconds' => $config['burst_window_seconds'],
    ];

    writeDeviceSendAuditLog($db, $deviceId, $delay);

    return $delay;
}

function waApplySendDelay(array $delay): void
{
    $totalDelayMs = (int) ($delay['total_delay_ms'] ?? 0);
    if ($totalDelayMs > 0) {
        usleep($totalDelayMs * 1000);
    }
}

function waSendNow(PDO $db, string $deviceId, string $phone, string $message, string $source): array
{
    $delay = waPrepareSendDelay($db, $deviceId, $source, $phone);
    waApplySendDelay($delay);

    $res = gowaRequest(
        'POST',
        '/send/message',
        [
            'phone' => $phone,
            'message' => $message,
        ],
        ['X-Device-Id: ' . $deviceId]
    );

    if (!$res['ok']) {
        $errMsg = '';
        if (is_array($res['data'])) {
            $errMsg = (string) ($res['data']['message'] ?? $res['data']['error'] ?? '');
        }
        if ($errMsg === '') {
            $errMsg = 'Gagal kirim pesan (GoWA HTTP ' . (int) $res['status'] . ')';
        }
        throw new RuntimeException($errMsg);
    }

    return [
        'device_id' => $deviceId,
        'delay_ms' => (int) ($delay['total_delay_ms'] ?? 0),
        'base_delay_ms' => (int) ($delay['base_delay_ms'] ?? 0),
        'rate_limit_pause_ms' => (int) ($delay['rate_limit_pause_ms'] ?? 0),
        'response' => gowaUnwrap($res['data']),
    ];
}

function enqueueWaMessage(PDO $db, string $deviceId, string $phone, string $message, string $source, string $scope, array $payload = []): int
{
    ensureWaMessageQueueTable($db);
    $config = waSendRuntimeConfigFromDb($db);

    $stmt = $db->prepare(
        'INSERT INTO wa_message_queue
            (device_id, phone_number, message_text, source, queue_scope, status, attempts, max_attempts, available_at, payload, created_at, updated_at)
         VALUES
            (:device_id, :phone_number, :message_text, :source, :queue_scope, "pending", 0, :max_attempts, NOW(), :payload, NOW(), NOW())'
    );

    $stmt->execute([
        'device_id' => $deviceId,
        'phone_number' => $phone,
        'message_text' => $message,
        'source' => $source,
        'queue_scope' => $scope,
        'max_attempts' => $config['queue_max_attempts'],
        'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    return (int) $db->lastInsertId();
}

function claimQueuedMessages(PDO $db, int $batchLimit): array
{
    ensureWaMessageQueueTable($db);

    $batchLimit = max(1, $batchLimit);
    $workerToken = bin2hex(random_bytes(16));

    $db->beginTransaction();
    try {
        $update = $db->prepare(
            'UPDATE wa_message_queue
             SET status = "processing",
                 worker_token = :worker_token,
                 locked_at = NOW(),
                 attempts = attempts + 1,
                 updated_at = NOW()
             WHERE status = "pending"
               AND available_at <= NOW()
             ORDER BY id ASC
             LIMIT ' . $batchLimit
        );
        $update->execute(['worker_token' => $workerToken]);

        $select = $db->prepare(
            'SELECT id, device_id, phone_number, message_text, source, queue_scope, status, attempts, max_attempts, payload
             FROM wa_message_queue
             WHERE worker_token = :worker_token AND status = "processing"
             ORDER BY id ASC'
        );
        $select->execute(['worker_token' => $workerToken]);
        $rows = $select->fetchAll();
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return is_array($rows) ? $rows : [];
}

function markQueuedMessageSent(PDO $db, int $queueId, array $response): void
{
    $stmt = $db->prepare(
        'UPDATE wa_message_queue
         SET status = "sent", sent_at = NOW(), worker_token = NULL, last_error = NULL, payload = :payload, updated_at = NOW()
         WHERE id = :id'
    );

    $stmt->execute([
        'id' => $queueId,
        'payload' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function markQueuedMessageFailed(PDO $db, array $job, string $error): void
{
    $queueId = (int) ($job['id'] ?? 0);
    $attempts = (int) ($job['attempts'] ?? 0);
    $runtime = waSendRuntimeConfigFromDb($db);
    $maxAttempts = max(1, (int) ($job['max_attempts'] ?? $runtime['queue_max_attempts']));
    $nextStatus = $attempts >= $maxAttempts ? 'failed' : 'pending';
    $availableAtSql = $nextStatus === 'pending'
        ? 'DATE_ADD(NOW(), INTERVAL 30 SECOND)'
        : 'NOW()';

    $stmt = $db->prepare(
        'UPDATE wa_message_queue
         SET status = :status,
             worker_token = NULL,
             last_error = :last_error,
             available_at = ' . $availableAtSql . ',
             updated_at = NOW()
         WHERE id = :id'
    );

    $stmt->execute([
        'id' => $queueId,
        'status' => $nextStatus,
        'last_error' => $error,
    ]);
}