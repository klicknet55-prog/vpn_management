<?php
/**
 * Model: UserFeaturesUsage
 * Melacak berapa kali user membuat VPN, WA Device, Proxy Route.
 */
class UserFeaturesUsage
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Ambil usage record user, auto-create jika belum ada */
    public function getOrCreate(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM user_features_usage WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row) {
            $this->db->prepare(
                'INSERT IGNORE INTO user_features_usage (user_id) VALUES (?)'
            )->execute([$userId]);
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
        }

        return $row;
    }

    /**
     * Cek apakah user masih bisa membuat resource tertentu.
     * @param string $feature  'vpn' | 'wa_device' | 'proxy_route'
     */
    public function canCreate(int $userId, string $feature, int $limit): bool
    {
        $usage   = $this->getOrCreate($userId);
        $colMap  = [
            'vpn'         => 'vpn_count',
            'wa_device'   => 'wa_device_count',
            'proxy_route' => 'proxy_route_count',
        ];
        $col = $colMap[$feature] ?? null;
        if (!$col) return false;

        return (int)$usage[$col] < $limit;
    }

    /**
     * Tambah counter setelah user berhasil membuat resource.
     * @param string $feature  'vpn' | 'wa_device' | 'proxy_route'
     */
    public function increment(int $userId, string $feature): bool
    {
        $colMap = [
            'vpn'         => 'vpn_count',
            'wa_device'   => 'wa_device_count',
            'proxy_route' => 'proxy_route_count',
        ];
        $col = $colMap[$feature] ?? null;
        if (!$col) return false;

        $stmt = $this->db->prepare(
            "UPDATE user_features_usage
             SET {$col} = {$col} + 1, updated_at = CURRENT_TIMESTAMP
             WHERE user_id = ?"
        );
        return $stmt->execute([$userId]);
    }

    /**
     * Reset semua counter (dipanggil saat user renew/upgrade plan).
     */
    public function reset(int $userId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE user_features_usage
             SET vpn_count = 0, wa_device_count = 0, proxy_route_count = 0,
                 reset_at = NOW(), updated_at = CURRENT_TIMESTAMP
             WHERE user_id = ?'
        );
        return $stmt->execute([$userId]);
    }

    /**
     * Kurangi counter (dipanggil saat resource dihapus).
     * @param string $feature  'vpn' | 'wa_device' | 'proxy_route'
     */
    public function decrement(int $userId, string $feature): bool
    {
        $colMap = [
            'vpn'         => 'vpn_count',
            'wa_device'   => 'wa_device_count',
            'proxy_route' => 'proxy_route_count',
        ];
        $col = $colMap[$feature] ?? null;
        if (!$col) return false;

        $stmt = $this->db->prepare(
            "UPDATE user_features_usage
             SET {$col} = GREATEST(0, {$col} - 1), updated_at = CURRENT_TIMESTAMP
             WHERE user_id = ?"
        );
        return $stmt->execute([$userId]);
    }
}
