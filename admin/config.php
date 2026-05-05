<?php
session_start();
if (empty($_SESSION['logged_in'])) {
    header('Location: ../login.php');
    exit();
}

$sessionRoles = $_SESSION['roles'] ?? [];
$isAdmin = in_array('admin', $sessionRoles, true) || in_array('super_admin', $sessionRoles, true);
if (!$isAdmin) {
    header('Location: ../index.php');
    exit();
}

require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/wa-send-runtime.php';
require_once __DIR__ . '/../system/seo.php';

$seoConfigError = '';
$seoConfigSuccess = '';
$webConfigError = '';
$webConfigSuccess = '';
$waConfigError = '';
$waConfigSuccess = '';
$vpnConfigError = '';
$vpnConfigSuccess = '';
$planConfigError = '';
$planConfigSuccess = '';
$plans = [];
$pgConfigError = '';
$pgConfigSuccess = '';
$pgConfig = [
    'merchant_code' => DUITKU_MERCHANT_CODE,
    'api_key'       => DUITKU_API_KEY,
    'base_url'      => DUITKU_BASE_URL,
    'sandbox'       => DUITKU_SANDBOX ? '1' : '0',
];

$seoConfig = [
    'site_title' => '',
    'meta_description' => '',
    'meta_keywords' => '',
    'og_image_url' => '',
    'robots_policy' => 'index,follow',
    'google_analytics_id' => '',
    'google_tag_manager_id' => '',
];

$webpageConfig = [
    'company_name' => 'TEAM KLICKnet',
    'template_name' => 'daynight-default',
    'font_family' => 'Poppins',
    'logo_url' => '',
    'favicon_url' => '',
];

$waConfig = [
    'base_url' => GOWA_BASE_URL,
    'api_key' => GOWA_USERNAME,
    'api_secret' => GOWA_PASSWORD,
    'admin_send_device_id' => GOWA_ADMIN_SEND_DEVICE_ID,
];

$vpnConfig = [
    'provider_name' => 'FastAPI Libreswan L2TP',
    'base_url' => '',
    'api_key' => '',
    'api_secret' => '',
    'access_token' => '',
    'webhook_url' => '',
    'verify_token' => '',
    'auth_scheme' => 'basic',
    'endpoint_users' => '/users',
    'endpoint_port_forwardings' => '/port-forwardings',
    'request_timeout_seconds' => 30,
    'is_enabled' => 1,
];
$waDevices = [];
$waRuntimeConfig = waSendRuntimeConfig();
$waQueueSummary = [
    'pending' => 0,
    'processing' => 0,
    'sent' => 0,
    'failed' => 0,
    'total' => 0,
];
$waQueueRecent = [];
$postAction = (string) ($_POST['action'] ?? '');

function normalizeDeviceLabel(string $label, string $deviceId): string
{
    $label = trim($label);
    $deviceId = trim($deviceId);
    if ($label === '') {
        return $deviceId !== '' ? ('Device ' . substr($deviceId, 0, 8)) : 'Device';
    }

    $isUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $label) === 1;
    if ($isUuid || ($deviceId !== '' && strcasecmp($label, $deviceId) === 0)) {
        return $deviceId !== '' ? ('Device ' . substr($deviceId, 0, 8)) : 'Device';
    }

    return $label;
}

function ensureAppSettingsTable(PDO $db): void
{
    $db->exec(
        'CREATE TABLE IF NOT EXISTS app_settings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            config_key VARCHAR(100) NOT NULL UNIQUE,
            config_value LONGTEXT NULL,
            updated_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function loadAppSettings(PDO $db, array $keys): array
{
    if (!$keys) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $db->prepare('SELECT config_key, config_value FROM app_settings WHERE config_key IN (' . $placeholders . ')');
    $stmt->execute(array_values($keys));
    $rows = $stmt->fetchAll();

    $result = [];
    foreach ($rows as $row) {
        $k = (string) ($row['config_key'] ?? '');
        if ($k !== '') {
            $result[$k] = (string) ($row['config_value'] ?? '');
        }
    }

    return $result;
}

function saveAppSetting(PDO $db, string $key, ?string $value, ?int $updatedBy = null): void
{
    $stmt = $db->prepare(
        'INSERT INTO app_settings (config_key, config_value, updated_by, created_at, updated_at)
         VALUES (:config_key, :config_value, :updated_by, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            config_value = VALUES(config_value),
            updated_by = VALUES(updated_by),
            updated_at = NOW()'
    );

    $stmt->execute([
        'config_key' => $key,
        'config_value' => $value,
        'updated_by' => $updatedBy,
    ]);
}

function brandingUploadDir(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'branding';
}

function ensureBrandingUploadDir(): string
{
    $dir = brandingUploadDir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Gagal membuat folder upload branding.');
    }

    return $dir;
}

function appBasePathFromScript(): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    if ($scriptDir === '' || $scriptDir === '.') {
        return '';
    }

    $base = dirname($scriptDir);
    if ($base === '/' || $base === '\\' || $base === '.') {
        return '';
    }

    return rtrim(str_replace('\\', '/', $base), '/');
}

function moveUploadedBrandingImage(array $file, string $type): string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload file gagal. Silakan coba lagi.');
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('File upload tidak valid.');
    }

    $maxBytes = 2 * 1024 * 1024;
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        throw new RuntimeException('Ukuran file maksimal 2MB.');
    }

    $allowedByType = [
        'logo' => [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        ],
        'favicon' => [
            'image/png' => 'png',
            'image/x-icon' => 'ico',
            'image/vnd.microsoft.icon' => 'ico',
        ],
    ];

    $allowed = $allowedByType[$type] ?? [];
    if (!$allowed) {
        throw new RuntimeException('Tipe upload tidak didukung.');
    }

    $mimeType = '';
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo !== false) {
        $detected = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);
        if (is_string($detected)) {
            $mimeType = strtolower(trim($detected));
        }
    }

    $extension = $allowed[$mimeType] ?? '';
    if ($extension === '') {
        $originalName = (string) ($file['name'] ?? '');
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExtensions = array_values(array_unique(array_map('strval', $allowed)));
        if (in_array($ext, $allowedExtensions, true)) {
            $extension = $ext;
        }
    }

    if ($extension === '') {
        if ($type === 'logo') {
            throw new RuntimeException('Format logo tidak didukung. Gunakan PNG, JPG, atau WEBP.');
        }
        throw new RuntimeException('Format favicon tidak didukung. Gunakan PNG atau ICO.');
    }

    $uploadDir = ensureBrandingUploadDir();
    try {
        $random = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $random = substr(str_replace('.', '', uniqid('', true)), -8);
    }
    $filename = $type . '-' . date('YmdHis') . '-' . $random . '.' . $extension;
    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        throw new RuntimeException('Gagal menyimpan file upload.');
    }

    $publicBase = appBasePathFromScript();
    return ($publicBase !== '' ? $publicBase : '') . '/uploads/branding/' . $filename;
}

try {
    $db = getDB();
    ensureAppSettingsTable($db);

    try {
        $db->exec('ALTER TABLE api_configurations ADD COLUMN admin_send_device_id VARCHAR(100) NULL AFTER verify_token');
    } catch (Throwable $e) {
        // Column may already exist.
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postAction === 'save_seo_config') {
        $siteTitle = trim((string) ($_POST['seo_site_title'] ?? ''));
        $metaDescription = trim((string) ($_POST['seo_meta_description'] ?? ''));
        $metaKeywords = trim((string) ($_POST['seo_meta_keywords'] ?? ''));
        $ogImageUrl = trim((string) ($_POST['seo_og_image_url'] ?? ''));
        $robotsPolicy = trim((string) ($_POST['seo_robots_policy'] ?? 'index,follow'));
        $googleAnalyticsId = strtoupper(trim((string) ($_POST['seo_google_analytics_id'] ?? '')));
        $googleTagManagerId = strtoupper(trim((string) ($_POST['seo_google_tag_manager_id'] ?? '')));

        if ($ogImageUrl !== '' && !preg_match('/^https?:\/\//i', $ogImageUrl)) {
            throw new RuntimeException('URL Open Graph image harus diawali http:// atau https://');
        }

        if ($googleAnalyticsId !== '' && !preg_match('/^G-[A-Z0-9]+$/', $googleAnalyticsId)) {
            throw new RuntimeException('Format Google Analytics ID tidak valid. Contoh: G-ABC123DEF4');
        }

        if ($googleTagManagerId !== '' && !preg_match('/^GTM-[A-Z0-9]+$/', $googleTagManagerId)) {
            throw new RuntimeException('Format Google Tag Manager ID tidak valid. Contoh: GTM-ABC1234');
        }

        if (!in_array($robotsPolicy, ['index,follow', 'noindex,follow', 'noindex,nofollow'], true)) {
            $robotsPolicy = 'index,follow';
        }

        $updatedBy = (int) ($_SESSION['user_id'] ?? 0) ?: null;
        saveAppSetting($db, 'seo.site_title', $siteTitle, $updatedBy);
        saveAppSetting($db, 'seo.meta_description', $metaDescription, $updatedBy);
        saveAppSetting($db, 'seo.meta_keywords', $metaKeywords, $updatedBy);
        saveAppSetting($db, 'seo.og_image_url', $ogImageUrl, $updatedBy);
        saveAppSetting($db, 'seo.robots_policy', $robotsPolicy, $updatedBy);
        saveAppSetting($db, 'seo.google_analytics_id', $googleAnalyticsId, $updatedBy);
        saveAppSetting($db, 'seo.google_tag_manager_id', $googleTagManagerId, $updatedBy);

        $seoConfigSuccess = 'Pengaturan SEO berhasil disimpan.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postAction === 'save_webpage_config') {
        $companyName = trim((string) ($_POST['web_company_name'] ?? 'TEAM KLICKnet'));
        $templateName = trim((string) ($_POST['web_template_name'] ?? 'daynight-default'));
        $fontFamily = trim((string) ($_POST['web_font_family'] ?? 'Poppins'));

        $currentSettings = loadAppSettings($db, ['web.logo_url', 'web.favicon_url']);
        $logoUrl = trim((string) ($currentSettings['web.logo_url'] ?? ''));
        $faviconUrl = trim((string) ($currentSettings['web.favicon_url'] ?? ''));

        if ($companyName === '') {
            throw new RuntimeException('Nama perusahaan wajib diisi.');
        }
        if (mb_strlen($companyName) > 100) {
            throw new RuntimeException('Nama perusahaan maksimal 100 karakter.');
        }

        if (!empty($_POST['web_remove_logo'])) {
            $logoUrl = '';
        }
        if (!empty($_POST['web_remove_favicon'])) {
            $faviconUrl = '';
        }

        $logoFile = $_FILES['web_logo_file'] ?? null;
        if (is_array($logoFile) && (int) ($logoFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $logoUrl = moveUploadedBrandingImage($logoFile, 'logo');
        }

        $faviconFile = $_FILES['web_favicon_file'] ?? null;
        if (is_array($faviconFile) && (int) ($faviconFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $faviconUrl = moveUploadedBrandingImage($faviconFile, 'favicon');
        }

        $updatedBy = (int) ($_SESSION['user_id'] ?? 0) ?: null;
        saveAppSetting($db, 'web.company_name', $companyName, $updatedBy);
        saveAppSetting($db, 'web.template_name', $templateName, $updatedBy);
        saveAppSetting($db, 'web.font_family', $fontFamily, $updatedBy);
        saveAppSetting($db, 'web.logo_url', $logoUrl, $updatedBy);
        saveAppSetting($db, 'web.favicon_url', $faviconUrl, $updatedBy);

        $webConfigSuccess = 'Pengaturan Web Page berhasil disimpan.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postAction === 'save_wa_config') {
        $waBaseUrl = trim((string) ($_POST['wa_base_url'] ?? ''));
        $waUsername = trim((string) ($_POST['wa_username'] ?? ''));
        $waPassword = (string) ($_POST['wa_password'] ?? '');
        $waAdminDeviceId = trim((string) ($_POST['wa_admin_device_id'] ?? ''));

        if ($waBaseUrl === '' || $waUsername === '' || $waPassword === '') {
            throw new RuntimeException('Server WA API, username, dan password wajib diisi.');
        }
        if (!preg_match('/^https?:\/\//i', $waBaseUrl)) {
            throw new RuntimeException('Server WA API harus diawali http:// atau https://');
        }

        $cfgStmt = $db->query("SELECT id FROM api_configurations WHERE service_type = 'whatsapp' ORDER BY updated_at DESC, id DESC LIMIT 1");
        $cfgId = (int) ($cfgStmt->fetchColumn() ?: 0);

        if ($cfgId > 0) {
            $update = $db->prepare(
                'UPDATE api_configurations
                 SET provider_name = :provider_name,
                     base_url = :base_url,
                     api_key = :api_key,
                     api_secret = :api_secret,
                     admin_send_device_id = :admin_send_device_id,
                     request_timeout_seconds = :request_timeout_seconds,
                     is_enabled = 1,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $update->execute([
                'provider_name' => 'GoWA',
                'base_url' => $waBaseUrl,
                'api_key' => $waUsername,
                'api_secret' => $waPassword,
                'admin_send_device_id' => $waAdminDeviceId !== '' ? $waAdminDeviceId : null,
                'request_timeout_seconds' => 30,
                'id' => $cfgId,
            ]);
        } else {
            $insert = $db->prepare(
                'INSERT INTO api_configurations
                    (service_type, provider_name, base_url, api_key, api_secret, admin_send_device_id, request_timeout_seconds, is_enabled, created_by, created_at, updated_at)
                 VALUES
                    (:service_type, :provider_name, :base_url, :api_key, :api_secret, :admin_send_device_id, :request_timeout_seconds, 1, :created_by, NOW(), NOW())'
            );
            $insert->execute([
                'service_type' => 'whatsapp',
                'provider_name' => 'GoWA',
                'base_url' => $waBaseUrl,
                'api_key' => $waUsername,
                'api_secret' => $waPassword,
                'admin_send_device_id' => $waAdminDeviceId !== '' ? $waAdminDeviceId : null,
                'request_timeout_seconds' => 30,
                'created_by' => (int) ($_SESSION['user_id'] ?? 0) ?: null,
            ]);
        }

        $waConfigSuccess = 'Konfigurasi WhatsApp API berhasil diperbarui.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postAction === 'save_wa_runtime_config') {
        $delayMinMs = max(0, (int) ($_POST['wa_delay_min_ms'] ?? WA_SEND_DELAY_MIN_MS));
        $delayMaxMs = max(0, (int) ($_POST['wa_delay_max_ms'] ?? WA_SEND_DELAY_MAX_MS));
        $burstLimit = max(1, (int) ($_POST['wa_burst_limit'] ?? WA_SEND_BURST_LIMIT));
        $burstWindowSeconds = max(1, (int) ($_POST['wa_burst_window_seconds'] ?? WA_SEND_BURST_WINDOW_SECONDS));
        $burstPauseMinMs = max(0, (int) ($_POST['wa_burst_pause_min_ms'] ?? WA_SEND_BURST_PAUSE_MIN_MS));
        $burstPauseMaxMs = max(0, (int) ($_POST['wa_burst_pause_max_ms'] ?? WA_SEND_BURST_PAUSE_MAX_MS));
        $queueBatchLimit = max(1, (int) ($_POST['wa_queue_batch_limit'] ?? WA_QUEUE_BATCH_LIMIT));
        $queueMaxAttempts = max(1, (int) ($_POST['wa_queue_max_attempts'] ?? WA_QUEUE_MAX_ATTEMPTS));
        $adminQueueEnabled = !empty($_POST['wa_admin_queue_enabled']) ? '1' : '0';
        $userQueueEnabled = !empty($_POST['wa_user_queue_enabled']) ? '1' : '0';

        if ($delayMaxMs < $delayMinMs) {
            throw new RuntimeException('Delay max harus lebih besar atau sama dengan delay min.');
        }
        if ($burstPauseMaxMs < $burstPauseMinMs) {
            throw new RuntimeException('Pause burst max harus lebih besar atau sama dengan pause burst min.');
        }

        $updatedBy = (int) ($_SESSION['user_id'] ?? 0) ?: null;
        saveAppSetting($db, 'wa_runtime.delay_min_ms', (string) $delayMinMs, $updatedBy);
        saveAppSetting($db, 'wa_runtime.delay_max_ms', (string) $delayMaxMs, $updatedBy);
        saveAppSetting($db, 'wa_runtime.burst_limit', (string) $burstLimit, $updatedBy);
        saveAppSetting($db, 'wa_runtime.burst_window_seconds', (string) $burstWindowSeconds, $updatedBy);
        saveAppSetting($db, 'wa_runtime.burst_pause_min_ms', (string) $burstPauseMinMs, $updatedBy);
        saveAppSetting($db, 'wa_runtime.burst_pause_max_ms', (string) $burstPauseMaxMs, $updatedBy);
        saveAppSetting($db, 'wa_runtime.admin_queue_enabled', $adminQueueEnabled, $updatedBy);
        saveAppSetting($db, 'wa_runtime.user_queue_enabled', $userQueueEnabled, $updatedBy);
        saveAppSetting($db, 'wa_runtime.queue_batch_limit', (string) $queueBatchLimit, $updatedBy);
        saveAppSetting($db, 'wa_runtime.queue_max_attempts', (string) $queueMaxAttempts, $updatedBy);

        $waConfigSuccess = 'Pengaturan runtime WA (delay/queue) berhasil diperbarui.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postAction === 'save_vpn_config') {
        $vpnProviderName = trim((string) ($_POST['vpn_provider_name'] ?? ''));
        $vpnBaseUrl = trim((string) ($_POST['vpn_base_url'] ?? ''));
        $vpnApiKey = trim((string) ($_POST['vpn_api_key'] ?? ''));
        $vpnApiSecret = (string) ($_POST['vpn_api_secret'] ?? '');
        $vpnAccessToken = trim((string) ($_POST['vpn_access_token'] ?? ''));
        $vpnWebhookUrl = trim((string) ($_POST['vpn_webhook_url'] ?? ''));
        $vpnVerifyToken = trim((string) ($_POST['vpn_verify_token'] ?? ''));
        $vpnAuthScheme = strtolower(trim((string) ($_POST['vpn_auth_scheme'] ?? 'basic')));
        $vpnUsersEndpoint = trim((string) ($_POST['vpn_endpoint_users'] ?? '/users'));
        $vpnPortForwardingsEndpoint = trim((string) ($_POST['vpn_endpoint_port_forwardings'] ?? '/port-forwardings'));
        $vpnTimeoutSeconds = max(1, (int) ($_POST['vpn_request_timeout_seconds'] ?? 30));
        $vpnSubnetPrefix = trim((string) ($_POST['vpn_subnet_prefix'] ?? '192.168.12'));
        $vpnIpRangeStart = max(1, min(253, (int) ($_POST['vpn_ip_range_start'] ?? 2)));
        $vpnIpRangeEnd = max(2, min(254, (int) ($_POST['vpn_ip_range_end'] ?? 254)));
        $vpnIsEnabled = !empty($_POST['vpn_is_enabled']) ? 1 : 0;

        if ($vpnProviderName === '') {
            $vpnProviderName = 'FastAPI Libreswan L2TP';
        }
        if ($vpnBaseUrl === '') {
            throw new RuntimeException('Server VPN API wajib diisi.');
        }
        if (!preg_match('/^https?:\/\//i', $vpnBaseUrl)) {
            throw new RuntimeException('Server VPN API harus diawali http:// atau https://');
        }
        if ($vpnWebhookUrl !== '' && !preg_match('/^https?:\/\//i', $vpnWebhookUrl)) {
            throw new RuntimeException('Webhook URL harus diawali http:// atau https://');
        }
        if (!in_array($vpnAuthScheme, ['basic', 'bearer'], true)) {
            throw new RuntimeException('Skema autentikasi harus basic atau bearer.');
        }
        if ($vpnSubnetPrefix !== '' && !preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}$/', $vpnSubnetPrefix)) {
            throw new RuntimeException('Subnet Prefix harus dalam format X.X.X (misal: 192.168.12).');
        }
        if ($vpnIpRangeStart >= $vpnIpRangeEnd) {
            throw new RuntimeException('IP Range Start harus lebih kecil dari IP Range End.');
        }

        $vpnUsersEndpoint = '/' . ltrim($vpnUsersEndpoint !== '' ? $vpnUsersEndpoint : '/users', '/');
        $vpnPortForwardingsEndpoint = '/' . ltrim($vpnPortForwardingsEndpoint !== '' ? $vpnPortForwardingsEndpoint : '/port-forwardings', '/');

        $vpnCfgStmt = $db->query("SELECT id FROM api_configurations WHERE service_type = 'vpn' ORDER BY updated_at DESC, id DESC LIMIT 1");
        $vpnCfgId = (int) ($vpnCfgStmt->fetchColumn() ?: 0);

        if ($vpnCfgId > 0) {
            $vpnUpdate = $db->prepare(
                'UPDATE api_configurations
                 SET provider_name = :provider_name,
                     base_url = :base_url,
                     api_key = :api_key,
                     api_secret = :api_secret,
                     access_token = :access_token,
                     webhook_url = :webhook_url,
                     verify_token = :verify_token,
                     request_timeout_seconds = :request_timeout_seconds,
                     is_enabled = :is_enabled,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $vpnUpdate->execute([
                'provider_name' => $vpnProviderName,
                'base_url' => $vpnBaseUrl,
                'api_key' => $vpnApiKey !== '' ? $vpnApiKey : null,
                'api_secret' => $vpnApiSecret !== '' ? $vpnApiSecret : null,
                'access_token' => $vpnAccessToken !== '' ? $vpnAccessToken : null,
                'webhook_url' => $vpnWebhookUrl !== '' ? $vpnWebhookUrl : null,
                'verify_token' => $vpnVerifyToken !== '' ? $vpnVerifyToken : null,
                'request_timeout_seconds' => $vpnTimeoutSeconds,
                'is_enabled' => $vpnIsEnabled,
                'id' => $vpnCfgId,
            ]);
        } else {
            $vpnInsert = $db->prepare(
                'INSERT INTO api_configurations
                    (service_type, provider_name, base_url, api_key, api_secret, access_token, webhook_url, verify_token, request_timeout_seconds, is_enabled, created_by, created_at, updated_at)
                 VALUES
                    (:service_type, :provider_name, :base_url, :api_key, :api_secret, :access_token, :webhook_url, :verify_token, :request_timeout_seconds, :is_enabled, :created_by, NOW(), NOW())'
            );
            $vpnInsert->execute([
                'service_type' => 'vpn',
                'provider_name' => $vpnProviderName,
                'base_url' => $vpnBaseUrl,
                'api_key' => $vpnApiKey !== '' ? $vpnApiKey : null,
                'api_secret' => $vpnApiSecret !== '' ? $vpnApiSecret : null,
                'access_token' => $vpnAccessToken !== '' ? $vpnAccessToken : null,
                'webhook_url' => $vpnWebhookUrl !== '' ? $vpnWebhookUrl : null,
                'verify_token' => $vpnVerifyToken !== '' ? $vpnVerifyToken : null,
                'request_timeout_seconds' => $vpnTimeoutSeconds,
                'is_enabled' => $vpnIsEnabled,
                'created_by' => (int) ($_SESSION['user_id'] ?? 0) ?: null,
            ]);
        }

        $updatedBy = (int) ($_SESSION['user_id'] ?? 0) ?: null;
        saveAppSetting($db, 'vpn.auth_scheme', $vpnAuthScheme, $updatedBy);
        saveAppSetting($db, 'vpn.endpoint_users', $vpnUsersEndpoint, $updatedBy);
        saveAppSetting($db, 'vpn.endpoint_port_forwardings', $vpnPortForwardingsEndpoint, $updatedBy);
        saveAppSetting($db, 'vpn.subnet_prefix', $vpnSubnetPrefix !== '' ? $vpnSubnetPrefix : '192.168.12', $updatedBy);
        saveAppSetting($db, 'vpn.ip_range_start', (string) $vpnIpRangeStart, $updatedBy);
        saveAppSetting($db, 'vpn.ip_range_end', (string) $vpnIpRangeEnd, $updatedBy);

        $vpnConfigSuccess = 'Konfigurasi VPN API berhasil diperbarui.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postAction === 'save_payment_gateway_config') {
        $pgMerchantCode = trim((string) ($_POST['duitku_merchant_code'] ?? ''));
        $pgApiKey = trim((string) ($_POST['duitku_api_key'] ?? ''));
        $pgBaseUrl = trim((string) ($_POST['duitku_base_url'] ?? 'https://sandbox.duitku.com/webapi/api/merchant'));
        $pgSandbox = !empty($_POST['duitku_sandbox']) ? '1' : '0';

        if ($pgMerchantCode === '') throw new RuntimeException('Merchant Code wajib diisi.');
        if ($pgApiKey === '') throw new RuntimeException('API Key wajib diisi.');
        if ($pgBaseUrl === '') throw new RuntimeException('Base URL wajib diisi.');

        $updatedBy = (int) ($_SESSION['user_id'] ?? 0) ?: null;
        saveAppSetting($db, 'duitku.merchant_code', $pgMerchantCode, $updatedBy);
        saveAppSetting($db, 'duitku.api_key', $pgApiKey, $updatedBy);
        saveAppSetting($db, 'duitku.base_url', $pgBaseUrl, $updatedBy);
        saveAppSetting($db, 'duitku.sandbox', $pgSandbox, $updatedBy);

        $pgConfig['merchant_code'] = $pgMerchantCode;
        $pgConfig['api_key'] = $pgApiKey;
        $pgConfig['base_url'] = $pgBaseUrl;
        $pgConfig['sandbox'] = $pgSandbox;

        $pgConfigSuccess = 'Konfigurasi Payment Gateway berhasil diperbarui.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postAction === 'save_plan_config') {
        $planId       = (int) ($_POST['plan_id'] ?? 0);
        $label        = trim((string) ($_POST['plan_label'] ?? ''));
        $price        = max(0, (float) str_replace(',', '.', (string) ($_POST['plan_price'] ?? '0')));
        $durationDays = max(1, (int) ($_POST['plan_duration_days'] ?? 30));
        $vpnLimit     = max(0, (int) ($_POST['plan_vpn_limit'] ?? 0));
        $waLimit      = max(0, (int) ($_POST['plan_wa_device_limit'] ?? 0));
        $proxyLimit   = max(0, (int) ($_POST['plan_proxy_route_limit'] ?? 0));
        $isActive     = !empty($_POST['plan_is_active']) ? 1 : 0;

        if ($planId <= 0) {
            throw new RuntimeException('Plan ID tidak valid.');
        }
        if ($label === '') {
            throw new RuntimeException('Label paket wajib diisi.');
        }

        $db->prepare(
            'UPDATE plans
             SET label             = :label,
                 price             = :price,
                 duration_days     = :duration_days,
                 vpn_limit         = :vpn_limit,
                 wa_device_limit   = :wa_device_limit,
                 proxy_route_limit = :proxy_route_limit,
                 is_active         = :is_active,
                 updated_at        = NOW()
             WHERE id = :id'
        )->execute([
            'label'             => $label,
            'price'             => $price,
            'duration_days'     => $durationDays,
            'vpn_limit'         => $vpnLimit,
            'wa_device_limit'   => $waLimit,
            'proxy_route_limit' => $proxyLimit,
            'is_active'         => $isActive,
            'id'                => $planId,
        ]);

        try {
            $db->prepare(
                "INSERT INTO audit_logs (actor_user_id, action, target_type, target_id, detail, created_at)
                 VALUES (:actor, 'plan_update', 'plan', :target_id, :detail, NOW())"
            )->execute([
                'actor'     => (int) ($_SESSION['user_id'] ?? 0) ?: null,
                'target_id' => $planId,
                'detail'    => json_encode(['label' => $label, 'price' => $price, 'duration_days' => $durationDays], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable) { /* ignore */ }

        $planConfigSuccess = 'Paket \'' . $label . '\' berhasil diperbarui.';
    }

    $settings = loadAppSettings($db, [
        'seo.site_title',
        'seo.meta_description',
        'seo.meta_keywords',
        'seo.og_image_url',
        'seo.robots_policy',
        'seo.google_analytics_id',
        'seo.google_tag_manager_id',
        'web.company_name',
        'web.template_name',
        'web.font_family',
        'web.logo_url',
        'web.favicon_url',
        'vpn.auth_scheme',
        'vpn.endpoint_users',
        'vpn.endpoint_port_forwardings',
        'vpn.subnet_prefix',
        'vpn.ip_range_start',
        'vpn.ip_range_end',
        'duitku.merchant_code',
        'duitku.api_key',
        'duitku.base_url',
        'duitku.sandbox',
    ]);

    $seoConfig['site_title'] = (string) ($settings['seo.site_title'] ?? $seoConfig['site_title']);
    $seoConfig['meta_description'] = (string) ($settings['seo.meta_description'] ?? $seoConfig['meta_description']);
    $seoConfig['meta_keywords'] = (string) ($settings['seo.meta_keywords'] ?? $seoConfig['meta_keywords']);
    $seoConfig['og_image_url'] = (string) ($settings['seo.og_image_url'] ?? $seoConfig['og_image_url']);
    $seoConfig['robots_policy'] = (string) ($settings['seo.robots_policy'] ?? $seoConfig['robots_policy']);
    $seoConfig['google_analytics_id'] = (string) ($settings['seo.google_analytics_id'] ?? $seoConfig['google_analytics_id']);
    $seoConfig['google_tag_manager_id'] = (string) ($settings['seo.google_tag_manager_id'] ?? $seoConfig['google_tag_manager_id']);

    $webpageConfig['company_name'] = (string) ($settings['web.company_name'] ?? $webpageConfig['company_name']);
    $webpageConfig['template_name'] = (string) ($settings['web.template_name'] ?? $webpageConfig['template_name']);
    $webpageConfig['font_family'] = (string) ($settings['web.font_family'] ?? $webpageConfig['font_family']);
    $webpageConfig['logo_url'] = (string) ($settings['web.logo_url'] ?? $webpageConfig['logo_url']);
    $webpageConfig['favicon_url'] = (string) ($settings['web.favicon_url'] ?? $webpageConfig['favicon_url']);

    $vpnAuthSchemeSetting = (string) ($settings['vpn.auth_scheme'] ?? $vpnConfig['auth_scheme']);
    $vpnConfig['auth_scheme'] = in_array($vpnAuthSchemeSetting, ['basic', 'bearer'], true)
        ? $vpnAuthSchemeSetting
        : $vpnConfig['auth_scheme'];
    $vpnConfig['endpoint_users'] = (string) ($settings['vpn.endpoint_users'] ?? $vpnConfig['endpoint_users']);
    $vpnConfig['endpoint_port_forwardings'] = (string) ($settings['vpn.endpoint_port_forwardings'] ?? $vpnConfig['endpoint_port_forwardings']);
    $vpnConfig['subnet_prefix'] = (string) ($settings['vpn.subnet_prefix'] ?? '192.168.12');
    $vpnConfig['ip_range_start'] = max(1, (int) ($settings['vpn.ip_range_start'] ?? 2));
    $vpnConfig['ip_range_end'] = min(254, (int) ($settings['vpn.ip_range_end'] ?? 254));

    $waCfgStmt = $db->query(
        "SELECT base_url, api_key, api_secret, admin_send_device_id
         FROM api_configurations
         WHERE service_type = 'whatsapp'
         ORDER BY updated_at DESC, id DESC
         LIMIT 1"
    );
    $waCfgRow = $waCfgStmt->fetch();
    if (is_array($waCfgRow)) {
        $waConfig['base_url'] = (string) ($waCfgRow['base_url'] ?? $waConfig['base_url']);
        $waConfig['api_key'] = (string) ($waCfgRow['api_key'] ?? $waConfig['api_key']);
        $waConfig['api_secret'] = (string) ($waCfgRow['api_secret'] ?? $waConfig['api_secret']);
        $waConfig['admin_send_device_id'] = (string) ($waCfgRow['admin_send_device_id'] ?? $waConfig['admin_send_device_id']);
    }

    $vpnCfgStmt = $db->query(
        "SELECT provider_name, base_url, api_key, api_secret, access_token, webhook_url, verify_token, request_timeout_seconds, is_enabled
         FROM api_configurations
         WHERE service_type = 'vpn'
         ORDER BY updated_at DESC, id DESC
         LIMIT 1"
    );
    $vpnCfgRow = $vpnCfgStmt->fetch();
    if (is_array($vpnCfgRow)) {
        $vpnConfig['provider_name'] = (string) ($vpnCfgRow['provider_name'] ?? $vpnConfig['provider_name']);
        $vpnConfig['base_url'] = (string) ($vpnCfgRow['base_url'] ?? $vpnConfig['base_url']);
        $vpnConfig['api_key'] = (string) ($vpnCfgRow['api_key'] ?? $vpnConfig['api_key']);
        $vpnConfig['api_secret'] = (string) ($vpnCfgRow['api_secret'] ?? $vpnConfig['api_secret']);
        $vpnConfig['access_token'] = (string) ($vpnCfgRow['access_token'] ?? $vpnConfig['access_token']);
        $vpnConfig['webhook_url'] = (string) ($vpnCfgRow['webhook_url'] ?? $vpnConfig['webhook_url']);
        $vpnConfig['verify_token'] = (string) ($vpnCfgRow['verify_token'] ?? $vpnConfig['verify_token']);
        $vpnConfig['request_timeout_seconds'] = max(1, (int) ($vpnCfgRow['request_timeout_seconds'] ?? $vpnConfig['request_timeout_seconds']));
        $vpnConfig['is_enabled'] = !empty($vpnCfgRow['is_enabled']) ? 1 : 0;
    }

    $waDeviceStmt = $db->prepare(
        'SELECT wa.device_id, wa.label, wa.status
         FROM wa_accounts wa
         WHERE wa.owner_user_id = :owner_user_id
         ORDER BY wa.created_at DESC'
    );
    $waDeviceStmt->execute([
        'owner_user_id' => (int) ($_SESSION['user_id'] ?? 0),
    ]);
    $waDevices = $waDeviceStmt->fetchAll();

    $waRuntimeConfig = waSendRuntimeConfigFromDb($db);

    ensureWaMessageQueueTable($db);
    $queueSummaryStmt = $db->query('SELECT status, COUNT(*) AS cnt FROM wa_message_queue GROUP BY status');
    foreach ($queueSummaryStmt->fetchAll() as $row) {
        $status = strtolower(trim((string) ($row['status'] ?? '')));
        $count = (int) ($row['cnt'] ?? 0);
        if (isset($waQueueSummary[$status])) {
            $waQueueSummary[$status] = $count;
        }
        $waQueueSummary['total'] += $count;
    }

    $queueRecentStmt = $db->query(
        'SELECT id, queue_scope, status, device_id, phone_number, attempts, max_attempts, created_at, updated_at, last_error
         FROM wa_message_queue
         ORDER BY id DESC
         LIMIT 12'
    );
    $waQueueRecent = $queueRecentStmt->fetchAll();

    $plans = $db->query('SELECT * FROM plans ORDER BY price ASC')->fetchAll();

    if (isset($settings['duitku.merchant_code'])) $pgConfig['merchant_code'] = $settings['duitku.merchant_code'];
    if (isset($settings['duitku.api_key'])) $pgConfig['api_key'] = $settings['duitku.api_key'];
    if (isset($settings['duitku.base_url'])) $pgConfig['base_url'] = $settings['duitku.base_url'];
    if (isset($settings['duitku.sandbox'])) $pgConfig['sandbox'] = $settings['duitku.sandbox'];
} catch (RuntimeException $e) {
    if ($postAction === 'save_seo_config') {
        $seoConfigError = $e->getMessage();
    } elseif ($postAction === 'save_webpage_config') {
        $webConfigError = $e->getMessage();
    } elseif ($postAction === 'save_vpn_config') {
        $vpnConfigError = $e->getMessage();
    } elseif ($postAction === 'save_plan_config') {
        $planConfigError = $e->getMessage();
    } elseif ($postAction === 'save_payment_gateway_config') {
        $pgConfigError = $e->getMessage();
    } else {
        $waConfigError = $e->getMessage();
    }
} catch (Throwable $e) {
    if ($postAction === 'save_seo_config') {
        $seoConfigError = 'Gagal menyimpan atau memuat pengaturan SEO.';
    } elseif ($postAction === 'save_webpage_config') {
        $webConfigError = 'Gagal menyimpan atau memuat pengaturan Web Page.';
    } elseif ($postAction === 'save_vpn_config') {
        $vpnConfigError = 'Gagal menyimpan atau memuat konfigurasi VPN API.';
    } elseif ($postAction === 'save_plan_config') {
        $planConfigError = 'Gagal menyimpan atau memuat paket subscription.';
    } elseif ($postAction === 'save_payment_gateway_config') {
        $pgConfigError = 'Gagal menyimpan atau memuat konfigurasi Payment Gateway.';
    } else {
        $waConfigError = 'Gagal menyimpan atau memuat konfigurasi WhatsApp API.';
    }
}

$sessionUserName = $_SESSION['full_name'] ?? 'Admin';
$sessionUserEmail = $_SESSION['email'] ?? '';
$sessionPhoneNumber = $_SESSION['phone_number'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php seoRenderHeadTags('Admin Config - ' . webCompanyName()); ?>
    <script>
        window.DAYNIGHT_SESSION = <?php echo json_encode([
            'fullName'    => $sessionUserName,
            'email'       => $sessionUserEmail,
            'phoneNumber' => $sessionPhoneNumber,
            'role'        => 'admin',
            'roles'       => $sessionRoles,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

        localStorage.setItem('daynight-user-name', window.DAYNIGHT_SESSION.fullName || 'Admin');
        localStorage.setItem('daynight-user-email', window.DAYNIGHT_SESSION.email || '');
        localStorage.setItem('daynight-user-role', 'admin');
        localStorage.setItem('daynight-user-roles', JSON.stringify(window.DAYNIGHT_SESSION.roles || []));

        if (localStorage.getItem('daynight-theme') === 'carbon') {
            document.documentElement.classList.add('carbon');
        }
    </script>
    <link rel="stylesheet" href="../templatemo-daynight-style.css?v=<?php echo (int) (file_exists(__DIR__ . '/../templatemo-daynight-style.css') ? filemtime(__DIR__ . '/../templatemo-daynight-style.css') : time()); ?>">
    <style>
        .config-switch {
            display: flex;
            gap: 0.625rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }

        .config-switch-btn {
            border: 1px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-secondary);
            border-radius: 10px;
            padding: 0.6rem 0.9rem;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
        }

        .config-switch-btn.active {
            color: var(--accent);
            border-color: rgba(56, 189, 248, 0.35);
            background: var(--accent-light);
        }

        .config-panel {
            display: none;
        }

        .config-panel.active {
            display: block;
        }

        .placeholder-list {
            margin: 0;
            padding-left: 1.1rem;
            color: var(--text-secondary);
            font-size: 0.875rem;
            line-height: 1.7;
        }

        .placeholder-note {
            font-size: 0.8125rem;
            color: var(--text-secondary);
            background: var(--bg-surface);
            border: 1px dashed var(--border-color);
            border-radius: 10px;
            padding: 0.75rem 0.85rem;
            margin-top: 0.9rem;
        }

        .admin-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }

        .admin-form-grid .full {
            grid-column: 1 / -1;
        }

        .admin-alert {
            border-radius: var(--radius-md);
            padding: 0.75rem 0.9rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            border: 1px solid transparent;
        }

        .admin-alert.error {
            color: #fecaca;
            background: rgba(153, 27, 27, 0.2);
            border-color: rgba(239, 68, 68, 0.35);
        }

        .admin-alert.success {
            color: #bbf7d0;
            background: rgba(22, 101, 52, 0.2);
            border-color: rgba(34, 197, 94, 0.35);
        }

        @media (max-width: 980px) {
            .admin-form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php seoRenderBodyOpenTags(); ?>
    <div class="mobile-menu-overlay"></div>

    <div class="mobile-menu">
        <div class="mobile-menu-header">
            <a href="../index.php" class="logo">
                <div class="logo-icon">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                    </svg>
                </div>
                <?php echo htmlspecialchars(webCompanyName(), ENT_QUOTES, 'UTF-8'); ?>
            </a>
            <button class="mobile-menu-close" onclick="closeMobileMenu()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <nav class="mobile-menu-nav">
            <a href="../index.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>Dashboard</a>
            <a href="wa-devices.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12" y2="18"/></svg>WA Devices</a>
            <a href="vpn-users.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>VPN Management</a>
        <a href="proxy-routes.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M2 12h3M19 12h3M12 2v3M12 19v3"/><path d="M4.93 4.93l2.12 2.12M16.95 16.95l2.12 2.12M19.07 4.93l-2.12 2.12M7.05 16.95l-2.12 2.12"/></svg>Proxy Routes</a>
            <a href="config.php" class="active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>Config</a>
            <a href="audit-log.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3h18v18H3z"/><path d="M7 7h10"/><path d="M7 12h10"/><path d="M7 17h6"/></svg>Audit Log</a>
        </nav>
        <div class="mobile-menu-footer">
            <a href="../api/logout.php" class="mobile-logout-btn">Logout</a>
            <div class="theme-toggle">
                <button class="theme-btn theme-btn-snow active" onclick="setTheme('snow')" title="Snow Edition">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="5"/>
                        <line x1="12" y1="1" x2="12" y2="3"/>
                        <line x1="12" y1="21" x2="12" y2="23"/>
                        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
                        <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                        <line x1="1" y1="12" x2="3" y2="12"/>
                        <line x1="21" y1="12" x2="23" y2="12"/>
                        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
                        <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                    </svg>
                </button>
                <button class="theme-btn theme-btn-carbon" onclick="setTheme('carbon')" title="Carbon Edition">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <div class="app-container">
        <nav class="top-nav">
            <div class="nav-container">
                <div class="nav-left">
                    <a href="../index.php" class="logo">
                        <div class="logo-icon">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                            </svg>
                        </div>
                        <?php echo htmlspecialchars(webCompanyName(), ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                    <div class="nav-menu">
                        <div class="nav-item"><a href="../index.php" class="nav-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>Dashboard</a></div>
                        <div class="nav-item"><a href="wa-devices.php" class="nav-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>WA Devices</a></div>
                        <div class="nav-item"><a href="vpn-users.php" class="nav-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>VPN Management</a></div>
                        <div class="nav-item"><a href="proxy-routes.php" class="nav-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M2 12h3M19 12h3M12 2v3M12 19v3"/><path d="M4.93 4.93l2.12 2.12M16.95 16.95l2.12 2.12M19.07 4.93l-2.12 2.12M7.05 16.95l-2.12 2.12"/></svg>Proxy Routes</a></div>
                    <div class="nav-item"><a href="config.php" class="nav-link active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>Config</a></div>
                        <div class="nav-item"><a href="audit-log.php" class="nav-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3h18v18H3z"/><path d="M7 7h10"/><path d="M7 12h10"/><path d="M7 17h6"/></svg>Audit Log</a></div>
                    </div>
                </div>
                <div class="nav-right">
                    <div class="theme-toggle">
                        <button class="theme-btn theme-btn-snow active" onclick="setTheme('snow')" title="Snow Edition">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="5"/>
                                <line x1="12" y1="1" x2="12" y2="3"/>
                                <line x1="12" y1="21" x2="12" y2="23"/>
                                <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
                                <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                                <line x1="1" y1="12" x2="3" y2="12"/>
                                <line x1="21" y1="12" x2="23" y2="12"/>
                                <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
                                <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                            </svg>
                        </button>
                        <button class="theme-btn theme-btn-carbon" onclick="setTheme('carbon')" title="Carbon Edition">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                            </svg>
                        </button>
                    </div>
                    <div class="account-menu-wrap" id="account-menu-wrap">
                        <button class="user-menu" onclick="toggleAccountDropdown()">
                            <div class="user-avatar">A</div>
                            <span class="user-name" data-current-user>Admin</span>
                        </button>
                        <div class="account-dropdown">
                            <button onclick="openAccountModal('edit-profile')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Edit Profil</button>
                            <button onclick="openAccountModal('change-password')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Ganti Password</button>
                        </div>
                    </div>
                    <a href="../api/logout.php" class="btn-logout" title="Logout">Logout</a>
                    <button class="mobile-menu-btn" onclick="toggleMobileMenu()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="3" y1="12" x2="21" y2="12"/>
                            <line x1="3" y1="6" x2="21" y2="6"/>
                            <line x1="3" y1="18" x2="21" y2="18"/>
                        </svg>
                    </button>
                </div>
            </div>
        </nav>

        <main class="main-content">
            <div class="page-header" style="display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; flex-wrap:wrap;">
                <div>
                    <h1 id="greeting" class="greeting">Admin Config</h1>
                    <p class="greeting-sub">Kelola konfigurasi berdasarkan kategori menggunakan mode switch.</p>
                </div>
                <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
                    <a href="index.php" class="btn btn-secondary">Open Admin Dashboard</a>
                    <a href="wa-devices.php" class="btn btn-primary">Manage Devices</a>
                </div>
            </div>

            <div class="config-switch" role="tablist" aria-label="Config mode switch">
                <button type="button" class="config-switch-btn" data-config-mode="seo" role="tab" aria-selected="false">
                    1. Pengaturan SEO
                </button>
                <button type="button" class="config-switch-btn" data-config-mode="webpage" role="tab" aria-selected="false">
                    2. Pengaturan Web Page
                </button>
                <button type="button" class="config-switch-btn" data-config-mode="api" role="tab" aria-selected="false">
                    3. Pengaturan WA API
                </button>
                <button type="button" class="config-switch-btn" data-config-mode="vpn" role="tab" aria-selected="false">
                    4. Pengaturan VPN API
                </button>
                <button type="button" class="config-switch-btn" data-config-mode="plans" role="tab" aria-selected="false">
                    5. Paket Subscription
                </button>
                <button type="button" class="config-switch-btn" data-config-mode="payment" role="tab" aria-selected="false">
                    6. Payment Gateway
                </button>
            </div>

            <section id="config-panel-seo" class="config-panel" data-config-panel="seo" role="tabpanel" aria-label="Pengaturan SEO">
                <div class="card" style="margin-bottom:1.5rem;">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">Pengaturan SEO</h3>
                            <p class="card-subtitle">Atur metadata SEO global website.</p>
                        </div>
                    </div>

                    <?php if ($seoConfigError !== ''): ?>
                        <div class="admin-alert error"><?php echo htmlspecialchars($seoConfigError, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    <?php if ($seoConfigSuccess !== ''): ?>
                        <div class="admin-alert success"><?php echo htmlspecialchars($seoConfigSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>

                    <form method="post" class="admin-form-grid">
                        <input type="hidden" name="action" value="save_seo_config">
                        <div class="full">
                            <label class="form-label" for="seo_site_title">Site Title</label>
                            <input class="form-input" type="text" id="seo_site_title" name="seo_site_title"
                                value="<?php echo htmlspecialchars((string) ($seoConfig['site_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                placeholder="Contoh: VPN & WhatsApp API Manager">
                        </div>
                        <div class="full">
                            <label class="form-label" for="seo_meta_description">Meta Description</label>
                            <textarea class="form-input" id="seo_meta_description" name="seo_meta_description" rows="3" placeholder="Deskripsi singkat website untuk mesin pencari"><?php echo htmlspecialchars((string) ($seoConfig['meta_description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        <div class="full">
                            <label class="form-label" for="seo_meta_keywords">Meta Keywords</label>
                            <input class="form-input" type="text" id="seo_meta_keywords" name="seo_meta_keywords"
                                value="<?php echo htmlspecialchars((string) ($seoConfig['meta_keywords'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                placeholder="contoh: vpn, whatsapp api, manager">
                        </div>
                        <div>
                            <label class="form-label" for="seo_og_image_url">Open Graph Image URL</label>
                            <input class="form-input" type="url" id="seo_og_image_url" name="seo_og_image_url"
                                value="<?php echo htmlspecialchars((string) ($seoConfig['og_image_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                placeholder="https://example.com/og-cover.jpg">
                        </div>
                        <div>
                            <label class="form-label" for="seo_robots_policy">Robots Policy</label>
                            <select class="form-select" id="seo_robots_policy" name="seo_robots_policy">
                                <option value="index,follow" <?php echo (($seoConfig['robots_policy'] ?? '') === 'index,follow') ? 'selected' : ''; ?>>index,follow</option>
                                <option value="noindex,follow" <?php echo (($seoConfig['robots_policy'] ?? '') === 'noindex,follow') ? 'selected' : ''; ?>>noindex,follow</option>
                                <option value="noindex,nofollow" <?php echo (($seoConfig['robots_policy'] ?? '') === 'noindex,nofollow') ? 'selected' : ''; ?>>noindex,nofollow</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label" for="seo_google_analytics_id">Google Analytics ID</label>
                            <input class="form-input" type="text" id="seo_google_analytics_id" name="seo_google_analytics_id"
                                value="<?php echo htmlspecialchars((string) ($seoConfig['google_analytics_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                placeholder="Contoh: G-ABC123DEF4">
                        </div>
                        <div>
                            <label class="form-label" for="seo_google_tag_manager_id">Google Tag Manager ID</label>
                            <input class="form-input" type="text" id="seo_google_tag_manager_id" name="seo_google_tag_manager_id"
                                value="<?php echo htmlspecialchars((string) ($seoConfig['google_tag_manager_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                placeholder="Contoh: GTM-ABC1234">
                        </div>
                        <div class="full" style="display:flex;justify-content:flex-end;">
                            <button type="submit" class="btn btn-primary">Save SEO Config</button>
                        </div>
                    </form>
                </div>
            </section>

            <section id="config-panel-webpage" class="config-panel" data-config-panel="webpage" role="tabpanel" aria-label="Pengaturan Web Page">
                <div class="two-col" style="margin-bottom:1.5rem;">
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h3 class="card-title">Pengaturan Web Page</h3>
                                <p class="card-subtitle">Atur parameter template, font, logo, dan favicon.</p>
                            </div>
                        </div>

                        <?php if ($webConfigError !== ''): ?>
                            <div class="admin-alert error"><?php echo htmlspecialchars($webConfigError, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                        <?php if ($webConfigSuccess !== ''): ?>
                            <div class="admin-alert success"><?php echo htmlspecialchars($webConfigSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>

                        <form method="post" class="admin-form-grid" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="save_webpage_config">
                            <div class="full">
                                <label class="form-label" for="web_company_name">Nama Perusahaan</label>
                                <input class="form-input" type="text" id="web_company_name" name="web_company_name"
                                    value="<?php echo htmlspecialchars((string) ($webpageConfig['company_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="Contoh: PT Maju Bersama Digital" maxlength="100" required>
                            </div>
                            <div>
                                <label class="form-label" for="web_template_name">Template</label>
                                <input class="form-input" type="text" id="web_template_name" name="web_template_name"
                                    value="<?php echo htmlspecialchars((string) ($webpageConfig['template_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="daynight-default">
                            </div>
                            <div>
                                <label class="form-label" for="web_font_family">Font Family</label>
                                <input class="form-input" type="text" id="web_font_family" name="web_font_family"
                                    value="<?php echo htmlspecialchars((string) ($webpageConfig['font_family'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="Poppins">
                            </div>
                            <div class="full">
                                <label class="form-label" for="web_logo_file">Upload Logo (PNG/JPG/WEBP)</label>
                                <input class="form-input" type="file" id="web_logo_file" name="web_logo_file" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp">
                                <label style="display:flex;align-items:center;gap:0.5rem;margin-top:0.55rem;font-size:0.8125rem;color:var(--text-secondary);">
                                    <input type="checkbox" name="web_remove_logo" value="1">
                                    Hapus logo saat ini
                                </label>
                            </div>
                            <div class="full">
                                <label class="form-label" for="web_favicon_file">Upload Favicon (PNG/ICO)</label>
                                <input class="form-input" type="file" id="web_favicon_file" name="web_favicon_file" accept=".png,.ico,image/png,image/x-icon,image/vnd.microsoft.icon">
                                <label style="display:flex;align-items:center;gap:0.5rem;margin-top:0.55rem;font-size:0.8125rem;color:var(--text-secondary);">
                                    <input type="checkbox" name="web_remove_favicon" value="1">
                                    Hapus favicon saat ini
                                </label>
                            </div>
                            <div class="full" style="display:flex;justify-content:flex-end;">
                                <button type="submit" class="btn btn-primary">Save Web Page Config</button>
                            </div>
                        </form>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h3 class="card-title">Preview Status</h3>
                                <p class="card-subtitle">Ringkasan konfigurasi Web Page yang sedang aktif.</p>
                            </div>
                        </div>
                        <div class="health-item">
                            <div class="health-meta">
                                <span>Nama Perusahaan</span>
                                <strong><?php echo htmlspecialchars((string) ($webpageConfig['company_name'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        </div>
                        <div class="health-item">
                            <div class="health-meta">
                                <span>Template</span>
                                <strong><?php echo htmlspecialchars((string) ($webpageConfig['template_name'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        </div>
                        <div class="health-item">
                            <div class="health-meta">
                                <span>Font Family</span>
                                <strong><?php echo htmlspecialchars((string) ($webpageConfig['font_family'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        </div>
                        <div class="health-item">
                            <div class="health-meta">
                                <span>Logo</span>
                                <strong style="font-size:0.75rem;"><?php echo htmlspecialchars((string) (($webpageConfig['logo_url'] ?? '') !== '' ? $webpageConfig['logo_url'] : '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                            <?php if (($webpageConfig['logo_url'] ?? '') !== ''): ?>
                                <img src="<?php echo htmlspecialchars((string) $webpageConfig['logo_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="Logo" style="max-height:42px;max-width:180px;object-fit:contain;border:1px solid var(--border-color);border-radius:8px;padding:0.35rem;background:var(--bg-surface);">
                            <?php endif; ?>
                        </div>
                        <div class="health-item" style="margin-bottom:0;">
                            <div class="health-meta">
                                <span>Favicon</span>
                                <strong style="font-size:0.75rem;"><?php echo htmlspecialchars((string) (($webpageConfig['favicon_url'] ?? '') !== '' ? $webpageConfig['favicon_url'] : '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                            <?php if (($webpageConfig['favicon_url'] ?? '') !== ''): ?>
                                <img src="<?php echo htmlspecialchars((string) $webpageConfig['favicon_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="Favicon" style="width:28px;height:28px;object-fit:contain;border:1px solid var(--border-color);border-radius:6px;padding:0.2rem;background:var(--bg-surface);">
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>

            <section id="config-panel-api" class="config-panel" data-config-panel="api" role="tabpanel" aria-label="Pengaturan WA API">
                <div class="two-col" style="margin-bottom:1.5rem;">
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h3 class="card-title">WhatsApp API Configuration</h3>
                                <p class="card-subtitle">Atur server GoWA, auth basic, dan device default admin</p>
                            </div>
                        </div>

                        <?php if ($waConfigError !== ''): ?>
                            <div class="admin-alert error"><?php echo htmlspecialchars($waConfigError, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                        <?php if ($waConfigSuccess !== ''): ?>
                            <div class="admin-alert success"><?php echo htmlspecialchars($waConfigSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>

                        <form method="post" class="admin-form-grid">
                            <input type="hidden" name="action" value="save_wa_config">
                            <div class="full">
                                <label class="form-label" for="wa_base_url">Server WA API</label>
                                <input class="form-input" type="url" id="wa_base_url" name="wa_base_url" required
                                    value="<?php echo htmlspecialchars((string) ($waConfig['base_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="https://gowa.example.com">
                            </div>
                            <div>
                                <label class="form-label" for="wa_username">Auth Username</label>
                                <input class="form-input" type="text" id="wa_username" name="wa_username" required
                                    value="<?php echo htmlspecialchars((string) ($waConfig['api_key'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div>
                                <label class="form-label" for="wa_password">Auth Password</label>
                                <input class="form-input" type="password" id="wa_password" name="wa_password" required
                                    value="<?php echo htmlspecialchars((string) ($waConfig['api_secret'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="full">
                                <label class="form-label" for="wa_admin_device_id">Device (untuk admin kirim pesan ke user)</label>
                                <select class="form-select" id="wa_admin_device_id" name="wa_admin_device_id">
                                    <option value="">Pilih device default admin</option>
                                    <?php foreach ($waDevices as $device): ?>
                                        <?php
                                            $deviceId = (string) ($device['device_id'] ?? '');
                                            $selected = ((string) ($waConfig['admin_send_device_id'] ?? '') === $deviceId) ? 'selected' : '';
                                            $status = (string) ($device['status'] ?? 'unknown');
                                            $label = normalizeDeviceLabel((string) ($device['label'] ?? ''), $deviceId);
                                        ?>
                                        <option value="<?php echo htmlspecialchars($deviceId, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $selected; ?>>
                                            <?php echo htmlspecialchars($label . ' [' . $deviceId . '] - ' . $status, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="full" style="display:flex;justify-content:flex-end;">
                                <button type="submit" class="btn btn-primary">Save WA API Config</button>
                            </div>
                        </form>

                        <hr style="border:none;border-top:1px solid var(--border-color,#e5e7eb);margin:1rem 0;">

                        <form method="post" class="admin-form-grid">
                            <input type="hidden" name="action" value="save_wa_runtime_config">
                            <div class="full">
                                <h4 style="margin:0 0 .25rem 0;font-size:.95rem;">WA Runtime (Delay, Pause, Queue)</h4>
                                <p style="margin:0;color:var(--text-secondary);font-size:.78rem;">Disimpan ke database (app_settings), langsung aktif tanpa edit .env.</p>
                            </div>
                            <div>
                                <label class="form-label" for="wa_delay_min_ms">Delay Min (ms)</label>
                                <input class="form-input" type="number" min="0" id="wa_delay_min_ms" name="wa_delay_min_ms" value="<?php echo htmlspecialchars((string) ($waRuntimeConfig['delay_min_ms'] ?? WA_SEND_DELAY_MIN_MS), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div>
                                <label class="form-label" for="wa_delay_max_ms">Delay Max (ms)</label>
                                <input class="form-input" type="number" min="0" id="wa_delay_max_ms" name="wa_delay_max_ms" value="<?php echo htmlspecialchars((string) ($waRuntimeConfig['delay_max_ms'] ?? WA_SEND_DELAY_MAX_MS), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div>
                                <label class="form-label" for="wa_burst_limit">Burst Limit</label>
                                <input class="form-input" type="number" min="1" id="wa_burst_limit" name="wa_burst_limit" value="<?php echo htmlspecialchars((string) ($waRuntimeConfig['burst_limit'] ?? WA_SEND_BURST_LIMIT), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div>
                                <label class="form-label" for="wa_burst_window_seconds">Burst Window (detik)</label>
                                <input class="form-input" type="number" min="1" id="wa_burst_window_seconds" name="wa_burst_window_seconds" value="<?php echo htmlspecialchars((string) ($waRuntimeConfig['burst_window_seconds'] ?? WA_SEND_BURST_WINDOW_SECONDS), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div>
                                <label class="form-label" for="wa_burst_pause_min_ms">Pause Burst Min (ms)</label>
                                <input class="form-input" type="number" min="0" id="wa_burst_pause_min_ms" name="wa_burst_pause_min_ms" value="<?php echo htmlspecialchars((string) ($waRuntimeConfig['burst_pause_min_ms'] ?? WA_SEND_BURST_PAUSE_MIN_MS), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div>
                                <label class="form-label" for="wa_burst_pause_max_ms">Pause Burst Max (ms)</label>
                                <input class="form-input" type="number" min="0" id="wa_burst_pause_max_ms" name="wa_burst_pause_max_ms" value="<?php echo htmlspecialchars((string) ($waRuntimeConfig['burst_pause_max_ms'] ?? WA_SEND_BURST_PAUSE_MAX_MS), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div>
                                <label class="form-label" for="wa_queue_batch_limit">Queue Batch / Run</label>
                                <input class="form-input" type="number" min="1" id="wa_queue_batch_limit" name="wa_queue_batch_limit" value="<?php echo htmlspecialchars((string) ($waRuntimeConfig['queue_batch_limit'] ?? WA_QUEUE_BATCH_LIMIT), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div>
                                <label class="form-label" for="wa_queue_max_attempts">Queue Max Attempts</label>
                                <input class="form-input" type="number" min="1" id="wa_queue_max_attempts" name="wa_queue_max_attempts" value="<?php echo htmlspecialchars((string) ($waRuntimeConfig['queue_max_attempts'] ?? WA_QUEUE_MAX_ATTEMPTS), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div>
                                <label style="display:flex;align-items:center;gap:.5rem;margin-top:1.8rem;">
                                    <input type="checkbox" name="wa_admin_queue_enabled" value="1" <?php echo !empty($waRuntimeConfig['admin_queue_enabled']) ? 'checked' : ''; ?>>
                                    <span>Default Queue untuk Admin</span>
                                </label>
                            </div>
                            <div>
                                <label style="display:flex;align-items:center;gap:.5rem;margin-top:1.8rem;">
                                    <input type="checkbox" name="wa_user_queue_enabled" value="1" <?php echo !empty($waRuntimeConfig['user_queue_enabled']) ? 'checked' : ''; ?>>
                                    <span>Default Queue untuk User</span>
                                </label>
                            </div>
                            <div class="full" style="display:flex;justify-content:flex-end;">
                                <button type="submit" class="btn btn-primary">Save WA Runtime Config</button>
                            </div>
                        </form>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h3 class="card-title">Config Status</h3>
                                <p class="card-subtitle">Snapshot konfigurasi aktif backend</p>
                            </div>
                        </div>
                        <div class="health-item">
                            <div class="health-meta">
                                <span>Server Endpoint</span>
                                <strong style="font-size:0.8rem;"><?php echo htmlspecialchars((string) ($waConfig['base_url'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        </div>
                        <div class="health-item">
                            <div class="health-meta">
                                <span>Basic Auth User</span>
                                <strong><?php echo htmlspecialchars((string) ($waConfig['api_key'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        </div>
                        <div class="health-item" style="margin-bottom:0;">
                            <div class="health-meta">
                                <span>Admin Default Device</span>
                                <strong><?php echo htmlspecialchars((string) (($waConfig['admin_send_device_id'] ?? '') !== '' ? $waConfig['admin_send_device_id'] : '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        </div>

                        <hr style="border:none;border-top:1px solid var(--border-color,#e5e7eb);margin:1rem 0;">

                        <div style="margin-bottom:.5rem;">
                            <span style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;opacity:.6;">Cron Auto-Reconnect</span>
                        </div>

                        <?php
                        $cronSecret = trim((string) env('CRON_SECRET', ''));
                        $cronUrl    = rtrim((string) APP_URL, '/') . '/api/cron-reconnect.php?token=' . urlencode($cronSecret);
                        $queueCronUrl = rtrim((string) APP_URL, '/') . '/api/cron-process-wa-queue.php?token=' . urlencode($cronSecret);
                        $waDelayRange = (int) ($waRuntimeConfig['delay_min_ms'] ?? WA_SEND_DELAY_MIN_MS) . ' - ' . (int) ($waRuntimeConfig['delay_max_ms'] ?? WA_SEND_DELAY_MAX_MS) . ' ms';
                        $waBurstRange = (int) ($waRuntimeConfig['burst_pause_min_ms'] ?? WA_SEND_BURST_PAUSE_MIN_MS) . ' - ' . (int) ($waRuntimeConfig['burst_pause_max_ms'] ?? WA_SEND_BURST_PAUSE_MAX_MS) . ' ms';
                        ?>

                        <div class="health-item">
                            <div class="health-meta">
                                <span>CRON_SECRET</span>
                                <strong style="font-size:.8rem;word-break:break-all;">
                                    <?php if ($cronSecret !== ''): ?>
                                        <span style="color:var(--success-color,#16a34a);">&#10003; Sudah diset</span>
                                    <?php else: ?>
                                        <span style="color:var(--danger-color,#dc2626);">&#10007; Belum diset di .env</span>
                                    <?php endif; ?>
                                </strong>
                            </div>
                        </div>

                        <?php if ($cronSecret !== ''): ?>
                        <div class="health-item">
                            <div class="health-meta" style="flex-direction:column;align-items:flex-start;gap:.25rem;">
                                <span>URL HTTP Trigger</span>
                                <code style="font-size:.72rem;word-break:break-all;background:var(--code-bg,#f1f5f9);padding:.2rem .4rem;border-radius:4px;display:block;width:100%;box-sizing:border-box;">
                                    <?php echo htmlspecialchars($cronUrl, ENT_QUOTES, 'UTF-8'); ?>
                                </code>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="health-item" style="margin-bottom:0;">
                            <div class="health-meta" style="flex-direction:column;align-items:flex-start;gap:.5rem;width:100%;">
                                <span>Cara Instalasi Crontab (Linux/Server)</span>
                                <code style="font-size:.72rem;white-space:pre;background:var(--code-bg,#f1f5f9);padding:.4rem .6rem;border-radius:4px;display:block;width:100%;box-sizing:border-box;overflow-x:auto;">*/5 * * * * php <?php echo htmlspecialchars(realpath(__DIR__ . '/../api/cron-reconnect.php') ?: '/path/to/api/cron-reconnect.php', ENT_QUOTES, 'UTF-8'); ?> >> /var/log/wa-reconnect.log 2>&amp;1</code>
                                <span style="font-size:.72rem;opacity:.7;">Atau via Windows Task Scheduler, jalankan setiap 5 menit:</span>
                                <code style="font-size:.72rem;white-space:pre;background:var(--code-bg,#f1f5f9);padding:.4rem .6rem;border-radius:4px;display:block;width:100%;box-sizing:border-box;overflow-x:auto;"><?php echo htmlspecialchars(PHP_BINARY, ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars(realpath(__DIR__ . '/../api/cron-reconnect.php') ?: __DIR__ . '/../api/cron-reconnect.php', ENT_QUOTES, 'UTF-8'); ?></code>
                            </div>
                        </div>

                        <hr style="border:none;border-top:1px solid var(--border-color,#e5e7eb);margin:1rem 0;">

                        <div style="margin-bottom:.5rem;">
                            <span style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;opacity:.6;">WA Queue &amp; Throttle</span>
                        </div>

                        <div class="health-item">
                            <div class="health-meta">
                                <span>Delay Kirim Acak</span>
                                <strong><?php echo htmlspecialchars($waDelayRange, ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        </div>
                        <div class="health-item">
                            <div class="health-meta">
                                <span>Ambang Burst</span>
                                <strong><?php echo htmlspecialchars((string) ($waRuntimeConfig['burst_limit'] ?? WA_SEND_BURST_LIMIT) . ' request / ' . (string) ($waRuntimeConfig['burst_window_seconds'] ?? WA_SEND_BURST_WINDOW_SECONDS) . ' detik', ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        </div>
                        <div class="health-item">
                            <div class="health-meta">
                                <span>Pause Burst Acak</span>
                                <strong><?php echo htmlspecialchars($waBurstRange, ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        </div>
                        <div class="health-item">
                            <div class="health-meta">
                                <span>Queue Admin Default</span>
                                <strong><?php echo !empty($waRuntimeConfig['admin_queue_enabled']) ? 'Aktif' : 'Nonaktif'; ?></strong>
                            </div>
                        </div>
                        <div class="health-item">
                            <div class="health-meta">
                                <span>Queue User Default</span>
                                <strong><?php echo !empty($waRuntimeConfig['user_queue_enabled']) ? 'Aktif' : 'Nonaktif'; ?></strong>
                            </div>
                        </div>
                        <div class="health-item">
                            <div class="health-meta">
                                <span>Batch Worker Queue</span>
                                <strong><?php echo htmlspecialchars((string) ($waRuntimeConfig['queue_batch_limit'] ?? WA_QUEUE_BATCH_LIMIT) . ' job / run', ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        </div>
                        <?php if ($cronSecret !== ''): ?>
                        <div class="health-item">
                            <div class="health-meta" style="flex-direction:column;align-items:flex-start;gap:.25rem;">
                                <span>URL HTTP Trigger Queue Worker</span>
                                <code style="font-size:.72rem;word-break:break-all;background:var(--code-bg,#f1f5f9);padding:.2rem .4rem;border-radius:4px;display:block;width:100%;box-sizing:border-box;">
                                    <?php echo htmlspecialchars($queueCronUrl, ENT_QUOTES, 'UTF-8'); ?>
                                </code>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="health-item" style="margin-bottom:0;">
                            <div class="health-meta" style="flex-direction:column;align-items:flex-start;gap:.5rem;width:100%;">
                                <span>Cron Worker Queue (Linux/Server)</span>
                                <code style="font-size:.72rem;white-space:pre;background:var(--code-bg,#f1f5f9);padding:.4rem .6rem;border-radius:4px;display:block;width:100%;box-sizing:border-box;overflow-x:auto;">*/1 * * * * php <?php echo htmlspecialchars(realpath(__DIR__ . '/../api/cron-process-wa-queue.php') ?: '/path/to/api/cron-process-wa-queue.php', ENT_QUOTES, 'UTF-8'); ?> >> /var/log/wa-queue-worker.log 2>&amp;1</code>
                                <span style="font-size:.72rem;opacity:.7;">Windows Task Scheduler:</span>
                                <code style="font-size:.72rem;white-space:pre;background:var(--code-bg,#f1f5f9);padding:.4rem .6rem;border-radius:4px;display:block;width:100%;box-sizing:border-box;overflow-x:auto;"><?php echo htmlspecialchars(PHP_BINARY, ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars(realpath(__DIR__ . '/../api/cron-process-wa-queue.php') ?: __DIR__ . '/../api/cron-process-wa-queue.php', ENT_QUOTES, 'UTF-8'); ?></code>
                                <span style="font-size:.72rem;opacity:.7;">Per request bisa override dengan JSON <strong>use_queue</strong>: <strong>true</strong> atau <strong>false</strong>.</span>
                            </div>
                        </div>

                        <hr style="border:none;border-top:1px solid var(--border-color,#e5e7eb);margin:1rem 0;">

                        <div style="margin-bottom:.5rem;">
                            <span style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;opacity:.6;">Queue Monitor</span>
                        </div>

                        <div class="health-item">
                            <div class="health-meta">
                                <span>Total Job</span>
                                <strong><?php echo (int) ($waQueueSummary['total'] ?? 0); ?></strong>
                            </div>
                        </div>
                        <div class="health-item">
                            <div class="health-meta">
                                <span>Pending / Processing</span>
                                <strong><?php echo (int) ($waQueueSummary['pending'] ?? 0); ?> / <?php echo (int) ($waQueueSummary['processing'] ?? 0); ?></strong>
                            </div>
                        </div>
                        <div class="health-item">
                            <div class="health-meta">
                                <span>Sent / Failed</span>
                                <strong><?php echo (int) ($waQueueSummary['sent'] ?? 0); ?> / <?php echo (int) ($waQueueSummary['failed'] ?? 0); ?></strong>
                            </div>
                        </div>

                        <div class="health-item" style="margin-bottom:0;">
                            <div class="health-meta" style="display:block;width:100%;">
                                <span style="display:block;margin-bottom:.35rem;">12 Job Terbaru</span>
                                <div style="overflow-x:auto;">
                                    <table style="width:100%;border-collapse:collapse;font-size:.75rem;">
                                        <thead>
                                            <tr>
                                                <th style="text-align:left;padding:.35rem;border-bottom:1px solid var(--border-color,#e5e7eb);">ID</th>
                                                <th style="text-align:left;padding:.35rem;border-bottom:1px solid var(--border-color,#e5e7eb);">Scope</th>
                                                <th style="text-align:left;padding:.35rem;border-bottom:1px solid var(--border-color,#e5e7eb);">Status</th>
                                                <th style="text-align:left;padding:.35rem;border-bottom:1px solid var(--border-color,#e5e7eb);">Phone</th>
                                                <th style="text-align:left;padding:.35rem;border-bottom:1px solid var(--border-color,#e5e7eb);">Attempt</th>
                                                <th style="text-align:left;padding:.35rem;border-bottom:1px solid var(--border-color,#e5e7eb);">Updated</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($waQueueRecent)): ?>
                                                <?php foreach ($waQueueRecent as $job): ?>
                                                    <tr>
                                                        <td style="padding:.35rem;border-bottom:1px solid var(--border-color,#e5e7eb);"><?php echo (int) ($job['id'] ?? 0); ?></td>
                                                        <td style="padding:.35rem;border-bottom:1px solid var(--border-color,#e5e7eb);"><?php echo htmlspecialchars((string) ($job['queue_scope'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td style="padding:.35rem;border-bottom:1px solid var(--border-color,#e5e7eb);"><?php echo htmlspecialchars((string) ($job['status'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td style="padding:.35rem;border-bottom:1px solid var(--border-color,#e5e7eb);"><?php echo htmlspecialchars((string) ($job['phone_number'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td style="padding:.35rem;border-bottom:1px solid var(--border-color,#e5e7eb);"><?php echo (int) ($job['attempts'] ?? 0); ?>/<?php echo (int) ($job['max_attempts'] ?? 0); ?></td>
                                                        <td style="padding:.35rem;border-bottom:1px solid var(--border-color,#e5e7eb);white-space:nowrap;"><?php echo htmlspecialchars((string) ($job['updated_at'] ?? $job['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                                    </tr>
                                                    <?php if (!empty($job['last_error'])): ?>
                                                    <tr>
                                                        <td colspan="6" style="padding:.35rem .35rem .5rem .35rem;border-bottom:1px solid var(--border-color,#e5e7eb);color:#ef4444;word-break:break-word;">
                                                            <?php echo htmlspecialchars((string) $job['last_error'], ENT_QUOTES, 'UTF-8'); ?>
                                                        </td>
                                                    </tr>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="6" style="padding:.5rem;border-bottom:1px solid var(--border-color,#e5e7eb);opacity:.7;">Belum ada job queue.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </section>

            <section id="config-panel-vpn" class="config-panel" data-config-panel="vpn" role="tabpanel" aria-label="Pengaturan VPN API">
                <div class="two-col" style="margin-bottom:1.5rem;">
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h3 class="card-title">VPN API Configuration</h3>
                                <p class="card-subtitle">Sesuai FastAPI L2TP/IPsec: Basic Auth + endpoint users dan port-forwardings.</p>
                            </div>
                        </div>

                        <?php if ($vpnConfigError !== ''): ?>
                            <div class="admin-alert error"><?php echo htmlspecialchars($vpnConfigError, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                        <?php if ($vpnConfigSuccess !== ''): ?>
                            <div class="admin-alert success"><?php echo htmlspecialchars($vpnConfigSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>

                        <form method="post" class="admin-form-grid">
                            <input type="hidden" name="action" value="save_vpn_config">
                            <div>
                                <label class="form-label" for="vpn_provider_name">Provider Name</label>
                                <input class="form-input" type="text" id="vpn_provider_name" name="vpn_provider_name"
                                    value="<?php echo htmlspecialchars((string) ($vpnConfig['provider_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="Contoh: FastAPI Libreswan L2TP">
                            </div>
                            <div>
                                <label class="form-label" for="vpn_auth_scheme">Auth Scheme</label>
                                <select class="form-select" id="vpn_auth_scheme" name="vpn_auth_scheme">
                                    <option value="basic" <?php echo (($vpnConfig['auth_scheme'] ?? 'basic') === 'basic') ? 'selected' : ''; ?>>basic (disarankan)</option>
                                    <option value="bearer" <?php echo (($vpnConfig['auth_scheme'] ?? '') === 'bearer') ? 'selected' : ''; ?>>bearer</option>
                                </select>
                            </div>
                            <div>
                                <label class="form-label" for="vpn_request_timeout_seconds">Request Timeout (detik)</label>
                                <input class="form-input" type="number" min="1" id="vpn_request_timeout_seconds" name="vpn_request_timeout_seconds"
                                    value="<?php echo htmlspecialchars((string) ($vpnConfig['request_timeout_seconds'] ?? 30), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="full">
                                <label class="form-label" for="vpn_base_url">Server VPN API</label>
                                <input class="form-input" type="url" id="vpn_base_url" name="vpn_base_url" required
                                    value="<?php echo htmlspecialchars((string) ($vpnConfig['base_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="https://vpn-api.example.com">
                            </div>
                            <div>
                                <label class="form-label" for="vpn_api_key">Basic Auth Username</label>
                                <input class="form-input" type="text" id="vpn_api_key" name="vpn_api_key"
                                    value="<?php echo htmlspecialchars((string) ($vpnConfig['api_key'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="Contoh: admin">
                            </div>
                            <div>
                                <label class="form-label" for="vpn_api_secret">Basic Auth Password</label>
                                <input class="form-input" type="password" id="vpn_api_secret" name="vpn_api_secret"
                                    value="<?php echo htmlspecialchars((string) ($vpnConfig['api_secret'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="Contoh: passwordkuat123">
                            </div>
                            <div class="full">
                                <label class="form-label" for="vpn_access_token">Access Token (Bearer)</label>
                                <input class="form-input" type="text" id="vpn_access_token" name="vpn_access_token"
                                    value="<?php echo htmlspecialchars((string) ($vpnConfig['access_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="Opsional jika memilih auth bearer">
                            </div>
                            <div>
                                <label class="form-label" for="vpn_endpoint_users">Endpoint Users</label>
                                <input class="form-input" type="text" id="vpn_endpoint_users" name="vpn_endpoint_users"
                                    value="<?php echo htmlspecialchars((string) ($vpnConfig['endpoint_users'] ?? '/users'), ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="/users">
                            </div>
                            <div>
                                <label class="form-label" for="vpn_endpoint_port_forwardings">Endpoint Port Forwardings</label>
                                <input class="form-input" type="text" id="vpn_endpoint_port_forwardings" name="vpn_endpoint_port_forwardings"
                                    value="<?php echo htmlspecialchars((string) ($vpnConfig['endpoint_port_forwardings'] ?? '/port-forwardings'), ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="/port-forwardings">
                            </div>
                            <div class="full">
                                <label class="form-label" for="vpn_subnet_prefix">Subnet Prefix IP VPN</label>
                                <input class="form-input" type="text" id="vpn_subnet_prefix" name="vpn_subnet_prefix"
                                    value="<?php echo htmlspecialchars((string) ($vpnConfig['subnet_prefix'] ?? '192.168.12'), ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="192.168.12">
                                <p style="margin:.3rem 0 0;font-size:.75rem;color:var(--text-secondary);">Format: X.X.X — 3 oktet pertama subnet VPN. User hanya mengisi oktet terakhir. Contoh: <code>192.168.12</code></p>
                            </div>
                            <div>
                                <label class="form-label" for="vpn_ip_range_start">IP Range Start (oktet terakhir)</label>
                                <input class="form-input" type="number" min="1" max="253" id="vpn_ip_range_start" name="vpn_ip_range_start"
                                    value="<?php echo (int) ($vpnConfig['ip_range_start'] ?? 2); ?>"
                                    placeholder="2">
                            </div>
                            <div>
                                <label class="form-label" for="vpn_ip_range_end">IP Range End (oktet terakhir)</label>
                                <input class="form-input" type="number" min="2" max="254" id="vpn_ip_range_end" name="vpn_ip_range_end"
                                    value="<?php echo (int) ($vpnConfig['ip_range_end'] ?? 254); ?>"
                                    placeholder="254">
                            </div>
                            <div class="full">
                                <label class="form-label" for="vpn_webhook_url">Webhook URL</label>
                                <input class="form-input" type="url" id="vpn_webhook_url" name="vpn_webhook_url"
                                    value="<?php echo htmlspecialchars((string) ($vpnConfig['webhook_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="https://domain-anda/api/vpn-webhook.php">
                            </div>
                            <div class="full">
                                <label class="form-label" for="vpn_verify_token">Verify Token</label>
                                <input class="form-input" type="text" id="vpn_verify_token" name="vpn_verify_token"
                                    value="<?php echo htmlspecialchars((string) ($vpnConfig['verify_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="Token verifikasi untuk webhook callback">
                            </div>
                            <div class="full">
                                <label style="display:flex;align-items:center;gap:.5rem;">
                                    <input type="checkbox" name="vpn_is_enabled" value="1" <?php echo !empty($vpnConfig['is_enabled']) ? 'checked' : ''; ?>>
                                    <span>Aktifkan VPN API</span>
                                </label>
                            </div>
                            <div class="full" style="display:flex;justify-content:flex-end;">
                                <button type="submit" class="btn btn-primary">Save VPN API Config</button>
                            </div>
                        </form>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h3 class="card-title">VPN Config Status</h3>
                                <p class="card-subtitle">Snapshot konfigurasi VPN API aktif.</p>
                            </div>
                        </div>
                        <div class="health-item">
                            <div class="health-meta">
                                <span>Provider</span>
                                <strong><?php echo htmlspecialchars((string) (($vpnConfig['provider_name'] ?? '') !== '' ? $vpnConfig['provider_name'] : '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        </div>
                        <div class="health-item">
                            <div class="health-meta">
                                <span>Server Endpoint</span>
                                <strong style="font-size:0.8rem;"><?php echo htmlspecialchars((string) (($vpnConfig['base_url'] ?? '') !== '' ? $vpnConfig['base_url'] : '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        </div>
                        <div class="health-item">
                            <div class="health-meta">
                                <span>Auth Scheme</span>
                                <strong><?php echo htmlspecialchars(strtoupper((string) ($vpnConfig['auth_scheme'] ?? 'basic')), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        </div>
                        <div class="health-item">
                            <div class="health-meta">
                                <span>Basic Auth Username</span>
                                <strong><?php echo htmlspecialchars((string) (($vpnConfig['api_key'] ?? '') !== '' ? $vpnConfig['api_key'] : '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        </div>
                        <div class="health-item">
                            <div class="health-meta">
                                <span>Endpoint Users</span>
                                <strong style="font-size:0.8rem;"><?php echo htmlspecialchars(rtrim((string) ($vpnConfig['base_url'] ?? ''), '/') . (string) ($vpnConfig['endpoint_users'] ?? '/users'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        </div>
                        <div class="health-item">
                            <div class="health-meta">
                                <span>Endpoint Port Forwardings</span>
                                <strong style="font-size:0.8rem;"><?php echo htmlspecialchars(rtrim((string) ($vpnConfig['base_url'] ?? ''), '/') . (string) ($vpnConfig['endpoint_port_forwardings'] ?? '/port-forwardings'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        </div>
                        <div class="health-item">
                            <div class="health-meta">
                                <span>Webhook URL</span>
                                <strong style="font-size:0.75rem;"><?php echo htmlspecialchars((string) (($vpnConfig['webhook_url'] ?? '') !== '' ? $vpnConfig['webhook_url'] : '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        </div>
                        <div class="health-item" style="margin-bottom:0;">
                            <div class="health-meta">
                                <span>Status</span>
                                <strong><?php echo !empty($vpnConfig['is_enabled']) ? 'Enabled' : 'Disabled'; ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section id="config-panel-plans" class="config-panel" data-config-panel="plans" role="tabpanel" aria-label="Paket Subscription">
                <div class="card" style="margin-bottom:1.5rem;">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">Paket Subscription</h3>
                            <p class="card-subtitle">Kelola harga, durasi, dan batas fitur (VPN, WA Device, Proxy Route) setiap paket.</p>
                        </div>
                    </div>

                    <?php if ($planConfigError !== ''): ?>
                        <div class="admin-alert error"><?php echo htmlspecialchars($planConfigError, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    <?php if ($planConfigSuccess !== ''): ?>
                        <div class="admin-alert success"><?php echo htmlspecialchars($planConfigSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>

                    <?php if (empty($plans)): ?>
                        <p style="color:var(--text-secondary);font-size:0.875rem;">Belum ada paket. Jalankan migration SQL untuk seed data awal.</p>
                    <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table style="width:100%;border-collapse:collapse;font-size:0.875rem;">
                            <thead>
                                <tr style="background:var(--bg-alt,#f8fafc);color:var(--text-secondary);font-size:0.75rem;text-transform:uppercase;letter-spacing:.05em;">
                                    <th style="padding:10px 14px;text-align:left;">Nama</th>
                                    <th style="padding:10px 14px;text-align:left;">Label</th>
                                    <th style="padding:10px 14px;text-align:left;">Harga</th>
                                    <th style="padding:10px 14px;text-align:left;">Durasi</th>
                                    <th style="padding:10px 14px;text-align:left;">VPN</th>
                                    <th style="padding:10px 14px;text-align:left;">WA Device</th>
                                    <th style="padding:10px 14px;text-align:left;">Proxy</th>
                                    <th style="padding:10px 14px;text-align:left;">Aktif</th>
                                    <th style="padding:10px 14px;text-align:left;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($plans as $plan): ?>
                                <tr style="border-bottom:1px solid var(--border-color,#e5e7eb);" id="plan-row-<?php echo (int) $plan['id']; ?>">
                                    <td style="padding:10px 14px;font-weight:600;"><?php echo htmlspecialchars((string) $plan['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:10px 14px;"><?php echo htmlspecialchars((string) $plan['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:10px 14px;">Rp <?php echo number_format((float) $plan['price'], 0, ',', '.'); ?></td>
                                    <td style="padding:10px 14px;"><?php echo (int) $plan['duration_days']; ?> hari</td>
                                    <td style="padding:10px 14px;"><?php echo (int) $plan['vpn_limit']; ?></td>
                                    <td style="padding:10px 14px;"><?php echo (int) $plan['wa_device_limit']; ?></td>
                                    <td style="padding:10px 14px;"><?php echo (int) $plan['proxy_route_limit']; ?></td>
                                    <td style="padding:10px 14px;">
                                        <?php if ($plan['is_active']): ?>
                                            <span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:.75rem;font-weight:600;background:#d1fae5;color:#065f46;">Aktif</span>
                                        <?php else: ?>
                                            <span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:.75rem;font-weight:600;background:#fee2e2;color:#991b1b;">Nonaktif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:10px 14px;">
                                        <button type="button" onclick="togglePlanEdit(<?php echo (int) $plan['id']; ?>)" class="btn btn-secondary" style="padding:4px 12px;font-size:.8rem;">Edit</button>
                                    </td>
                                </tr>
                                <tr id="plan-edit-<?php echo (int) $plan['id']; ?>" style="display:none;background:var(--bg-alt,#f8fafc);">
                                    <td colspan="9" style="padding:1rem 14px;border-bottom:1px solid var(--border-color,#e5e7eb);">
                                        <form method="post">
                                            <input type="hidden" name="action" value="save_plan_config">
                                            <input type="hidden" name="plan_id" value="<?php echo (int) $plan['id']; ?>">
                                            <p style="margin:0 0 .75rem 0;font-size:.8125rem;color:var(--text-secondary);">Edit Paket: <strong><?php echo htmlspecialchars((string) $plan['name'], ENT_QUOTES, 'UTF-8'); ?></strong></p>
                                            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.75rem;align-items:end;">
                                                <div>
                                                    <label class="form-label">Label Tampilan</label>
                                                    <input class="form-input" type="text" name="plan_label" required
                                                        value="<?php echo htmlspecialchars((string) $plan['label'], ENT_QUOTES, 'UTF-8'); ?>">
                                                </div>
                                                <div>
                                                    <label class="form-label">Harga (Rp)</label>
                                                    <input class="form-input" type="number" name="plan_price" min="0" step="1000"
                                                        value="<?php echo (int) $plan['price']; ?>">
                                                </div>
                                                <div>
                                                    <label class="form-label">Durasi (hari)</label>
                                                    <input class="form-input" type="number" name="plan_duration_days" min="1"
                                                        value="<?php echo (int) $plan['duration_days']; ?>">
                                                </div>
                                                <div>
                                                    <label class="form-label">Maks VPN</label>
                                                    <input class="form-input" type="number" name="plan_vpn_limit" min="0"
                                                        value="<?php echo (int) $plan['vpn_limit']; ?>">
                                                </div>
                                                <div>
                                                    <label class="form-label">Maks WA Device</label>
                                                    <input class="form-input" type="number" name="plan_wa_device_limit" min="0"
                                                        value="<?php echo (int) $plan['wa_device_limit']; ?>">
                                                </div>
                                                <div>
                                                    <label class="form-label">Maks Proxy Route</label>
                                                    <input class="form-input" type="number" name="plan_proxy_route_limit" min="0"
                                                        value="<?php echo (int) $plan['proxy_route_limit']; ?>">
                                                </div>
                                                <div>
                                                    <label class="form-label">Status</label>
                                                    <label style="display:flex;align-items:center;gap:.4rem;margin-top:.5rem;">
                                                        <input type="checkbox" name="plan_is_active" value="1" <?php echo $plan['is_active'] ? 'checked' : ''; ?>>
                                                        <span style="font-size:.875rem;">Aktif</span>
                                                    </label>
                                                </div>
                                                <div style="display:flex;gap:.5rem;align-items:flex-end;">
                                                    <button type="submit" class="btn btn-primary" style="font-size:.8rem;padding:6px 14px;">Simpan</button>
                                                    <button type="button" onclick="togglePlanEdit(<?php echo (int) $plan['id']; ?>)" class="btn btn-secondary" style="font-size:.8rem;padding:6px 14px;">Batal</button>
                                                </div>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p style="margin:.75rem 0 0;font-size:.78rem;color:var(--text-secondary);">Nama paket (free/basic/premium) tidak dapat diubah karena digunakan sebagai kunci referensi sistem.</p>
                    <?php endif; ?>
                </div>
            </section>

            <!-- ── 6. Payment Gateway ─────────────────────────────────────── -->
            <section id="config-panel-payment" class="config-panel" data-config-panel="payment" role="tabpanel" aria-label="Payment Gateway">
                <div class="config-card">
                    <h2 class="config-card-title">6. Payment Gateway (Duitku)</h2>
                    <p style="margin:0 0 1.2rem;font-size:.875rem;color:var(--text-secondary);">Konfigurasi Duitku disimpan di database dan dipakai oleh sistem pembayaran. Sandbox Duitku mendukung localhost/XAMPP untuk testing.</p>

                    <?php if ($pgConfigError !== ''): ?>
                        <div class="admin-alert error"><?php echo htmlspecialchars($pgConfigError, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    <?php if ($pgConfigSuccess !== ''): ?>
                        <div class="admin-alert success"><?php echo htmlspecialchars($pgConfigSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>

                    <form method="POST" action="config.php?mode=payment">
                        <input type="hidden" name="action" value="save_payment_gateway_config">
                        <div class="admin-form-grid">
                            <div class="form-group">
                                <label for="pg-merchant-code">Merchant Code <span style="color:#f87171">*</span></label>
                                <input type="text" id="pg-merchant-code" name="duitku_merchant_code"
                                    value="<?php echo htmlspecialchars($pgConfig['merchant_code'], ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="Merchant Code Duitku"
                                    autocomplete="off" required>
                            </div>
                            <div class="form-group">
                                <label for="pg-api-key">API Key <span style="color:#f87171">*</span></label>
                                <input type="text" id="pg-api-key" name="duitku_api_key"
                                    value="<?php echo htmlspecialchars($pgConfig['api_key'], ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="API Key dari dashboard Duitku"
                                    autocomplete="off" required>
                            </div>
                            <div class="form-group full">
                                <label for="pg-base-url">Base URL API</label>
                                <input type="url" id="pg-base-url" name="duitku_base_url"
                                    value="<?php echo htmlspecialchars($pgConfig['base_url'], ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="https://sandbox.duitku.com/webapi/api/merchant">
                                <small style="color:var(--text-secondary);font-size:.78rem;">Production: <code>https://passport.duitku.com/webapi/api/merchant</code> &nbsp;|&nbsp; Sandbox: <code>https://sandbox.duitku.com/webapi/api/merchant</code></small>
                            </div>
                            <div class="form-group full" style="display:flex;align-items:center;gap:.75rem;">
                                <input type="checkbox" id="pg-sandbox" name="duitku_sandbox" value="1"
                                    <?php echo $pgConfig['sandbox'] === '1' ? 'checked' : ''; ?>>
                                <label for="pg-sandbox" style="margin:0;cursor:pointer;">Mode Sandbox (direkomendasikan saat testing localhost/XAMPP)</label>
                            </div>
                        </div>
                        <div style="margin-top:1.25rem;display:flex;gap:.75rem;align-items:center;">
                            <button type="submit" class="btn btn-primary">Simpan Konfigurasi</button>
                            <a href="https://dashboard.duitku.com" target="_blank" rel="noopener noreferrer"
                                style="font-size:.85rem;color:var(--text-secondary);text-decoration:underline;">Dashboard Duitku &rarr;</a>
                        </div>
                    </form>

                    <div style="margin-top:1.5rem;padding:1rem;background:var(--bg-surface);border:1px dashed var(--border-color);border-radius:var(--radius-md);font-size:.82rem;color:var(--text-secondary);">
                        <strong style="color:var(--text-primary);">Status saat ini:</strong><br>
                        Merchant Code: <code><?php echo $pgConfig['merchant_code'] !== '' ? str_repeat('*', max(0, strlen($pgConfig['merchant_code']) - 4)) . substr($pgConfig['merchant_code'], -4) : '<em>belum diset</em>'; ?></code> &nbsp;|
                        API Key: <code><?php echo $pgConfig['api_key'] !== '' ? str_repeat('*', 8) . substr($pgConfig['api_key'], -4) : '<em>belum diset</em>'; ?></code> &nbsp;|
                        Mode: <strong><?php echo $pgConfig['sandbox'] === '1' ? '<span style="color:#fbbf24">Sandbox</span>' : '<span style="color:#4ade80">Production</span>'; ?></strong>
                    </div>
                </div>
            </section>
        </main>

        <footer class="footer">
            <p>&copy; 2026 TEAM <?php echo htmlspecialchars(webCompanyName(), ENT_QUOTES, 'UTF-8'); ?></p>
        </footer>
    </div>

    <script>
        (function () {
            const modeButtons = Array.from(document.querySelectorAll('[data-config-mode]'));
            const panels = Array.from(document.querySelectorAll('[data-config-panel]'));
            if (!modeButtons.length || !panels.length) {
                return;
            }

            const allowedModes = new Set(['seo', 'webpage', 'api', 'vpn', 'plans', 'payment']);
            const requestedMode = <?php echo json_encode((string) ($_GET['mode'] ?? '')); ?>;
            const initialMode = <?php echo json_encode(
                in_array($postAction, ['save_wa_config', 'save_wa_runtime_config'], true)
                    ? 'api'
                    : ($postAction === 'save_vpn_config'
                        ? 'vpn'
                    : ($postAction === 'save_plan_config'
                        ? 'plans'
                    : ($postAction === 'save_payment_gateway_config'
                        ? 'payment'
                    : ($postAction === 'save_seo_config'
                        ? 'seo'
                        : 'webpage'))))
            ); ?>;
            const startMode = allowedModes.has(requestedMode) ? requestedMode : initialMode;

            const setMode = (mode) => {
                const safeMode = allowedModes.has(mode) ? mode : 'webpage';

                modeButtons.forEach((btn) => {
                    const active = btn.getAttribute('data-config-mode') === safeMode;
                    btn.classList.toggle('active', active);
                    btn.setAttribute('aria-selected', active ? 'true' : 'false');
                });

                panels.forEach((panel) => {
                    panel.classList.toggle('active', panel.getAttribute('data-config-panel') === safeMode);
                });
            };

            modeButtons.forEach((btn) => {
                btn.addEventListener('click', function () {
                    const mode = btn.getAttribute('data-config-mode') || 'webpage';
                    setMode(mode);
                });
            });

            setMode(startMode);
        })();
    </script>
    <script>
    function togglePlanEdit(planId) {
        const editRow = document.getElementById('plan-edit-' + planId);
        if (!editRow) return;
        editRow.style.display = editRow.style.display === 'none' ? 'table-row' : 'none';
    }
    </script>
    <script src="../templatemo-daynight-script.js?v=<?php echo (int) (file_exists(__DIR__ . '/../templatemo-daynight-script.js') ? filemtime(__DIR__ . '/../templatemo-daynight-script.js') : time()); ?>"></script>

    <!-- Account Modals -->
    <div class="acct-modal-overlay" id="modal-edit-profile" onclick="if(event.target===this)closeAccountModal('modal-edit-profile')">
        <div class="acct-modal">
            <button class="modal-close" onclick="closeAccountModal('modal-edit-profile')" title="Tutup"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
            <h3>Edit Profil</h3>
            <div class="modal-msg"></div>
            <div class="form-group"><label for="ep-full-name">Nama Lengkap</label><input type="text" id="ep-full-name" placeholder="Nama Lengkap" autocomplete="name"></div>
            <div class="form-group"><label for="ep-phone">No. HP</label><input type="text" id="ep-phone" placeholder="62xxxxxxxxxx"></div>
            <div class="form-group"><label for="ep-email">Email (tidak dapat diubah)</label><input type="email" id="ep-email" disabled></div>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="closeAccountModal('modal-edit-profile')">Batal</button>
                <button class="btn btn-primary" id="ep-submit-btn" onclick="submitEditProfile()">Simpan</button>
            </div>
        </div>
    </div>
    <div class="acct-modal-overlay" id="modal-change-password" onclick="if(event.target===this)closeAccountModal('modal-change-password')">
        <div class="acct-modal">
            <button class="modal-close" onclick="closeAccountModal('modal-change-password')" title="Tutup"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
            <h3>Ganti Password</h3>
            <div class="modal-msg"></div>
            <div class="form-group"><label for="cp-old-password">Password Lama</label><input type="password" id="cp-old-password" placeholder="Password saat ini" autocomplete="current-password"></div>
            <div class="form-group"><label for="cp-new-password">Password Baru</label><input type="password" id="cp-new-password" placeholder="Minimal 6 karakter" autocomplete="new-password"></div>
            <div class="form-group"><label for="cp-confirm-password">Konfirmasi Password Baru</label><input type="password" id="cp-confirm-password" placeholder="Ulangi password baru" autocomplete="new-password"></div>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="closeAccountModal('modal-change-password')">Batal</button>
                <button class="btn btn-primary" id="cp-submit-btn" onclick="submitChangePassword()">Simpan</button>
            </div>
        </div>
    </div>
</body>
</html>
