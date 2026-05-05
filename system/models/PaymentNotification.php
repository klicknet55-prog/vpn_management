<?php
/**
 * Model: PaymentNotification
 * Menyimpan raw webhook dari payment gateway untuk audit trail.
 */
class PaymentNotification
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Simpan notifikasi webhook mentah dari gateway */
    public function log(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO payment_notifications
             (payment_id, raw_payload, notification_type, status_code, trx_id, processed_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $data['payment_id']         ?? null,
            isset($data['raw_payload']) ? json_encode($data['raw_payload']) : null,
            $data['notification_type']  ?? 'payment',
            $data['status_code']        ?? null,
            $data['trx_id']             ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** Ambil log notifikasi berdasarkan trx_id/reference gateway */
    public function findByTrxId(string $trxId): array|false
    {
        $stmt = $this->db->prepare('SELECT * FROM payment_notifications WHERE trx_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$trxId]);
        return $stmt->fetch();
    }
}
