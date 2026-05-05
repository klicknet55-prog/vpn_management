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
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$days} days"));

        $this->subModel->upsert($userId, $planId, $startedAt, $expiresAt);

        // Reset counter saat upgrade atau renew
        if ($isUpgrade) {
            $this->usageModel->reset($userId);
        }

        // Re-enable VPN users yang tersuspend + set WA devices ke pending (siap scan QR ulang)
        $this->reactivateUserVpn($userId);
        $this->reactivateUserWa($userId);

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
     */
    private function reactivateUserVpn(int $userId): void
    {
        try {
            $controllerPath = dirname(__DIR__, 2) . '/api/vpn-controller.php';
            if (!function_exists('loadVpnApiConfig') && file_exists($controllerPath)) {
                require_once $controllerPath;
            }

            if (!function_exists('loadVpnApiConfig')) {
                return;
            }

            $vpnConfig = loadVpnApiConfig($this->db);

            $rows = $this->db->prepare(
                "SELECT username FROM vpn_users
                 WHERE owner_user_id = :uid AND vpn_status = 'suspended'"
            );
            $rows->execute(['uid' => $userId]);
            $vpnUsers = $rows->fetchAll();

            foreach ($vpnUsers as $vpnRow) {
                $username = (string) $vpnRow['username'];
                try {
                    vpnApiRequest($vpnConfig, 'POST',
                        $vpnConfig['endpoint_users'] . '/' . rawurlencode($username) . '/enable');
                } catch (Throwable) { /* API offline, tetap update DB */ }

                $this->db->prepare(
                    "UPDATE vpn_users SET vpn_status = 'active', updated_at = NOW()
                     WHERE username = :username"
                )->execute(['username' => $username]);
            }
        } catch (Throwable) {
            // VPN tidak dikonfigurasi atau error – skip
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
            $this->db->prepare(
                "UPDATE wa_accounts
                 SET status = 'pending', disconnected_at = NULL, updated_at = NOW()
                 WHERE owner_user_id = :uid
                   AND status IN ('disconnected', 'error')"
            )->execute(['uid' => $userId]);
        } catch (Throwable) {
            // skip jika tabel belum ada atau error lain
        }
    }
}
