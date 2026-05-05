<?php
/**
 * FeatureLimitService
 *
 * Business logic untuk:
 * - Validasi apakah user boleh membuat resource baru (VPN / WA Device / Proxy Route)
 * - Increment counter saat resource berhasil dibuat
 * - Decrement counter saat resource dihapus
 */

require_once __DIR__ . '/../models/UserSubscription.php';
require_once __DIR__ . '/../models/UserFeaturesUsage.php';

class FeatureLimitService
{
    private UserSubscription $subModel;
    private UserFeaturesUsage $usageModel;

    // Mapping feature key ke kolom limit di tabel plans
    private const LIMIT_COLUMN = [
        'vpn'         => 'vpn_limit',
        'wa_device'   => 'wa_device_limit',
        'proxy_route' => 'proxy_route_limit',
    ];

    // Pesan error yang ramah
    private const ERROR_MSG = [
        'vpn'         => 'Batas pembuatan VPN user sudah tercapai pada paket Anda.',
        'wa_device'   => 'Batas pembuatan WhatsApp device sudah tercapai pada paket Anda.',
        'proxy_route' => 'Batas pembuatan proxy route sudah tercapai pada paket Anda.',
    ];

    public function __construct(PDO $db)
    {
        $this->subModel   = new UserSubscription($db);
        $this->usageModel = new UserFeaturesUsage($db);
    }

    /**
     * Validasi apakah user boleh membuat resource baru.
     *
     * Return: ['allowed' => bool, 'message' => string, 'limit' => int, 'used' => int]
     *
     * @param string $feature  'vpn' | 'wa_device' | 'proxy_route'
     */
    public function check(int $userId, string $feature): array
    {
        $limitCol = self::LIMIT_COLUMN[$feature] ?? null;
        if (!$limitCol) {
            return ['allowed' => false, 'message' => 'Feature tidak dikenal.', 'limit' => 0, 'used' => 0];
        }

        // Cek subscription aktif
        $sub = $this->subModel->getByUserId($userId);
        if (!$sub || !(bool)$sub['is_active'] || strtotime($sub['expires_at']) <= time()) {
            return [
                'allowed' => false,
                'message' => 'Subscription Anda tidak aktif atau sudah expired. Silakan upgrade paket.',
                'limit'   => 0,
                'used'    => 0,
            ];
        }

        $limit = (int) $sub[$limitCol];
        $usage = $this->usageModel->getOrCreate($userId);

        $usageColMap = [
            'vpn'         => 'vpn_count',
            'wa_device'   => 'wa_device_count',
            'proxy_route' => 'proxy_route_count',
        ];
        $used = (int) $usage[$usageColMap[$feature]];

        if ($used >= $limit) {
            return [
                'allowed' => false,
                'message' => self::ERROR_MSG[$feature] . " (limit: {$limit})",
                'limit'   => $limit,
                'used'    => $used,
            ];
        }

        return ['allowed' => true, 'message' => 'OK', 'limit' => $limit, 'used' => $used];
    }

    /**
     * Increment counter setelah resource berhasil dibuat.
     * @param string $feature  'vpn' | 'wa_device' | 'proxy_route'
     */
    public function increment(int $userId, string $feature): bool
    {
        return $this->usageModel->increment($userId, $feature);
    }

    /**
     * Decrement counter setelah resource dihapus.
     * @param string $feature  'vpn' | 'wa_device' | 'proxy_route'
     */
    public function decrement(int $userId, string $feature): bool
    {
        return $this->usageModel->decrement($userId, $feature);
    }
}
