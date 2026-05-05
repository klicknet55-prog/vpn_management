<?php
/**
 * Subscription Middleware Helpers
 *
 * Fungsi pendek yang dipanggil di awal setiap controller/halaman
 * untuk memblokir akses atau validasi limit fitur.
 *
 * Pola yang dipakai: function-based (sesuai gaya kode existing di project ini).
 */

require_once __DIR__ . '/../services/SubscriptionService.php';
require_once __DIR__ . '/../services/FeatureLimitService.php';

/**
 * Pastikan subscription user aktif.
 * Jika tidak, hentikan eksekusi dengan respons JSON 403 (untuk API)
 * atau redirect ke halaman upgrade (untuk halaman HTML).
 *
 * @param int    $userId
 * @param PDO    $db
 * @param bool   $isApi    true = return JSON, false = redirect
 */
function requireActiveSubscription(int $userId, PDO $db, bool $isApi = true): void
{
    $service = new SubscriptionService($db);

    if (!$service->isActive($userId)) {
        if ($isApi) {
            http_response_code(403);
            echo json_encode([
                'ok'    => false,
                'error' => 'Subscription Anda tidak aktif atau sudah expired. Silakan upgrade paket.',
                'code'  => 'SUBSCRIPTION_INACTIVE',
            ]);
            exit;
        } else {
            // Untuk halaman HTML: redirect ke halaman subscription
            header('Location: /user/subscription.php?reason=expired');
            exit;
        }
    }
}

/**
 * Validasi apakah user boleh membuat resource baru.
 * Jika tidak boleh, hentikan dengan JSON error.
 * Jika boleh, return array ['limit' => int, 'used' => int].
 *
 * @param int    $userId
 * @param string $feature  'vpn' | 'wa_device' | 'proxy_route'
 * @param PDO    $db
 */
function requireFeatureLimit(int $userId, string $feature, PDO $db): array
{
    $service = new FeatureLimitService($db);
    $result  = $service->check($userId, $feature);

    if (!$result['allowed']) {
        http_response_code(403);
        echo json_encode([
            'ok'    => false,
            'error' => $result['message'],
            'code'  => 'FEATURE_LIMIT_EXCEEDED',
            'limit' => $result['limit'],
            'used'  => $result['used'],
        ]);
        exit;
    }

    return $result;
}

/**
 * Increment counter setelah resource berhasil dibuat.
 *
 * @param int    $userId
 * @param string $feature  'vpn' | 'wa_device' | 'proxy_route'
 * @param PDO    $db
 */
function afterFeatureCreated(int $userId, string $feature, PDO $db): void
{
    $service = new FeatureLimitService($db);
    $service->increment($userId, $feature);
}

/**
 * Decrement counter setelah resource dihapus.
 *
 * @param int    $userId
 * @param string $feature  'vpn' | 'wa_device' | 'proxy_route'
 * @param PDO    $db
 */
function afterFeatureDeleted(int $userId, string $feature, PDO $db): void
{
    $service = new FeatureLimitService($db);
    $service->decrement($userId, $feature);
}
