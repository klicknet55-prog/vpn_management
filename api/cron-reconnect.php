<?php
//
// Cron Auto-Reconnect WhatsApp Devices
// ─────────────────────────────────────────────────────────────────────────────
// Jalankan via CLI (direkomendasikan):
//   php /path/to/api/cron-reconnect.php
//
// Atau via HTTP dengan secret token:
//   GET /api/cron-reconnect.php?token=<CRON_SECRET>
//
// Setup Crontab (setiap 5 menit):
//   */5 * * * * php /path/to/templatemo/api/cron-reconnect.php >> /path/to/logs/cron-reconnect.log 2>&1
//
// Variabel .env yang dibutuhkan:
//   CRON_SECRET=<random-strong-secret>   (wajib saat diakses via HTTP)
// ─────────────────────────────────────────────────────────────────────────────

define('CRON_RUNNING', true);
$isCli = PHP_SAPI === 'cli';

// ── Bootstrap ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gowa.php';

// ── HTTP access guard ─────────────────────────────────────────────────────────
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

// ── Helpers ───────────────────────────────────────────────────────────────────
function cronLog(string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . PHP_EOL;
}

function writeCronAudit(PDO $db, string $deviceId, string $action, array $detail): void
{
    try {
        $db->prepare(
            'INSERT INTO audit_logs (actor_user_id, action, target_type, target_id, detail, created_at)
             VALUES (NULL, :action, "cron_reconnect", :target_id, :detail, NOW())'
        )->execute([
            'action'    => $action,
            'target_id' => $deviceId,
            'detail'    => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable $e) {
        cronLog('[WARN] Gagal tulis audit log: ' . $e->getMessage());
    }
}

function writeCronHeartbeat(PDO $db, string $key): void
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
            'config_key' => $key,
            'config_value' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        cronLog('[WARN] Gagal tulis heartbeat cron: ' . $e->getMessage());
    }
}

// ── Normalize status ──────────────────────────────────────────────────────────
function cronNormalizeStatus(mixed $data, string $fallback = ''): string
{
    if (!is_array($data)) {
        return $fallback;
    }

    $status = strtolower((string) ($data['status'] ?? ''));
    if ($status !== '') {
        return $status;
    }

    $isConnected = $data['is_connected'] ?? null;
    $isLoggedIn  = $data['is_logged_in'] ?? null;

    if ($isConnected === true || $isLoggedIn === true) {
        return 'connected';
    }
    if ($isConnected === false || $isLoggedIn === false) {
        return 'pending';
    }

    return strtolower(trim($fallback));
}

// ── Main Logic ────────────────────────────────────────────────────────────────
cronLog('=== Cron Auto-Reconnect mulai ===');

try {
    $db = getDB();
} catch (Throwable $e) {
    cronLog('[ERROR] Gagal konek DB: ' . $e->getMessage());
    exit(1);
}

writeCronHeartbeat($db, 'cron.reconnect.last_run');

// Ambil semua device dari DB (kecuali yang baru saja disconnected manual)
$stmt = $db->query(
    "SELECT device_id, label, status, owner_user_id
     FROM wa_accounts
     WHERE status NOT IN ('disconnected')
     ORDER BY id ASC"
);
$devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($devices)) {
    cronLog('Tidak ada device aktif untuk diperiksa.');
    cronLog('=== Selesai ===');
    exit(0);
}

cronLog('Total device: ' . count($devices));

$connected   = 0;
$reconnected = 0;
$failed      = 0;
$skipped     = 0;

foreach ($devices as $device) {
    $deviceId = (string) ($device['device_id'] ?? '');
    $label    = (string) ($device['label'] ?? $deviceId);
    $dbStatus = (string) ($device['status'] ?? '');

    if ($deviceId === '') {
        $skipped++;
        continue;
    }

    // ── Cek status terkini ke GoWA ────────────────────────────────────────────
    try {
        $res  = gowaRequest('GET', '/devices/' . rawurlencode($deviceId) . '/status');
        $data = is_array($res['data']) ? (gowaUnwrap($res['data']) ?? $res['data']) : [];
    } catch (Throwable $e) {
        cronLog("[ERROR] $label ($deviceId): Gagal ambil status — " . $e->getMessage());
        $failed++;
        continue;
    }

    $liveStatus = cronNormalizeStatus(is_array($data) ? $data : [], $dbStatus);

    // ── Sinkronisasi status ke DB ─────────────────────────────────────────────
    if ($liveStatus !== '' && $liveStatus !== $dbStatus) {
        $updateFields  = ['status = :status', 'updated_at = NOW()'];
        $updateParams  = ['status' => $liveStatus, 'device_id' => $deviceId];

        if ($liveStatus === 'connected') {
            $updateFields[] = 'connected_at = NOW()';
            $phone = is_array($data) ? ($data['phone'] ?? $data['jid'] ?? '') : '';
            if ($phone) {
                $updateFields[] = 'phone_jid = :phone_jid';
                $updateParams['phone_jid'] = $phone;
            }
        }

        $db->prepare('UPDATE wa_accounts SET ' . implode(', ', $updateFields) . ' WHERE device_id = :device_id')
            ->execute($updateParams);
    }

    // ── Sudah connected, tidak perlu reconnect ────────────────────────────────
    if ($liveStatus === 'connected') {
        cronLog("[OK] $label ($deviceId): connected");
        $connected++;
        continue;
    }

    // ── Status tidak memerlukan reconnect otomatis ────────────────────────────
    // 'pending' berarti menunggu QR scan (belum pernah login); tidak bisa reconnect otomatis.
    if ($liveStatus === 'pending') {
        cronLog("[SKIP] $label ($deviceId): pending (perlu scan QR manual)");
        $skipped++;
        continue;
    }

    // ── Coba reconnect ────────────────────────────────────────────────────────
    cronLog("[RECONNECT] $label ($deviceId): status=$liveStatus — mencoba reconnect...");

    try {
        // GoWA menyediakan endpoint reconnect; beberapa versi menggunakan /app/reconnect
        $reconnRes = gowaRequest('GET', '/devices/' . rawurlencode($deviceId) . '/reconnect');

        // Fallback ke endpoint legacy jika endpoint baru tidak dikenali (404 / 501)
        if (!$reconnRes['ok'] && in_array($reconnRes['status'], [404, 501], true)) {
            $reconnRes = gowaRequest('GET', '/app/reconnect', null, ['X-Device-Id: ' . $deviceId]);
        }
    } catch (Throwable $e) {
        cronLog("[ERROR] $label ($deviceId): Gagal reconnect — " . $e->getMessage());
        writeCronAudit($db, $deviceId, 'cron_reconnect_error', [
            'label'      => $label,
            'live_status' => $liveStatus,
            'error'      => $e->getMessage(),
        ]);
        $failed++;
        continue;
    }

    if ($reconnRes['ok']) {
        cronLog("[SUCCESS] $label ($deviceId): Reconnect berhasil (HTTP {$reconnRes['status']})");

        // Update status ke 'reconnecting' sementara GoWA memproses
        $db->prepare(
            "UPDATE wa_accounts SET status = 'reconnecting', updated_at = NOW() WHERE device_id = ?"
        )->execute([$deviceId]);

        writeCronAudit($db, $deviceId, 'cron_reconnect_success', [
            'label'      => $label,
            'live_status' => $liveStatus,
            'gowa_http'  => $reconnRes['status'],
        ]);
        $reconnected++;
    } else {
        $errMsg = '';
        if (is_array($reconnRes['data'])) {
            $errMsg = $reconnRes['data']['message'] ?? $reconnRes['data']['error'] ?? '';
        }
        if (!$errMsg) {
            $errMsg = 'HTTP ' . $reconnRes['status'];
        }

        cronLog("[FAIL] $label ($deviceId): Reconnect gagal — $errMsg");

        // Tandai error agar mudah dimonitor
        $db->prepare(
            "UPDATE wa_accounts SET status = 'error', updated_at = NOW() WHERE device_id = ?"
        )->execute([$deviceId]);

        writeCronAudit($db, $deviceId, 'cron_reconnect_failed', [
            'label'      => $label,
            'live_status' => $liveStatus,
            'error'      => $errMsg,
            'gowa_http'  => $reconnRes['status'],
        ]);
        $failed++;
    }
}

// ── Summary ───────────────────────────────────────────────────────────────────
cronLog('─────────────────────────────────');
cronLog("Ringkasan: connected=$connected | reconnected=$reconnected | failed=$failed | skipped=$skipped");
cronLog('=== Cron Auto-Reconnect selesai ===');
exit(0);
