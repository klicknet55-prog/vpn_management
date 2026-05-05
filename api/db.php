<?php
require_once __DIR__ . '/config.php';

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
 * @return array{merchant_code: string, api_key: string, base_url: string, sandbox: bool}
 */
function getPaymentGatewayConfig(PDO $db): array
{
    $keys = ['duitku.merchant_code', 'duitku.api_key', 'duitku.base_url', 'duitku.sandbox'];
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

    return [
        'merchant_code' => $map['duitku.merchant_code'] ?? DUITKU_MERCHANT_CODE,
        'api_key'       => $map['duitku.api_key'] ?? DUITKU_API_KEY,
        'base_url'      => $map['duitku.base_url'] ?? DUITKU_BASE_URL,
        'sandbox'       => isset($map['duitku.sandbox']) ? ($map['duitku.sandbox'] === '1') : DUITKU_SANDBOX,
    ];
}
