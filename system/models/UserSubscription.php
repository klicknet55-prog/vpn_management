<?php
/**
 * Model: UserSubscription
 * Status langganan masing-masing user.
 */
class UserSubscription
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Ambil subscription aktif user (join dengan plans).
     */
    public function getByUserId(int $userId): array|false
    {
        $stmt = $this->db->prepare(
            'SELECT us.*, p.name AS plan_name, p.label AS plan_label,
                    p.vpn_limit, p.wa_device_limit, p.proxy_route_limit, p.duration_days
             FROM user_subscriptions us
             JOIN plans p ON p.id = us.plan_id
             WHERE us.user_id = ?'
        );
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }

    /**
     * Cek apakah subscription user masih aktif dan belum expired.
     */
    public function isActive(int $userId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM user_subscriptions
             WHERE user_id = ? AND is_active = 1 AND expires_at > NOW()'
        );
        $stmt->execute([$userId]);
        return (bool) $stmt->fetch();
    }

    /**
     * Buat atau update subscription user.
     * Dipakai saat register (assign free plan) dan saat payment berhasil.
     */
    public function upsert(int $userId, int $planId, string $startedAt, string $expiresAt): bool
    {
        $stmt = $this->db->prepare(
            'INSERT INTO user_subscriptions (user_id, plan_id, started_at, expires_at, is_active, disabled_reason)
             VALUES (?, ?, ?, ?, 1, NULL)
             ON DUPLICATE KEY UPDATE
               plan_id         = VALUES(plan_id),
               started_at      = VALUES(started_at),
               expires_at      = VALUES(expires_at),
               is_active       = 1,
               disabled_reason = NULL,
               updated_at      = CURRENT_TIMESTAMP'
        );
        return $stmt->execute([$userId, $planId, $startedAt, $expiresAt]);
    }

    /**
     * Blokir subscription user (expired atau manual block).
     */
    public function disable(int $userId, string $reason = 'expired'): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE user_subscriptions
             SET is_active = 0, disabled_reason = ?, updated_at = CURRENT_TIMESTAMP
             WHERE user_id = ?'
        );
        return $stmt->execute([$reason, $userId]);
    }

    /**
     * Ambil semua subscription yang sudah expired tapi masih is_active = 1.
     * Dipakai oleh scheduler/cron.
     */
    public function getExpiredActive(): array
    {
        $stmt = $this->db->query(
            'SELECT us.user_id, us.id, us.expires_at, p.name AS plan_name
             FROM user_subscriptions us
             JOIN plans p ON p.id = us.plan_id
             WHERE us.is_active = 1 AND us.expires_at <= NOW()'
        );
        return $stmt->fetchAll();
    }
}
