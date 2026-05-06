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
        $stmt = $this->db->prepare(
            'SELECT p.*, pl.label AS plan_label
             FROM payments p
             LEFT JOIN plans pl ON pl.id = p.plan_id
             WHERE p.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /** Ambil payment berdasarkan invoice number */
    public function findByInvoiceNumber(string $invoiceNumber): array|false
    {
        $stmt = $this->db->prepare(
            'SELECT p.*, pl.label AS plan_label
             FROM payments p
             LEFT JOIN plans pl ON pl.id = p.plan_id
             WHERE p.invoice_number = ?'
        );
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

    /** Update status dan data gateway setelah callback webhook */
    public function updateAfterPayment(int $id, array $data): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE payments
             SET status                     = ?,
                 gateway_transaction_id     = ?,
                 gateway_session_id         = ?,
                 payment_method             = ?,
                 paid_at                    = ?,
                 updated_at                 = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        return $stmt->execute([
            $data['status'],
            $data['transaction_id']           ?? $data['gateway_transaction_id'] ?? null,
            $data['session_id']               ?? $data['gateway_session_id'] ?? null,
            $data['payment_method']        ?? null,
            $data['paid_at']               ?? null,
            $id,
        ]);
    }

    /** Generate nomor invoice unik: INV-YYYYMMDD-XXXXXXXX (dengan random suffix) */
    public function generateInvoiceNumber(): string
    {
        $date  = date('Ymd');
        $stmt  = $this->db->prepare(
            "SELECT COUNT(*) FROM payments WHERE invoice_number LIKE ?"
        );
        $stmt->execute(["INV-{$date}-%"]);
        $count = (int) $stmt->fetchColumn();
        $suffix = str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
        $random = substr(md5(microtime(true) . random_bytes(16)), 0, 4);
        return sprintf('INV-%s-%s%s', $date, $suffix, $random);
    }
}
