<?php
/**
 * Public invoice link helpers.
 *
 * Signed URL format:
 * /public/invoice.php?invoice=INV-...&exp=...&sig=...
 */

require_once __DIR__ . '/config.php';

function invoicePublicSecret(): string
{
    $secret = (string) env('INVOICE_PUBLIC_SECRET', '');
    if ($secret !== '') {
        return $secret;
    }

    // Fallback agar tetap jalan bila env belum diisi.
    return (string) env('CRON_SECRET', (string) env('DUITKU_API_KEY', 'default-invoice-secret'));
}

function invoicePublicSign(string $invoiceNumber, int $expiresAt): string
{
    $payload = $invoiceNumber . '|' . $expiresAt;
    return hash_hmac('sha256', $payload, invoicePublicSecret());
}

function invoicePublicBuildUrl(string $invoiceNumber, ?int $expiresAt = null, string $status = 'success'): string
{
    $expiresAt = $expiresAt ?? (time() + (7 * 24 * 3600));
    $sig = invoicePublicSign($invoiceNumber, $expiresAt);

    return APP_URL
        . '/public/invoice.php?invoice=' . urlencode($invoiceNumber)
        . '&exp=' . $expiresAt
        . '&sig=' . urlencode($sig)
        . '&status=' . urlencode($status);
}

function invoicePublicValidate(string $invoiceNumber, int $expiresAt, string $signature): bool
{
    if ($invoiceNumber === '' || $expiresAt <= 0 || $signature === '') {
        return false;
    }

    if ($expiresAt < time()) {
        return false;
    }

    $expected = invoicePublicSign($invoiceNumber, $expiresAt);
    return hash_equals(strtolower($expected), strtolower($signature));
}
