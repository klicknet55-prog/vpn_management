<?php
require_once __DIR__ . '/config.php';

/**
 * Normalize daftar metode pembayaran Duitku ke array kode unik 2 huruf.
 *
 * @param string|array|null $raw
 * @return array<int, string>
 */
function normalizeDuitkuPaymentMethods($raw): array
{
    if (is_array($raw)) {
        $parts = $raw;
    } elseif (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $parts = $decoded;
        } else {
            $parts = preg_split('/[\s,;|]+/', $raw) ?: [];
        }
    } else {
        $parts = [];
    }

    $seen = [];
    $out = [];
    foreach ($parts as $part) {
        $code = strtoupper(trim((string) $part));
        if (!preg_match('/^[A-Z0-9]{2}$/', $code)) {
            continue;
        }
        if (isset($seen[$code])) {
            continue;
        }
        $seen[$code] = true;
        $out[] = $code;
    }

    return $out;
}

/**
 * Konversi kode metode pembayaran Duitku ke nama tampilan.
 *
 * @param string $code  Kode 2-huruf (mis. 'SP', 'BC')
 * @return string       Nama ramah (mis. 'QRIS ShopeePay', 'BCA Virtual Account')
 */
function duitkuMethodLabel(string $code): string
{
    static $catalog = [
        'BC' => 'BCA Virtual Account',
        'M2' => 'Mandiri Virtual Account',
        'VA' => 'Maybank Virtual Account',
        'I1' => 'BNI Virtual Account',
        'B1' => 'CIMB Niaga Virtual Account',
        'BT' => 'Permata Virtual Account',
        'A1' => 'ATM Bersama',
        'BR' => 'BRIVA',
        'IR' => 'Indomaret',
        'FT' => 'Retail (Pegadaian/ALFA/Pos)',
        'OV' => 'OVO',
        'DA' => 'DANA',
        'SP' => 'QRIS ShopeePay',
        'NQ' => 'QRIS Nobu',
        'GQ' => 'QRIS Gudang Voucher',
        'SQ' => 'QRIS Nusapay',
        'VC' => 'Kartu Kredit',
        'JP' => 'Jenius Pay',
        'DN' => 'Indodana Paylater',
        'AT' => 'ATOME',
        'T1' => 'Tokopedia Card',
        'T2' => 'Tokopedia E-Wallet',
        'T3' => 'Tokopedia Lainnya',
    ];

    $upper = strtoupper(trim($code));
    return $catalog[$upper] ?? ($upper !== '' ? $upper : '-');
}

/**
 * Get a singleton PDO database connection.
 */
function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_NAME
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/**
 * Baca konfigurasi Duitku dari app_settings (UI admin), fallback ke konstanta .env.
 *
 * @return array{merchant_code: string, api_key: string, base_url: string, sandbox: bool, enabled_payment_methods: array<int, string>}
 */
function getPaymentGatewayConfig(PDO $db): array
{
     $keys = ['duitku.merchant_code', 'duitku.api_key', 'duitku.base_url', 'duitku.sandbox', 'duitku.enabled_payment_methods'];
    try {
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $db->prepare("SELECT config_key, config_value FROM app_settings WHERE config_key IN ($placeholders)");
        $stmt->execute($keys);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        $rows = [];
    }

    $map = [];
    foreach ($rows as $row) {
        $k = (string) ($row['config_key'] ?? '');
        if ($k !== '') {
            $map[$k] = (string) ($row['config_value'] ?? '');
        }
    }

    $enabledMethods = normalizeDuitkuPaymentMethods($map['duitku.enabled_payment_methods'] ?? '');
    if (empty($enabledMethods)) {
        $enabledMethods = ['BC'];
    }

    return [
        'merchant_code' => $map['duitku.merchant_code'] ?? DUITKU_MERCHANT_CODE,
        'api_key'       => $map['duitku.api_key'] ?? DUITKU_API_KEY,
        'base_url'      => $map['duitku.base_url'] ?? DUITKU_BASE_URL,
        'sandbox'       => isset($map['duitku.sandbox']) ? ($map['duitku.sandbox'] === '1') : DUITKU_SANDBOX,
        'enabled_payment_methods' => $enabledMethods,
    ];
}
