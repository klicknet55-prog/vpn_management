<?php
/**
 * NotificationService
 *
 * Mengirim notifikasi via Email (PHP mail()) dan WhatsApp (GoWA API).
 *
 * Variabel .env yang dibutuhkan:
 *   MAIL_FROM       = noreply@yourdomain.com
 *   MAIL_FROM_NAME  = YourApp
 *   APP_NAME        = YourApp          (opsional, default = APP_URL host)
 *
 * Untuk notifikasi WA, konfigurasi GoWA dan admin_send_device_id
 * harus sudah diset di Admin → Config → WhatsApp.
 */
class NotificationService
{
    private string $fromEmail;
    private string $fromName;
    private string $appName;
    private string $appUrl;

    public function __construct()
    {
        $this->fromEmail = env('MAIL_FROM',      'noreply@' . (parse_url(APP_URL, PHP_URL_HOST) ?: 'localhost'));
        $this->fromName  = env('MAIL_FROM_NAME', 'VPN & WA Manager');
        $this->appName   = env('APP_NAME',       'VPN & WA Manager');
        $this->appUrl    = APP_URL;
    }

    /**
     * Kirim notifikasi subscription expired.
     */
    public function sendSubscriptionExpired(string $toEmail, string $toName, string $expiredAt): bool
    {
        $subject = "[{$this->appName}] Subscription Anda Telah Berakhir";
        $body    = $this->renderExpiredTemplate($toName, $expiredAt);
        return $this->sendMail($toEmail, $toName, $subject, $body);
    }

    /**
     * Kirim notifikasi subscription hampir habis (3 hari lagi).
     */
    public function sendSubscriptionExpiringSoon(string $toEmail, string $toName, int $daysLeft, string $expiredAt): bool
    {
        $subject = "[{$this->appName}] Subscription Anda Akan Berakhir dalam {$daysLeft} Hari";
        $body    = $this->renderExpiringSoonTemplate($toName, $daysLeft, $expiredAt);
        return $this->sendMail($toEmail, $toName, $subject, $body);
    }

    /**
     * Kirim konfirmasi pembayaran berhasil.
     */
    public function sendPaymentSuccess(string $toEmail, string $toName, array $paymentData): bool
    {
        $subject = "[{$this->appName}] Pembayaran Berhasil - {$paymentData['invoice_number']}";
        $body    = $this->renderPaymentSuccessTemplate($toName, $paymentData);
        return $this->sendMail($toEmail, $toName, $subject, $body);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function sendMail(string $toEmail, string $toName, string $subject, string $htmlBody): bool
    {
        $toName  = mb_encode_mimeheader($toName, 'UTF-8', 'Q');
        $from    = mb_encode_mimeheader($this->fromName, 'UTF-8', 'Q');
        $subject = mb_encode_mimeheader($subject, 'UTF-8', 'Q');

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$from} <{$this->fromEmail}>\r\n";
        $headers .= "Reply-To: {$this->fromEmail}\r\n";
        $headers .= "X-Mailer: PHP/" . PHP_VERSION . "\r\n";

        return @mail($toEmail, $subject, $htmlBody, $headers);
    }

    private function renderExpiredTemplate(string $name, string $expiredAt): string
    {
        $upgradeUrl = $this->appUrl . '/user/upgrade.php';
        $name       = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $expiredAt  = htmlspecialchars($expiredAt, ENT_QUOTES, 'UTF-8');
        $appName    = htmlspecialchars($this->appName, ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <!DOCTYPE html><html><head><meta charset="UTF-8"></head>
        <body style="font-family:Arial,sans-serif;color:#333;line-height:1.6;max-width:560px;margin:0 auto;padding:20px;">
            <h2 style="color:#ef4444;">Subscription Anda Telah Berakhir</h2>
            <p>Halo <strong>{$name}</strong>,</p>
            <p>Subscription Anda di <strong>{$appName}</strong> telah berakhir pada <strong>{$expiredAt}</strong>.</p>
            <p>Untuk melanjutkan menggunakan fitur VPN, WhatsApp API, dan Proxy Route, silakan perpanjang atau upgrade paket Anda.</p>
            <p style="margin:24px 0;">
                <a href="{$upgradeUrl}" style="background:#3b82f6;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:bold;">
                    Upgrade Sekarang
                </a>
            </p>
            <p style="color:#888;font-size:0.875rem;">Jika Anda tidak melakukan tindakan apapun, fitur-fitur premium tidak akan tersedia.</p>
            <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
            <p style="color:#aaa;font-size:0.8rem;">Email ini dikirim otomatis oleh {$appName}. Harap tidak membalas email ini.</p>
        </body></html>
        HTML;
    }

    private function renderExpiringSoonTemplate(string $name, int $daysLeft, string $expiredAt): string
    {
        $upgradeUrl = $this->appUrl . '/user/upgrade.php';
        $name       = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $expiredAt  = htmlspecialchars($expiredAt, ENT_QUOTES, 'UTF-8');
        $appName    = htmlspecialchars($this->appName, ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <!DOCTYPE html><html><head><meta charset="UTF-8"></head>
        <body style="font-family:Arial,sans-serif;color:#333;line-height:1.6;max-width:560px;margin:0 auto;padding:20px;">
            <h2 style="color:#f59e0b;">Subscription Hampir Berakhir</h2>
            <p>Halo <strong>{$name}</strong>,</p>
            <p>Subscription Anda di <strong>{$appName}</strong> akan berakhir dalam <strong>{$daysLeft} hari</strong> (pada <strong>{$expiredAt}</strong>).</p>
            <p>Perpanjang sekarang agar layanan Anda tidak terputus.</p>
            <p style="margin:24px 0;">
                <a href="{$upgradeUrl}" style="background:#3b82f6;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:bold;">
                    Perpanjang Sekarang
                </a>
            </p>
            <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
            <p style="color:#aaa;font-size:0.8rem;">Email ini dikirim otomatis oleh {$appName}. Harap tidak membalas email ini.</p>
        </body></html>
        HTML;
    }

    private function renderPaymentSuccessTemplate(string $name, array $p): string
    {
        $subUrl  = $this->appUrl . '/user/subscription.php';
        $name    = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $appName = htmlspecialchars($this->appName, ENT_QUOTES, 'UTF-8');
        $inv     = htmlspecialchars($p['invoice_number'] ?? '-', ENT_QUOTES, 'UTF-8');
        $plan    = htmlspecialchars($p['plan_label']     ?? '-', ENT_QUOTES, 'UTF-8');
        $amount  = 'Rp ' . number_format((int)($p['amount'] ?? 0), 0, ',', '.');
        $paidAt  = htmlspecialchars($p['paid_at']        ?? '-', ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <!DOCTYPE html><html><head><meta charset="UTF-8"></head>
        <body style="font-family:Arial,sans-serif;color:#333;line-height:1.6;max-width:560px;margin:0 auto;padding:20px;">
            <h2 style="color:#22c55e;">Pembayaran Berhasil!</h2>
            <p>Halo <strong>{$name}</strong>,</p>
            <p>Pembayaran Anda telah berhasil dikonfirmasi dan subscription Anda sudah aktif.</p>
            <table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:0.9rem;">
                <tr><td style="padding:8px;border-bottom:1px solid #eee;color:#888;">No. Invoice</td><td style="padding:8px;border-bottom:1px solid #eee;"><strong>{$inv}</strong></td></tr>
                <tr><td style="padding:8px;border-bottom:1px solid #eee;color:#888;">Paket</td><td style="padding:8px;border-bottom:1px solid #eee;">{$plan}</td></tr>
                <tr><td style="padding:8px;border-bottom:1px solid #eee;color:#888;">Total Dibayar</td><td style="padding:8px;border-bottom:1px solid #eee;">{$amount}</td></tr>
                <tr><td style="padding:8px;color:#888;">Tanggal</td><td style="padding:8px;">{$paidAt}</td></tr>
            </table>
            <p style="margin:24px 0;">
                <a href="{$subUrl}" style="background:#3b82f6;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:bold;">
                    Lihat Subscription Saya
                </a>
            </p>
            <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
            <p style="color:#aaa;font-size:0.8rem;">Email ini dikirim otomatis oleh {$appName}. Harap tidak membalas email ini.</p>
        </body></html>
        HTML;
    }

    // ── WhatsApp Notifications ─────────────────────────────────────────────────

    /**
     * Kirim WA: subscription sudah berakhir.
     * @param  string  $phone    Nomor HP format internasional, misal 6281234567890
     */
    public function sendWaSubscriptionExpired(string $phone, string $name, string $expiredAt): bool
    {
        $upgradeUrl = $this->appUrl . '/user/upgrade.php';
        $message = "Halo *{$name}*,\n\n"
            . "Subscription Anda di *{$this->appName}* telah *berakhir* pada {$expiredAt}.\n\n"
            . "Segera perpanjang agar akses VPN, WA API & Proxy tidak terputus:\n"
            . "{$upgradeUrl}";
        return $this->sendWa($phone, $message);
    }

    /**
     * Kirim WA: subscription akan berakhir dalam N hari.
     */
    public function sendWaSubscriptionExpiringSoon(string $phone, string $name, int $daysLeft, string $expiredAt): bool
    {
        $upgradeUrl = $this->appUrl . '/user/upgrade.php';
        $message = "Halo *{$name}*,\n\n"
            . "⏰ Subscription Anda di *{$this->appName}* akan berakhir dalam *{$daysLeft} hari* (pada {$expiredAt}).\n\n"
            . "Perpanjang sekarang agar layanan tidak terputus:\n"
            . "{$upgradeUrl}";
        return $this->sendWa($phone, $message);
    }

    /**
     * Kirim WA: konfirmasi pembayaran berhasil.
     */
    public function sendWaPaymentSuccess(string $phone, string $name, array $paymentData): bool
    {
        $subUrl  = $this->appUrl . '/user/subscription.php';
        $inv     = $paymentData['invoice_number'] ?? '-';
        $plan    = $paymentData['plan_label']     ?? '-';
        $amount  = 'Rp ' . number_format((int)($paymentData['amount'] ?? 0), 0, ',', '.');
        $paidAt  = $paymentData['paid_at']        ?? '-';

        $message = "✅ *Pembayaran Berhasil!*\n\n"
            . "Halo *{$name}*,\n"
            . "Pembayaran Anda sudah dikonfirmasi dan subscription aktif.\n\n"
            . "No. Invoice : {$inv}\n"
            . "Paket       : {$plan}\n"
            . "Total       : {$amount}\n"
            . "Tanggal     : {$paidAt}\n\n"
            . "Lihat subscription Anda:\n{$subUrl}";
        return $this->sendWa($phone, $message);
    }

    /**
     * Kirim pesan WA via GoWA menggunakan admin_send_device_id.
     * Mengembalikan true jika berhasil, false jika device tidak dikonfigurasi
     * atau GoWA tidak dapat dihubungi.
     */
    private function sendWa(string $phone, string $message): bool
    {
        $phone = trim($phone);
        if ($phone === '') {
            return false;
        }

        // Pastikan fungsi GoWA sudah di-load
        if (!function_exists('gowaRequest')) {
            $gowaPath = __DIR__ . '/../../api/gowa.php';
            if (!file_exists($gowaPath)) {
                return false;
            }
            require_once $gowaPath;
        }

        // Ambil admin_send_device_id dari konfigurasi runtime
        $deviceId = $this->resolveAdminDeviceId();
        if ($deviceId === '') {
            return false;
        }

        try {
            $res = gowaRequest(
                'POST',
                '/send/message',
                ['phone' => $phone, 'message' => $message],
                ['X-Device-Id: ' . $deviceId]
            );
            return (bool) ($res['ok'] ?? false);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Baca admin_send_device_id dari api_configurations atau constant.
     */
    private function resolveAdminDeviceId(): string
    {
        // Coba dari DB terlebih dahulu
        try {
            if (function_exists('getDB')) {
                $db   = getDB();
                $stmt = $db->query(
                    "SELECT admin_send_device_id
                     FROM api_configurations
                     WHERE service_type = 'whatsapp' AND is_enabled = 1
                     ORDER BY updated_at DESC, id DESC
                     LIMIT 1"
                );
                $row = $stmt->fetch();
                if (!empty($row['admin_send_device_id'])) {
                    return trim((string) $row['admin_send_device_id']);
                }
            }
        } catch (Throwable $e) {
            // fallthrough
        }

        // Fallback ke constant (dari api/config.php)
        if (defined('GOWA_ADMIN_SEND_DEVICE_ID') && GOWA_ADMIN_SEND_DEVICE_ID !== '') {
            return trim(GOWA_ADMIN_SEND_DEVICE_ID);
        }

        return '';
    }
}
