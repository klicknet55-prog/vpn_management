<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function gowaRuntimeConfig(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $cfg = [
        'base_url' => rtrim(GOWA_BASE_URL, '/'),
        'username' => GOWA_USERNAME,
        'password' => GOWA_PASSWORD,
    ];

    try {
        $db = getDB();
        $stmt = $db->query(
            "SELECT base_url, api_key, api_secret
             FROM api_configurations
             WHERE service_type = 'whatsapp' AND is_enabled = 1
             ORDER BY updated_at DESC, id DESC
             LIMIT 1"
        );
        $row = $stmt->fetch();
        if (is_array($row)) {
            $dbBaseUrl = trim((string) ($row['base_url'] ?? ''));
            $dbUser = trim((string) ($row['api_key'] ?? ''));
            $dbPass = (string) ($row['api_secret'] ?? '');

            if ($dbBaseUrl !== '') {
                $cfg['base_url'] = rtrim($dbBaseUrl, '/');
            }
            if ($dbUser !== '') {
                $cfg['username'] = $dbUser;
            }
            if ($dbPass !== '') {
                $cfg['password'] = $dbPass;
            }
        }
    } catch (Throwable $e) {
        // Fallback to constants from api/config.php when DB config is unavailable.
    }

    return $cfg;
}

function gowaBaseUrl(): string
{
    $cfg = gowaRuntimeConfig();
    return rtrim((string) $cfg['base_url'], '/');
}

/**
 * Make an HTTP request to the GoWA REST API using Basic Authentication.
 *
 * GoWA uses HTTP Basic Auth configured via --basic-auth flag.
 * Reference: https://github.com/aldinokemal/go-whatsapp-web-multidevice
 *
 * @param  string      $method         HTTP method (GET, POST, DELETE, …)
 * @param  string      $path           API path, e.g. /devices or /send/message
 * @param  array|null  $body           Request body (will be JSON-encoded)
 * @param  array       $extraHeaders   Additional HTTP headers, e.g. ['X-Device-Id: 628xxx@s.whatsapp.net']
 * @return array       ['ok' => bool, 'status' => int, 'data' => mixed, 'raw' => string, 'content_type' => string]
 */
function gowaRequest(string $method, string $path, ?array $body = null, array $extraHeaders = []): array
{
    if (!extension_loaded('curl')) {
        throw new RuntimeException('PHP cURL extension is required');
    }

    $cfg = gowaRuntimeConfig();

    $url = preg_match('/^https?:\/\//i', $path)
        ? $path
        : rtrim((string) $cfg['base_url'], '/') . $path;
    $ch  = curl_init();

    $headers = array_merge(
        ['Content-Type: application/json', 'Accept: application/json'],
        $extraHeaders
    );

    curl_setopt_array($ch, [
        CURLOPT_URL             => $url,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_CUSTOMREQUEST   => strtoupper($method),
        CURLOPT_HTTPHEADER      => $headers,
        CURLOPT_USERPWD         => (string) $cfg['username'] . ':' . (string) $cfg['password'],
        CURLOPT_TIMEOUT         => 30,
        CURLOPT_CONNECTTIMEOUT  => 10,
        CURLOPT_SSL_VERIFYPEER  => false,   // Allow self-signed certs
        CURLOPT_SSL_VERIFYHOST  => false,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_HEADER          => false,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw         = curl_exec($ch);
    $httpCode    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr     = curl_error($ch);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('GoWA cURL error: ' . $curlErr);
    }

    $isText = str_contains($contentType, 'application/json')
           || str_contains($contentType, 'text/');
    $data   = $isText ? (json_decode($raw, true) ?? $raw) : $raw;

    return [
        'ok'           => $httpCode >= 200 && $httpCode < 300,
        'status'       => $httpCode,
        'data'         => $data,
        'raw'          => $raw,
        'content_type' => $contentType,
    ];
}

/**
 * Normalize a GoWA response that may be wrapped in a 'data' key.
 * GoWA typically returns: { "code": 200, "message": "...", "results": {...} }
 * or just the object directly. This normalizes both shapes.
 */
function gowaUnwrap(mixed $data): mixed
{
    if (is_array($data)) {
        return $data['results'] ?? $data['data'] ?? $data;
    }
    return $data;
}
