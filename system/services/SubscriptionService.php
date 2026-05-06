<?php
/**
 * SubscriptionService
 *
 * Business logic untuk:
 * - Assign plan ke user (register → free plan)
 * - Aktivasi subscription setelah payment sukses
 * - Block subscription saat expired
 * - Ambil info subscription aktif user
 */

require_once __DIR__ . '/../models/Plan.php';
require_once __DIR__ . '/../models/UserSubscription.php';
require_once __DIR__ . '/../models/UserFeaturesUsage.php';

class SubscriptionService
{
    private Plan $planModel;
    private UserSubscription $subModel;
    private UserFeaturesUsage $usageModel;
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db         = $db;
        $this->planModel  = new Plan($db);
        $this->subModel   = new UserSubscription($db);
        $this->usageModel = new UserFeaturesUsage($db);
    }

    /**
     * Assign free plan ke user baru saat register.
     */
    public function assignFreePlan(int $userId): bool
    {
        $plan = $this->planModel->findByName('free');
        if (!$plan) return false;

        $startedAt  = date('Y-m-d H:i:s');
        $expiresAt  = date('Y-m-d H:i:s', strtotime("+{$plan['duration_days']} days"));

        $this->subModel->upsert($userId, (int)$plan['id'], $startedAt, $expiresAt);

        // Init usage record
        $this->usageModel->getOrCreate($userId);

        return true;
    }

    /**
     * Aktifkan subscription setelah payment berhasil.
     * Jika upgrade, reset usage counter.
     *
     * @param int|null $overrideDays Override durasi (admin manual activate). Null = gunakan durasi plan.
     */
    public function activate(int $userId, int $planId, ?int $overrideDays = null): bool
    {
        $plan = $this->planModel->findById($planId);
        if (!$plan) return false;

        $current = $this->subModel->getByUserId($userId);
        $isUpgrade = !$current || (int)$current['plan_id'] !== $planId;

        $days      = ($overrideDays !== null && $overrideDays > 0) ? $overrideDays : (int) $plan['duration_days'];
        $startedAt = date('Y-m-d H:i:s');

        // Jika renew plan yang sama dan subscription masih aktif,
        // extend dari expires_at yang ada (bukan dari sekarang) agar sisa hari tidak hangus.
        $baseTime = time();
        if (!$isUpgrade && !empty($current['expires_at'])) {
            $currentExpiry = strtotime($current['expires_at']);
            if ($currentExpiry > $baseTime) {
                $baseTime = $currentExpiry;
            }
        }

        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$days} days", $baseTime));

        $this->subModel->upsert($userId, $planId, $startedAt, $expiresAt);

        // Reset counter saat upgrade atau renew
        if ($isUpgrade) {
            $this->usageModel->reset($userId);
        }

        // Queue reactivate VPN/WA as async job (non-blocking)
        // Jika VPN/GoWA offline, tetap success karena subscription sudah di-save
        $this->queueReactivateAsync($userId);

        return true;
    }

    /**
     * Ambil info lengkap subscription user (digabung plan limits).
     */
    public function getInfo(int $userId): array
    {
        $sub   = $this->subModel->getByUserId($userId);
        $usage = $this->usageModel->getOrCreate($userId);

        if (!$sub) {
            return [
                'has_subscription' => false,
                'is_active'        => false,
                'plan'             => null,
                'usage'            => $usage,
                'expires_at'       => null,
                'days_remaining'   => 0,
            ];
        }

        $expiresTs    = strtotime($sub['expires_at']);
        $daysRemaining = max(0, (int) ceil(($expiresTs - time()) / 86400));
        $isExpired     = $expiresTs <= time();

        return [
            'has_subscription' => true,
            'is_active'        => (bool) $sub['is_active'] && !$isExpired,
            'plan'             => [
                'id'               => $sub['plan_id'],
                'name'             => $sub['plan_name'],
                'label'            => $sub['plan_label'],
                'vpn_limit'        => (int) $sub['vpn_limit'],
                'wa_device_limit'  => (int) $sub['wa_device_limit'],
                'proxy_route_limit'=> (int) $sub['proxy_route_limit'],
            ],
            'usage'         => [
                'vpn_count'         => (int) $usage['vpn_count'],
                'wa_device_count'   => (int) $usage['wa_device_count'],
                'proxy_route_count' => (int) $usage['proxy_route_count'],
            ],
            'started_at'    => $sub['started_at'],
            'expires_at'    => $sub['expires_at'],
            'days_remaining'=> $daysRemaining,
            'disabled_reason' => $sub['disabled_reason'],
        ];
    }

    /**
     * Cek apakah subscription user aktif dan tidak expired.
     */
    public function isActive(int $userId): bool
    {
        return $this->subModel->isActive($userId);
    }

    /**
     * Jalankan blocking semua subscription yang sudah expired.
     * Dipanggil oleh cron job.
     *
     * Untuk setiap user yang di-block:
     * - Subscription di-set is_active = 0, disabled_reason = 'expired'
     * - Semua VPN user miliknya di-suspend (via VPN API + update DB)
     * - Semua WA device miliknya di-logout (via GoWA API + update DB)
     *
     * @return int Jumlah subscription yang diblokir.
     */
    public function blockExpired(): int
    {
        $expired = $this->subModel->getExpiredActive();
        $count   = 0;
        foreach ($expired as $row) {
            $userId = (int) $row['user_id'];
            $this->subModel->disable($userId, 'expired');
            $this->suspendUserVpn($userId);
            $this->disconnectUserWa($userId);
            $count++;
        }
        return $count;
    }

    /**
     * Suspend semua VPN user milik $userId via VPN API + update DB.
     * Gagal per-item tidak menghentikan proses.
     */
    private function suspendUserVpn(int $userId): void
    {
        try {
            // Perlu load vpn-controller helper functions
            $controllerPath = dirname(__DIR__, 2) . '/api/vpn-controller.php';
            if (!function_exists('loadVpnApiConfig') && file_exists($controllerPath)) {
                require_once $controllerPath;
            }

            if (!function_exists('loadVpnApiConfig')) {
                return; // file tidak tersedia, skip
            }

            $vpnConfig = loadVpnApiConfig($this->db);

            $rows = $this->db->prepare(
                "SELECT username FROM vpn_users
                 WHERE owner_user_id = :uid AND vpn_status = 'active'"
            );
            $rows->execute(['uid' => $userId]);
            $vpnUsers = $rows->fetchAll();

            foreach ($vpnUsers as $vpnRow) {
                $username = (string) $vpnRow['username'];
                try {
                    vpnApiRequest($vpnConfig, 'POST',
                        $vpnConfig['endpoint_users'] . '/' . rawurlencode($username) . '/disable');
                } catch (Throwable) { /* API offline, tetap update DB */ }

                $this->db->prepare(
                    "UPDATE vpn_users SET vpn_status = 'suspended', updated_at = NOW()
                     WHERE username = :username"
                )->execute(['username' => $username]);
            }
        } catch (Throwable) {
            // VPN tidak dikonfigurasi atau error – skip, jangan block proses utama
        }
    }

    /**
     * Logout semua WA device milik $userId via GoWA API + update DB.
     * Gagal per-item tidak menghentikan proses.
     */
    private function disconnectUserWa(int $userId): void
    {
        try {
            $gowaPath = dirname(__DIR__, 2) . '/api/gowa.php';
            if (!function_exists('gowaRequest') && file_exists($gowaPath)) {
                require_once $gowaPath;
            }

            if (!function_exists('gowaRequest')) {
                return;
            }

            $rows = $this->db->prepare(
                "SELECT device_id FROM wa_accounts
                 WHERE owner_user_id = :uid
                   AND status IN ('connected', 'qr_ready', 'pending')"
            );
            $rows->execute(['uid' => $userId]);
            $waDevices = $rows->fetchAll();

            foreach ($waDevices as $waRow) {
                $deviceId = (string) $waRow['device_id'];
                try {
                    gowaRequest('POST', '/devices/' . rawurlencode($deviceId) . '/logout');
                } catch (Throwable) { /* GoWA offline, tetap update DB */ }

                $this->db->prepare(
                    'UPDATE wa_accounts
                     SET status = "disconnected", disconnected_at = NOW(), updated_at = NOW()
                     WHERE device_id = :device_id'
                )->execute(['device_id' => $deviceId]);
            }
        } catch (Throwable) {
            // GoWA tidak dikonfigurasi atau error – skip
        }
    }

    /**
     * Re-enable VPN users yang sebelumnya tersuspend karena expired.
     * Dipanggil saat user aktivasi/renew subscription.
     * Setiap API call dipecah dengan timeout pendek (5s) agar tidak memblock.
     */
    private function reactivateUserVpn(int $userId): void
    {
        try {
            $controllerPath = dirname(__DIR__, 2) . '/api/vpn-controller.php';
            if (!function_exists('loadVpnApiConfig') && file_exists($controllerPath)) {
                if (!defined('VPN_CONTROLLER_LIB_MODE')) {
                    define('VPN_CONTROLLER_LIB_MODE', true);
                }
                require_once $controllerPath;
            }

            if (!function_exists('loadVpnApiConfig')) {
                return;
            }

            $vpnConfig = loadVpnApiConfig($this->db);
            if (!is_array($vpnConfig) || empty($vpnConfig)) {
                return;
            }

            // Override timeout untuk reactivate (short, non-blocking)
            $vpnConfig['timeout'] = min(5, (int) ($vpnConfig['timeout'] ?? 5));

            $rows = $this->db->prepare(
                "SELECT username FROM vpn_users
                 WHERE owner_user_id = :uid AND vpn_status = 'suspended'"
            );
            $rows->execute(['uid' => $userId]);
            $vpnUsers = $rows->fetchAll();

            if (empty($vpnUsers)) {
                return;
            }

            foreach ($vpnUsers as $vpnRow) {
                $username = (string) $vpnRow['username'];
                try {
                    vpnApiRequest($vpnConfig, 'POST',
                        $vpnConfig['endpoint_users'] . '/' . rawurlencode($username) . '/enable');
                } catch (Throwable $e) {
                    /* API offline/timeout, tetap update DB */
                    error_log("Reactivate VPN API error for {$username}: " . $e->getMessage());
                }

                try {
                    $this->db->prepare(
                        "UPDATE vpn_users SET vpn_status = 'active', updated_at = NOW()
                         WHERE username = :username"
                    )->execute(['username' => $username]);
                } catch (Throwable $e) {
                    /* skip jika DB error */
                    error_log("Reactivate VPN DB error for {$username}: " . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            // VPN tidak dikonfigurasi atau error – skip
            error_log("Reactivate VPN fatal error for user {$userId}: " . $e->getMessage());
        }
    }

    /**
     * Set WA devices yang disconnected/error kembali ke 'pending'
     * agar user bisa scan QR ulang setelah renew subscription.
     * (WA tidak bisa auto-reconnect — butuh QR scan manual.)
     */
    private function reactivateUserWa(int $userId): void
    {
        try {
            // Hanya update database, tidak ada API call ke GoWA
            // GoWA butuh QR scan manual, jadi just set status to pending
            $stmt = $this->db->prepare(
                "UPDATE wa_accounts
                 SET status = 'pending', disconnected_at = NULL, updated_at = NOW()
                 WHERE owner_user_id = :uid
                   AND status IN ('disconnected', 'error')"
            );
            $stmt->execute(['uid' => $userId]);
        } catch (Throwable $e) {
            // skip jika tabel belum ada atau error lain
            error_log("Reactivate WA error for user {$userId}: " . $e->getMessage());
        }
    }

    /**
     * Queue async reactivate VPN/WA (non-blocking).
     * Langsung jalankan di background dengan timeout pendek.
     * Jika VPN/GoWA offline/timeout, tetap lanjut (tidak fail).
     */
    private function queueReactivateAsync(int $userId): void
    {
        // Set short max execution time untuk reactivate agar jangan hang
        $prevTimeout = (int) @ini_get('max_execution_time');
        $prevSocket = @ini_get('default_socket_timeout');
        
        try {
            @set_time_limit(10); // Max 10 second untuk reactivate
            @ini_set('default_socket_timeout', '5'); // 5 second socket timeout

            $this->reactivateUserVpn($userId);
            $this->reactivateUserWa($userId);
        } catch (Throwable $e) {
            // Log but don't fail — subscription sudah di-save, reactivate adalah optional
            @error_log("Reactivate async error for user {$userId}: " . $e->getMessage());
        } finally {
            // Restore previous settings
            if ($prevTimeout > 0) {
                @set_time_limit($prevTimeout);
            }
            if (!empty($prevSocket)) {
                @ini_set('default_socket_timeout', $prevSocket);
            }
        }
    }
}
