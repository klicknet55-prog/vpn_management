<?php
/**
 * Model: Invoice
 * Faktur yang dikirim ke user.
 */
class Invoice
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Buat invoice baru, return ID */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO invoices (user_id, payment_id, plan_id, invoice_number, amount, due_date, status, payment_url)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['user_id'],
            $data['payment_id'],
            $data['plan_id'],
            $data['invoice_number'],
            $data['amount'],
            $data['due_date'],
            $data['status']      ?? 'draft',
            $data['payment_url'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** Ambil invoice berdasarkan ID (join user & plan) */
    public function findById(int $id): array|false
    {
        $stmt = $this->db->prepare(
            'SELECT inv.*, u.full_name, u.email, p.label AS plan_label
             FROM invoices inv
             JOIN users u ON u.id = inv.user_id
             JOIN plans p ON p.id = inv.plan_id
             WHERE inv.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /** Ambil invoice berdasarkan invoice_number */
    public function findByNumber(string $invoiceNumber): array|false
    {
        $stmt = $this->db->prepare('SELECT * FROM invoices WHERE invoice_number = ?');
        $stmt->execute([$invoiceNumber]);
        return $stmt->fetch();
    }

    /** Ambil semua invoice user */
    public function getByUserId(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT inv.*, p.label AS plan_label
             FROM invoices inv
             JOIN plans p ON p.id = inv.plan_id
             WHERE inv.user_id = ?
             ORDER BY inv.created_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /** Update status invoice */
    public function updateStatus(int $id, string $status, ?string $paymentUrl = null): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE invoices
             SET status = ?,
                 payment_url = COALESCE(?, payment_url),
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        return $stmt->execute([$status, $paymentUrl, $id]);
    }

    /** Update payment_url setelah iPaymu memberikan link bayar */
    public function setPaymentUrl(int $id, string $url): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE invoices SET payment_url = ?, status = "sent", updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        return $stmt->execute([$url, $id]);
    }
}
