<?php
/**
 * Model: Plan
 * Merepresentasikan paket berlangganan (free, basic, premium).
 */
class Plan
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Ambil semua plan yang aktif */
    public function getAll(): array
    {
        $stmt = $this->db->query('SELECT * FROM plans WHERE is_active = 1 ORDER BY price ASC');
        return $stmt->fetchAll();
    }

    /** Ambil plan berdasarkan ID */
    public function findById(int $id): array|false
    {
        $stmt = $this->db->prepare('SELECT * FROM plans WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /** Ambil plan berdasarkan nama (free/basic/premium) */
    public function findByName(string $name): array|false
    {
        $stmt = $this->db->prepare('SELECT * FROM plans WHERE name = ? AND is_active = 1');
        $stmt->execute([$name]);
        return $stmt->fetch();
    }
}
