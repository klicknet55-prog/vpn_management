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
     * Return jumlah user yang diblokir.
     */
    public function blockExpired(): int
    {
        $expired = $this->subModel->getExpiredActive();
        $count   = 0;
        foreach ($expired as $row) {
            $this->subModel->disable((int)$row['user_id'], 'expired');
            $count++;
        }
        return $count;
    }
}
