<?php
/**
 * Model: Payment
 * Melacak transaksi pembayaran user.
 */
class Payment
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Buat record payment baru, return ID yang baru dibuat */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO payments
             (user_id, plan_id, amount, invoice_number, status, invoice_expired_at, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['user_id'],
            $data['plan_id'],
            $data['amount'],
            $data['invoice_number'],
            $data['status']           ?? 'pending',
            $data['invoice_expired_at'] ?? null,
            $data['notes']            ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** Ambil payment berdasarkan ID */
    public function findById(int $id): array|false
    {
        $stmt = $this->db->prepare('SELECT * FROM payments WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /** Ambil payment berdasarkan invoice number */
    public function findByInvoiceNumber(string $invoiceNumber): array|false
    {
        $stmt = $this->db->prepare('SELECT * FROM payments WHERE invoice_number = ?');
        $stmt->execute([$invoiceNumber]);
        return $stmt->fetch();
    }

    /** Ambil semua payment satu user */
    public function getByUserId(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.*, pl.label AS plan_label
             FROM payments p
             JOIN plans pl ON pl.id = p.plan_id
             WHERE p.user_id = ?
             ORDER BY p.created_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /** Update status dan data iPaymu setelah callback webhook */
    public function updateAfterPayment(int $id, array $data): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE payments
             SET status                  = ?,
                 ipaymu_transaction_id   = ?,
                 ipaymu_session_id       = ?,
                 payment_method         = ?,
                 paid_at                = ?,
                 updated_at             = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        return $stmt->execute([
            $data['status'],
            $data['ipaymu_transaction_id'] ?? null,
            $data['ipaymu_session_id']     ?? null,
            $data['payment_method']        ?? null,
            $data['paid_at']               ?? null,
            $id,
        ]);
    }

    /** Generate nomor invoice unik: INV-YYYYMMDD-XXXX */
    public function generateInvoiceNumber(): string
    {
        $date  = date('Ymd');
        $stmt  = $this->db->prepare(
            "SELECT COUNT(*) FROM payments WHERE invoice_number LIKE ?"
        );
        $stmt->execute(["INV-{$date}-%"]);
        $count = (int) $stmt->fetchColumn();
        return sprintf('INV-%s-%04d', $date, $count + 1);
    }
}
