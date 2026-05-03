<?php
/**
 * VPN Controller API
 *
 * Actions (POST JSON):
 * - create_user
 * - disable_user
 * - enable_user
 * - delete_user
 * - disconnect_user
 * - create_port_forwarding
 * - delete_port_forwarding
 * - list_users
 * - list_port_forwardings
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

session_start();

header('Content-Type: application/json; charset=utf-8');
setCorsHeaders(false);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed', 'code' => 405]);
    exit;
}

if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'code' => 401]);
    exit;
}

$roles = $_SESSION['roles'] ?? [];
$isAllowed = in_array('admin', $roles, true)
    || in_array('super_admin', $roles, true)
    || in_array('user', $roles, true);
if (!$isAllowed) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden', 'code' => 403]);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = [];
}

$action = trim((string) ($payload['action'] ?? ''));
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

try {
    $db = getDB();
    ensureVpnUsersTable($db);
    ensureVpnPortForwardingsTable($db);

    $vpnConfig = null;

    switch ($action) {
        case 'list_users':
            handleListUsers($db, $currentUserId);
            break;
        case 'list_port_forwardings':
            handleListPortForwardings($db, $currentUserId);
            break;
        case 'get_subnet_config':
            $vpnConfig = loadVpnApiConfig($db);
            echo json_encode([
                'status' => 'success',
                'code' => 200,
                'data' => [
                    'subnet_prefix' => $vpnConfig['subnet_prefix'],
                    'ip_range_start' => $vpnConfig['ip_range_start'],
                    'ip_range_end'   => $vpnConfig['ip_range_end'],
                ],
            ]);
            break;
        case 'create_user':
            $vpnConfig = loadVpnApiConfig($db);
            handleCreateUser($db, $vpnConfig, $payload, $currentUserId);
            break;
        case 'disable_user':
            $vpnConfig = loadVpnApiConfig($db);
            handleDisableUser($db, $vpnConfig, $payload, $currentUserId);
            break;
        case 'enable_user':
            $vpnConfig = loadVpnApiConfig($db);
            handleEnableUser($db, $vpnConfig, $payload, $currentUserId);
            break;
        case 'delete_user':
            $vpnConfig = loadVpnApiConfig($db);
            handleDeleteUser($db, $vpnConfig, $payload, $currentUserId);
            break;
        case 'disconnect_user':
            $vpnConfig = loadVpnApiConfig($db);
            handleDisconnectUser($db, $vpnConfig, $payload, $currentUserId);
            break;
        case 'create_port_forwarding':
            $vpnConfig = loadVpnApiConfig($db);
            handleCreatePortForwarding($db, $vpnConfig, $payload, $currentUserId);
            break;
        case 'delete_port_forwarding':
            $vpnConfig = loadVpnApiConfig($db);
            handleDeletePortForwarding($db, $vpnConfig, $payload, $currentUserId);
            break;
        case 'sync_server':
            $vpnConfig = loadVpnApiConfig($db);
            handleSyncServer($db, $vpnConfig, $currentUserId);
            break;
        case 'get_user_detail':
            handleGetUserDetail($db, $payload, $currentUserId);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action', 'code' => 400]);
    }
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage(), 'code' => 400]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'code' => 500]);
}

function ensureVpnUsersTable(PDO $db): void
{
    try {
        $db->exec('ALTER TABLE vpn_users ADD COLUMN owner_user_id BIGINT UNSIGNED NULL AFTER id');
    } catch (Throwable $e) {
        // Column may already exist.
    }
    try {
        $db->exec('ALTER TABLE vpn_users ADD COLUMN vpn_ip VARCHAR(45) NULL AFTER external_vpn_user_id');
    } catch (Throwable $e) {
        // Column may already exist.
    }
    try {
        $db->exec('ALTER TABLE vpn_users ADD COLUMN vpn_password VARCHAR(255) NULL AFTER vpn_ip');
    } catch (Throwable $e) {
        // Column may already exist.
    }

    $db->exec(
        "CREATE TABLE IF NOT EXISTS vpn_users (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            owner_user_id BIGINT UNSIGNED NULL,
            username VARCHAR(100) NOT NULL UNIQUE,
            full_name VARCHAR(120) NULL,
            phone_number VARCHAR(30) NULL,
            vpn_plan VARCHAR(80) NULL,
            vpn_status ENUM('active','suspended','expired') NOT NULL DEFAULT 'active',
            external_vpn_user_id VARCHAR(100) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_vpn_owner (owner_user_id)
        ) ENGINE=InnoDB"
    );

    try {
        $db->exec('CREATE INDEX idx_vpn_owner ON vpn_users (owner_user_id)');
    } catch (Throwable $e) {
        // Index may already exist.
    }
}

function ensureVpnPortForwardingsTable(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS vpn_port_forwardings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            owner_user_id BIGINT UNSIGNED NULL,
            name VARCHAR(100) NOT NULL UNIQUE,
            protocol VARCHAR(10) NOT NULL,
            listen_port INT UNSIGNED NOT NULL,
            destination_ip VARCHAR(45) NOT NULL,
            destination_port INT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_vpn_pf_owner (owner_user_id)
        ) ENGINE=InnoDB"
    );

    try {
        $db->exec('CREATE INDEX idx_vpn_pf_owner ON vpn_port_forwardings (owner_user_id)');
    } catch (Throwable $e) {
        // Index may already exist.
    }
}

function handleListUsers(PDO $db, int $actorUserId): void
{
    $isAdmin = isCurrentUserAdmin();

    $sql =
        'SELECT vu.id, vu.owner_user_id, vu.username, vu.vpn_status, vu.vpn_ip, vu.external_vpn_user_id, vu.created_at, vu.updated_at,
                u.full_name AS owner_name, u.email AS owner_email
         FROM vpn_users vu
         LEFT JOIN users u ON u.id = vu.owner_user_id';

    if (!$isAdmin) {
        $sql .= ' WHERE vu.owner_user_id = :owner_user_id';
    }

    $sql .= ' ORDER BY vu.created_at DESC, vu.id DESC';

    $stmt = $db->prepare($sql);
    if ($isAdmin) {
        $stmt->execute();
    } else {
        $stmt->execute(['owner_user_id' => $actorUserId]);
    }

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'owner_user_id' => (int) ($row['owner_user_id'] ?? 0),
            'owner_name' => (string) ($row['owner_name'] ?? ''),
            'owner_email' => (string) ($row['owner_email'] ?? ''),
            'username' => (string) ($row['username'] ?? ''),
            'vpn_status' => (string) ($row['vpn_status'] ?? 'active'),
            'vpn_ip' => (string) ($row['vpn_ip'] ?? ''),
            'external_vpn_user_id' => (string) ($row['external_vpn_user_id'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    echo json_encode([
        'status' => 'success',
        'code' => 200,
        'data' => [
            'items' => $rows,
            'count' => count($rows),
        ],
    ]);
}

function handleListPortForwardings(PDO $db, int $actorUserId): void
{
    $isAdmin = isCurrentUserAdmin();

    $sql =
        'SELECT pf.id, pf.owner_user_id, pf.name, pf.protocol, pf.listen_port, pf.destination_ip, pf.destination_port, pf.status, pf.created_at, pf.updated_at,
                u.full_name AS owner_name, u.email AS owner_email
         FROM vpn_port_forwardings pf
         LEFT JOIN users u ON u.id = pf.owner_user_id';

    if (!$isAdmin) {
        $sql .= ' WHERE pf.owner_user_id = :owner_user_id';
    }

    $sql .= ' ORDER BY pf.created_at DESC, pf.id DESC';

    $stmt = $db->prepare($sql);
    if ($isAdmin) {
        $stmt->execute();
    } else {
        $stmt->execute(['owner_user_id' => $actorUserId]);
    }

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'owner_user_id' => (int) ($row['owner_user_id'] ?? 0),
            'owner_name' => (string) ($row['owner_name'] ?? ''),
            'owner_email' => (string) ($row['owner_email'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'protocol' => (string) ($row['protocol'] ?? 'tcp'),
            'listen_port' => (int) ($row['listen_port'] ?? 0),
            'destination_ip' => (string) ($row['destination_ip'] ?? ''),
            'destination_port' => (int) ($row['destination_port'] ?? 0),
            'status' => (string) ($row['status'] ?? 'active'),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    echo json_encode([
        'status' => 'success',
        'code' => 200,
        'data' => [
            'items' => $rows,
            'count' => count($rows),
        ],
    ]);
}

function loadVpnApiConfig(PDO $db): array
{
    $stmt = $db->query(
        "SELECT base_url, api_key, api_secret, access_token, request_timeout_seconds
         FROM api_configurations
         WHERE service_type = 'vpn' AND is_enabled = 1
         ORDER BY updated_at DESC, id DESC
         LIMIT 1"
    );
    $row = $stmt->fetch();

    if (!is_array($row)) {
        throw new RuntimeException('VPN API belum dikonfigurasi atau belum diaktifkan di Admin Config.');
    }

    $settings = loadAppSettings($db, [
        'vpn.auth_scheme',
        'vpn.endpoint_users',
        'vpn.endpoint_port_forwardings',
        'vpn.subnet_prefix',
        'vpn.ip_range_start',
        'vpn.ip_range_end',
    ]);

    $authScheme = strtolower(trim((string) ($settings['vpn.auth_scheme'] ?? 'basic')));
    if (!in_array($authScheme, ['basic', 'bearer'], true)) {
        $authScheme = 'basic';
    }

    $endpointUsers = '/' . ltrim((string) ($settings['vpn.endpoint_users'] ?? '/users'), '/');
    $endpointPortForwardings = '/' . ltrim((string) ($settings['vpn.endpoint_port_forwardings'] ?? '/port-forwardings'), '/');

    $baseUrl = rtrim(trim((string) ($row['base_url'] ?? '')), '/');
    if ($baseUrl === '') {
        throw new RuntimeException('Base URL VPN API kosong. Silakan isi di Admin Config.');
    }

    return [
        'base_url' => $baseUrl,
        'auth_scheme' => $authScheme,
        'api_key' => trim((string) ($row['api_key'] ?? '')),
        'api_secret' => (string) ($row['api_secret'] ?? ''),
        'access_token' => trim((string) ($row['access_token'] ?? '')),
        'endpoint_users' => $endpointUsers,
        'endpoint_port_forwardings' => $endpointPortForwardings,
        'timeout' => max(3, (int) ($row['request_timeout_seconds'] ?? 30)),
        'subnet_prefix' => trim((string) ($settings['vpn.subnet_prefix'] ?? '192.168.12')),
        'ip_range_start' => max(1, (int) ($settings['vpn.ip_range_start'] ?? 2)),
        'ip_range_end' => min(254, (int) ($settings['vpn.ip_range_end'] ?? 254)),
    ];
}

function loadAppSettings(PDO $db, array $keys): array
{
    if (!$keys) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $db->prepare('SELECT config_key, config_value FROM app_settings WHERE config_key IN (' . $placeholders . ')');
    $stmt->execute(array_values($keys));

    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $key = (string) ($row['config_key'] ?? '');
        if ($key !== '') {
            $result[$key] = (string) ($row['config_value'] ?? '');
        }
    }

    return $result;
}

function handleCreateUser(PDO $db, array $vpnConfig, array $payload, int $actorUserId): void
{
    $username = normalizeVpnName((string) ($payload['username'] ?? ''));
    $password = (string) ($payload['password'] ?? '');

    if ($username === '' || $password === '') {
        throw new RuntimeException('username dan password wajib diisi.');
    }
    if (strlen($password) < 6) {
        throw new RuntimeException('Password VPN minimal 6 karakter.');
    }

    $subnetPrefix = rtrim(trim($vpnConfig['subnet_prefix'] ?? '192.168.12'), '.');
    $rangeStart   = (int) ($vpnConfig['ip_range_start'] ?? 2);
    $rangeEnd     = (int) ($vpnConfig['ip_range_end'] ?? 254);

    // Auto-assign: find next available IP in range
    $usedStmt = $db->query('SELECT vpn_ip FROM vpn_users WHERE vpn_ip IS NOT NULL');
    $usedIps = array_flip(array_filter(array_column($usedStmt->fetchAll(PDO::FETCH_ASSOC), 'vpn_ip')));
    $ip = null;
    for ($i = $rangeStart; $i <= $rangeEnd; $i++) {
        $candidate = $subnetPrefix . '.' . $i;
        if (!isset($usedIps[$candidate])) {
            $ip = $candidate;
            break;
        }
    }
    if ($ip === null) {
        throw new RuntimeException('Tidak ada IP yang tersedia dalam range ' . $subnetPrefix . '.' . $rangeStart . ' – ' . $subnetPrefix . '.' . $rangeEnd . '.');
    }

    $createPfWinbox = !empty($payload['create_pf_winbox']);
    $createPfApi = !empty($payload['create_pf_api']);
    $winboxDestPort = (int) ($payload['winbox_dest_port'] ?? 0);
    $apiDestPort = (int) ($payload['api_dest_port'] ?? 0);
    if ($winboxDestPort <= 0 || $winboxDestPort > 65535) {
        $winboxDestPort = 8291;
    }
    if ($apiDestPort <= 0 || $apiDestPort > 65535) {
        $apiDestPort = 8728;
    }

    $ipParts = explode('.', $ip);
    $lastOctet = (int) end($ipParts);
    if ($lastOctet <= 0 || $lastOctet > 254) {
        throw new RuntimeException('IP auto-assign tidak valid untuk perhitungan template port.');
    }
    $winboxListenPort = 1000 + $lastOctet;
    $apiListenPort = 2000 + $lastOctet;

    $res = vpnApiRequest($vpnConfig, 'POST', $vpnConfig['endpoint_users'], [
        'username' => $username,
        'password' => $password,
        'ip' => $ip,
    ]);

    if (!$res['ok']) {
        throw new RuntimeException('VPN API create user gagal: ' . $res['error']);
    }

    $stmt = $db->prepare(
        "INSERT INTO vpn_users (owner_user_id, username, vpn_status, external_vpn_user_id, vpn_ip, vpn_password, created_at, updated_at)
         VALUES (:owner_user_id, :username, 'active', :external_vpn_user_id, :vpn_ip, :vpn_password, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            owner_user_id = VALUES(owner_user_id),
            vpn_status = 'active',
            external_vpn_user_id = VALUES(external_vpn_user_id),
            vpn_ip = VALUES(vpn_ip),
            vpn_password = VALUES(vpn_password),
            updated_at = NOW()"
    );
    $stmt->execute([
        'owner_user_id' => $actorUserId > 0 ? $actorUserId : null,
        'username' => $username,
        'external_vpn_user_id' => $username,
        'vpn_ip' => $ip,
        'vpn_password' => $password,
    ]);

    $templatePfResults = [];
    $templatePfErrors = [];

    if ($createPfWinbox) {
        try {
            $templatePfResults[] = createPortForwardingRule(
                $db,
                $vpnConfig,
                $actorUserId,
                [
                    'name' => normalizeVpnName('mikrotik-winbox-' . $username),
                    'protocol' => 'tcp',
                    'listen_port' => $winboxListenPort,
                    'destination_ip' => $ip,
                    'destination_port' => $winboxDestPort,
                ]
            );
        } catch (Throwable $e) {
            $templatePfErrors[] = 'Template Winbox gagal dibuat: ' . $e->getMessage();
        }
    }

    if ($createPfApi) {
        try {
            $templatePfResults[] = createPortForwardingRule(
                $db,
                $vpnConfig,
                $actorUserId,
                [
                    'name' => normalizeVpnName('mikrotik-api-' . $username),
                    'protocol' => 'tcp',
                    'listen_port' => $apiListenPort,
                    'destination_ip' => $ip,
                    'destination_port' => $apiDestPort,
                ]
            );
        } catch (Throwable $e) {
            $templatePfErrors[] = 'Template API Mikrotik gagal dibuat: ' . $e->getMessage();
        }
    }

    $vpnServerHost = (string) (parse_url((string) ($vpnConfig['base_url'] ?? ''), PHP_URL_HOST) ?: 'YOUR_VPN_SERVER_HOST');
    $mikrotikScript = buildMikrotikInstallScript($vpnServerHost, $username, $password);

    writeVpnAuditLog($db, $actorUserId, 'vpn_user_create', 'vpn_user', $username, [
        'username' => $username,
        'vpn_ip' => $ip,
        'owner_user_id' => $actorUserId > 0 ? $actorUserId : null,
    ]);

    echo json_encode([
        'status' => 'success',
        'message' => $templatePfErrors
            ? ('VPN user berhasil dibuat, namun ada template port forwarding yang gagal: ' . implode(' | ', $templatePfErrors))
            : 'VPN user berhasil dibuat.',
        'code' => 200,
        'data' => [
            'username' => $username,
            'ip' => $ip,
            'port_forwardings' => $templatePfResults,
            'port_forwarding_errors' => $templatePfErrors,
            'mikrotik_install_script' => $mikrotikScript,
            'vpn_server_host' => $vpnServerHost,
            'upstream' => $res['data'],
        ],
    ]);
}

function handleGetUserDetail(PDO $db, array $payload, int $actorUserId): void
{
    $username = normalizeVpnName((string) ($payload['username'] ?? ''));
    if ($username === '') {
        throw new RuntimeException('username wajib diisi.');
    }

    assertVpnUserAccess($db, $username, $actorUserId);

    $stmt = $db->prepare(
        'SELECT vu.id, vu.owner_user_id, vu.username, vu.vpn_status, vu.vpn_ip, vu.vpn_password,
                vu.external_vpn_user_id, vu.created_at, vu.updated_at,
                u.full_name AS owner_name, u.email AS owner_email
         FROM vpn_users vu
         LEFT JOIN users u ON u.id = vu.owner_user_id
         WHERE vu.username = :username
         LIMIT 1'
    );
    $stmt->execute(['username' => $username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row)) {
        throw new RuntimeException('User VPN tidak ditemukan di database.');
    }

    echo json_encode([
        'status' => 'success',
        'code' => 200,
        'data' => [
            'id' => (int) ($row['id'] ?? 0),
            'username' => (string) ($row['username'] ?? ''),
            'vpn_status' => (string) ($row['vpn_status'] ?? 'active'),
            'vpn_ip' => (string) ($row['vpn_ip'] ?? ''),
            'vpn_password' => (string) ($row['vpn_password'] ?? ''),
            'external_vpn_user_id' => (string) ($row['external_vpn_user_id'] ?? ''),
            'owner_name' => (string) ($row['owner_name'] ?? ''),
            'owner_email' => (string) ($row['owner_email'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ],
    ]);
}

function handleDisableUser(PDO $db, array $vpnConfig, array $payload, int $actorUserId): void
{
    $username = normalizeVpnName((string) ($payload['username'] ?? ''));
    if ($username === '') {
        throw new RuntimeException('username wajib diisi.');
    }

    assertVpnUserAccess($db, $username, $actorUserId);

    $res = vpnApiRequest($vpnConfig, 'POST', $vpnConfig['endpoint_users'] . '/' . rawurlencode($username) . '/disable');
    if (!$res['ok']) {
        throw new RuntimeException('VPN API disable user gagal: ' . $res['error']);
    }

    $db->prepare("UPDATE vpn_users SET vpn_status = 'suspended', updated_at = NOW() WHERE username = :username")
        ->execute(['username' => $username]);

    writeVpnAuditLog($db, $actorUserId, 'vpn_user_disable', 'vpn_user', $username, [
        'username' => $username,
        'vpn_status' => 'suspended',
    ]);

    echo json_encode([
        'status' => 'success',
        'message' => 'VPN user berhasil dinonaktifkan.',
        'code' => 200,
        'data' => [
            'username' => $username,
            'upstream' => $res['data'],
        ],
    ]);
}

function handleEnableUser(PDO $db, array $vpnConfig, array $payload, int $actorUserId): void
{
    $username = normalizeVpnName((string) ($payload['username'] ?? ''));
    if ($username === '') {
        throw new RuntimeException('username wajib diisi.');
    }

    assertVpnUserAccess($db, $username, $actorUserId);

    $res = vpnApiRequest($vpnConfig, 'POST', $vpnConfig['endpoint_users'] . '/' . rawurlencode($username) . '/enable');
    if (!$res['ok']) {
        throw new RuntimeException('VPN API enable user gagal: ' . $res['error']);
    }

    $db->prepare("UPDATE vpn_users SET vpn_status = 'active', updated_at = NOW() WHERE username = :username")
        ->execute(['username' => $username]);

    writeVpnAuditLog($db, $actorUserId, 'vpn_user_enable', 'vpn_user', $username, [
        'username' => $username,
        'vpn_status' => 'active',
    ]);

    echo json_encode([
        'status' => 'success',
        'message' => 'VPN user berhasil diaktifkan kembali.',
        'code' => 200,
        'data' => [
            'username' => $username,
            'upstream' => $res['data'],
        ],
    ]);
}

function handleDeleteUser(PDO $db, array $vpnConfig, array $payload, int $actorUserId): void
{
    $username = normalizeVpnName((string) ($payload['username'] ?? ''));
    if ($username === '') {
        throw new RuntimeException('username wajib diisi.');
    }

    assertVpnUserAccess($db, $username, $actorUserId);

    $res = vpnApiRequest($vpnConfig, 'DELETE', $vpnConfig['endpoint_users'] . '/' . rawurlencode($username));
    if (!$res['ok']) {
        throw new RuntimeException('VPN API delete user gagal: ' . $res['error']);
    }

    $db->prepare('DELETE FROM vpn_users WHERE username = :username')->execute(['username' => $username]);

    writeVpnAuditLog($db, $actorUserId, 'vpn_user_delete', 'vpn_user', $username, [
        'username' => $username,
    ]);

    echo json_encode([
        'status' => 'success',
        'message' => 'VPN user berhasil dihapus.',
        'code' => 200,
        'data' => [
            'username' => $username,
            'upstream' => $res['data'],
        ],
    ]);
}

function handleDisconnectUser(PDO $db, array $vpnConfig, array $payload, int $actorUserId): void
{
    $username = normalizeVpnName((string) ($payload['username'] ?? ''));
    if ($username === '') {
        throw new RuntimeException('username wajib diisi.');
    }

    assertVpnUserAccess($db, $username, $actorUserId);

    $res = vpnApiRequest($vpnConfig, 'POST', $vpnConfig['endpoint_users'] . '/' . rawurlencode($username) . '/disconnect');
    if (!$res['ok']) {
        throw new RuntimeException('VPN API disconnect user gagal: ' . $res['error']);
    }

    writeVpnAuditLog($db, $actorUserId, 'vpn_user_disconnect', 'vpn_user', $username, [
        'username' => $username,
        'upstream_status' => (int) ($res['status'] ?? 0),
    ]);

    echo json_encode([
        'status' => 'success',
        'message' => 'Permintaan disconnect user berhasil dikirim (best effort).',
        'code' => 200,
        'data' => [
            'username' => $username,
            'upstream' => $res['data'],
        ],
    ]);
}

function handleCreatePortForwarding(PDO $db, array $vpnConfig, array $payload, int $actorUserId): void
{
    $created = createPortForwardingRule($db, $vpnConfig, $actorUserId, [
        'name' => (string) ($payload['name'] ?? ''),
        'protocol' => (string) ($payload['protocol'] ?? 'tcp'),
        'listen_port' => (int) ($payload['listen_port'] ?? 0),
        'destination_ip' => (string) ($payload['destination_ip'] ?? ''),
        'destination_port' => (int) ($payload['destination_port'] ?? 0),
    ]);

    echo json_encode([
        'status' => 'success',
        'message' => 'Port forwarding berhasil dibuat.',
        'code' => 200,
        'data' => [
            'name' => $created['name'],
            'upstream' => $created['upstream'],
        ],
    ]);
}

function createPortForwardingRule(PDO $db, array $vpnConfig, int $actorUserId, array $payload): array
{
    $name = normalizeVpnName((string) ($payload['name'] ?? ''));
    $protocol = strtolower(trim((string) ($payload['protocol'] ?? 'tcp')));
    $listenPort = (int) ($payload['listen_port'] ?? 0);
    $destinationIp = trim((string) ($payload['destination_ip'] ?? ''));
    $destinationPort = (int) ($payload['destination_port'] ?? 0);

    if ($name === '' || $destinationIp === '' || $listenPort <= 0 || $destinationPort <= 0) {
        throw new RuntimeException('name, protocol, listen_port, destination_ip, destination_port wajib diisi.');
    }
    if (!in_array($protocol, ['tcp', 'udp'], true)) {
        throw new RuntimeException('Protocol hanya boleh tcp atau udp.');
    }
    if (!filter_var($destinationIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        throw new RuntimeException('destination_ip tidak valid (wajib IPv4).');
    }
    if ($listenPort > 65535 || $destinationPort > 65535) {
        throw new RuntimeException('Port harus berada di rentang 1-65535.');
    }

    $res = vpnApiRequest($vpnConfig, 'POST', $vpnConfig['endpoint_port_forwardings'], [
        'name' => $name,
        'protocol' => $protocol,
        'listen_port' => $listenPort,
        'destination_ip' => $destinationIp,
        'destination_port' => $destinationPort,
    ]);
    if (!$res['ok']) {
        throw new RuntimeException('VPN API create port forwarding gagal: ' . $res['error']);
    }

    $stmt = $db->prepare(
        "INSERT INTO vpn_port_forwardings
            (owner_user_id, name, protocol, listen_port, destination_ip, destination_port, status, created_at, updated_at)
         VALUES
            (:owner_user_id, :name, :protocol, :listen_port, :destination_ip, :destination_port, 'active', NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            owner_user_id = VALUES(owner_user_id),
            protocol = VALUES(protocol),
            listen_port = VALUES(listen_port),
            destination_ip = VALUES(destination_ip),
            destination_port = VALUES(destination_port),
            status = 'active',
            updated_at = NOW()"
    );
    $stmt->execute([
        'owner_user_id' => $actorUserId > 0 ? $actorUserId : null,
        'name' => $name,
        'protocol' => $protocol,
        'listen_port' => $listenPort,
        'destination_ip' => $destinationIp,
        'destination_port' => $destinationPort,
    ]);

    writeVpnAuditLog($db, $actorUserId, 'vpn_pf_create', 'vpn_port_forwarding', $name, [
        'name' => $name,
        'protocol' => $protocol,
        'listen_port' => $listenPort,
        'destination_ip' => $destinationIp,
        'destination_port' => $destinationPort,
        'owner_user_id' => $actorUserId > 0 ? $actorUserId : null,
    ]);

    return [
        'name' => $name,
        'protocol' => $protocol,
        'listen_port' => $listenPort,
        'destination_ip' => $destinationIp,
        'destination_port' => $destinationPort,
        'upstream' => $res['data'],
    ];
}

function buildMikrotikInstallScript(string $vpnServerHost, string $username, string $password): string
{
    $safeServer = str_replace('"', '', trim($vpnServerHost));
    $safeUser = str_replace('"', '', trim($username));
    $safePass = str_replace('"', '', trim($password));

    return "/interface l2tp-client remove [find where name=\"VPN-REMOTE\"]\n"
        . "/interface l2tp-client add name=\"VPN-REMOTE\" connect-to=\"{$safeServer}\" user=\"{$safeUser}\" password=\"{$safePass}\" use-ipsec=no disabled=no profile=default-encryption\n"
        . "/interface l2tp-client enable [find where name=\"VPN-REMOTE\"]\n"
        . "/interface l2tp-client print where name=\"VPN-REMOTE\"";
}

function handleDeletePortForwarding(PDO $db, array $vpnConfig, array $payload, int $actorUserId): void
{
    $name = normalizeVpnName((string) ($payload['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('name wajib diisi.');
    }

    assertPortForwardingAccess($db, $name, $actorUserId);

    $res = vpnApiRequest($vpnConfig, 'DELETE', $vpnConfig['endpoint_port_forwardings'] . '/' . rawurlencode($name));
    if (!$res['ok']) {
        throw new RuntimeException('VPN API delete port forwarding gagal: ' . $res['error']);
    }

    $db->prepare('DELETE FROM vpn_port_forwardings WHERE name = :name')->execute(['name' => $name]);

    writeVpnAuditLog($db, $actorUserId, 'vpn_pf_delete', 'vpn_port_forwarding', $name, [
        'name' => $name,
    ]);

    echo json_encode([
        'status' => 'success',
        'message' => 'Port forwarding berhasil dihapus.',
        'code' => 200,
        'data' => [
            'name' => $name,
            'upstream' => $res['data'],
        ],
    ]);
}

function assertVpnUserAccess(PDO $db, string $username, int $actorUserId): void
{
    $isAdmin = isCurrentUserAdmin();
    if ($isAdmin) {
        return;
    }

    $stmt = $db->prepare('SELECT owner_user_id FROM vpn_users WHERE username = :username LIMIT 1');
    $stmt->execute(['username' => $username]);
    $ownerRaw = $stmt->fetchColumn();
    if ($ownerRaw === false) {
        throw new RuntimeException('User VPN tidak ditemukan.');
    }

    $owner = (int) $ownerRaw;
    if ($owner <= 0 || $owner !== $actorUserId) {
        throw new RuntimeException('Anda tidak memiliki akses ke user VPN tersebut.');
    }
}

function assertPortForwardingAccess(PDO $db, string $name, int $actorUserId): void
{
    $isAdmin = isCurrentUserAdmin();
    if ($isAdmin) {
        return;
    }

    $stmt = $db->prepare('SELECT owner_user_id FROM vpn_port_forwardings WHERE name = :name LIMIT 1');
    $stmt->execute(['name' => $name]);
    $ownerRaw = $stmt->fetchColumn();
    if ($ownerRaw === false) {
        throw new RuntimeException('Port forwarding tidak ditemukan.');
    }

    $owner = (int) $ownerRaw;
    if ($owner <= 0 || $owner !== $actorUserId) {
        throw new RuntimeException('Anda tidak memiliki akses ke port forwarding tersebut.');
    }
}

function normalizeVpnName(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/\s+/', '-', $name);
    $name = preg_replace('/[^a-z0-9._-]/', '', $name);
    return substr((string) $name, 0, 100);
}

function writeVpnAuditLog(PDO $db, int $actorUserId, string $action, string $targetType, string $targetId, array $detail = []): void
{
    try {
        $stmt = $db->prepare(
            'INSERT INTO audit_logs (actor_user_id, action, target_type, target_id, detail, created_at)
             VALUES (:actor_user_id, :action, :target_type, :target_id, :detail, NOW())'
        );

        $stmt->execute([
            'actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'detail' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable $e) {
        // Do not break main business flow when audit logging fails.
    }
}

function isCurrentUserAdmin(): bool
{
    $roles = $_SESSION['roles'] ?? [];
    return in_array('admin', $roles, true) || in_array('super_admin', $roles, true);
}

function vpnApiRequest(array $cfg, string $method, string $path, ?array $body = null): array
{
    $url = rtrim((string) $cfg['base_url'], '/') . '/' . ltrim($path, '/');
    $headers = ['Accept: application/json'];
    $methodUpper = strtoupper($method);
    $payload = null;
    if ($body !== null) {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new RuntimeException('Gagal encode payload JSON.');
        }
        $headers[] = 'Content-Type: application/json';
    }
    if (($cfg['auth_scheme'] ?? 'basic') === 'basic') {
        $apiKey = (string) ($cfg['api_key'] ?? '');
        $apiSecret = (string) ($cfg['api_secret'] ?? '');
        if ($apiKey === '' || $apiSecret === '') {
            throw new RuntimeException('Kredensial Basic Auth VPN belum lengkap.');
        }
    } else {
        $token = (string) ($cfg['access_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('Access token VPN belum diisi untuk skema bearer.');
        }
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $raw = false;
    $httpStatus = 0;
    $lastError = '';
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Gagal inisialisasi cURL.');
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $methodUpper);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        if (($cfg['auth_scheme'] ?? 'basic') === 'basic') {
            $apiKey = (string) ($cfg['api_key'] ?? '');
            $apiSecret = (string) ($cfg['api_secret'] ?? '');
            curl_setopt($ch, CURLOPT_USERPWD, $apiKey . ':' . $apiSecret);
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) ($cfg['timeout'] ?? 30),
            CURLOPT_CONNECTTIMEOUT => min(10, (int) ($cfg['timeout'] ?? 30)),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $raw = curl_exec($ch);
        if ($raw !== false) {
            $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            break;
        }

        $lastError = curl_error($ch);
        curl_close($ch);
    }

    if ($raw === false) {
        return [
            'ok' => false,
            'status' => 0,
            'error' => 'cURL error: ' . ($lastError !== '' ? $lastError : 'unknown') . ' [URL: ' . $url . ']',
            'data' => null,
        ];
    }

    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        $data = ['raw' => (string) $raw];
    }

    $ok = $httpStatus >= 200 && $httpStatus < 300;
    $error = '';
    if (!$ok) {
        $error = (string) ($data['detail'] ?? $data['message'] ?? $data['error'] ?? ('HTTP ' . $httpStatus));
    }

    return [
        'ok' => $ok,
        'status' => $httpStatus,
        'error' => $error,
        'data' => $data,
    ];
}

/**
 * Sync: fetch users + port forwardings from the upstream VPN server and
 * upsert them into the local DB.  Admin-only action.
 */
function handleSyncServer(PDO $db, array $vpnConfig, int $actorUserId): void
{
    if (!isCurrentUserAdmin()) {
        throw new RuntimeException('Hanya admin yang dapat melakukan sync server.');
    }

    $usersAdded = 0;
    $usersUpdated = 0;
    $pfAdded = 0;
    $pfUpdated = 0;

    // --- Sync users ---
    $usersRes = vpnApiRequest($vpnConfig, 'GET', $vpnConfig['endpoint_users']);
    if ($usersRes['ok']) {
        $serverUsers = [];
        $responseData = $usersRes['data'] ?? [];

        // Support both list at root or nested under 'users'/'data'/'items'
        if (isset($responseData['users']) && is_array($responseData['users'])) {
            $serverUsers = $responseData['users'];
        } elseif (isset($responseData['items']) && is_array($responseData['items'])) {
            $serverUsers = $responseData['items'];
        } elseif (isset($responseData['data']) && is_array($responseData['data'])) {
            $serverUsers = $responseData['data'];
        } elseif (array_is_list($responseData)) {
            $serverUsers = $responseData;
        }

        $upsert = $db->prepare(
            "INSERT INTO vpn_users
                (username, vpn_status, external_vpn_user_id, created_at, updated_at)
             VALUES
                (:username, :vpn_status, :external_vpn_user_id, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                vpn_status = VALUES(vpn_status),
                external_vpn_user_id = VALUES(external_vpn_user_id),
                updated_at = NOW()"
        );

        foreach ($serverUsers as $u) {
            if (!is_array($u)) continue;
            $uname = (string) ($u['username'] ?? $u['name'] ?? '');
            if ($uname === '') continue;
            $status = (string) ($u['status'] ?? $u['vpn_status'] ?? 'active');
            $upsert->execute([
                'username' => $uname,
                'vpn_status' => in_array($status, ['active', 'suspended', 'expired'], true) ? $status : 'active',
                'external_vpn_user_id' => $uname,
            ]);
            if ($upsert->rowCount() === 1) {
                $usersAdded++;
            } else {
                $usersUpdated++;
            }
        }
    }

    // --- Sync port forwardings ---
    $pfRes = vpnApiRequest($vpnConfig, 'GET', $vpnConfig['endpoint_port_forwardings']);
    if ($pfRes['ok']) {
        $serverPfs = [];
        $pfData = $pfRes['data'] ?? [];

        if (isset($pfData['port_forwardings']) && is_array($pfData['port_forwardings'])) {
            $serverPfs = $pfData['port_forwardings'];
        } elseif (isset($pfData['items']) && is_array($pfData['items'])) {
            $serverPfs = $pfData['items'];
        } elseif (isset($pfData['data']) && is_array($pfData['data'])) {
            $serverPfs = $pfData['data'];
        } elseif (array_is_list($pfData)) {
            $serverPfs = $pfData;
        }

        $pfUpsert = $db->prepare(
            "INSERT INTO vpn_port_forwardings
                (name, protocol, listen_port, destination_ip, destination_port, status, created_at, updated_at)
             VALUES
                (:name, :protocol, :listen_port, :destination_ip, :destination_port, 'active', NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                protocol = VALUES(protocol),
                listen_port = VALUES(listen_port),
                destination_ip = VALUES(destination_ip),
                destination_port = VALUES(destination_port),
                status = 'active',
                updated_at = NOW()"
        );

        foreach ($serverPfs as $pf) {
            if (!is_array($pf)) continue;
            $name = (string) ($pf['name'] ?? '');
            if ($name === '') continue;
            $pfUpsert->execute([
                'name' => $name,
                'protocol' => strtolower((string) ($pf['protocol'] ?? 'tcp')),
                'listen_port' => (int) ($pf['listen_port'] ?? $pf['listen'] ?? 0),
                'destination_ip' => (string) ($pf['destination_ip'] ?? $pf['dest_ip'] ?? ''),
                'destination_port' => (int) ($pf['destination_port'] ?? $pf['dest_port'] ?? 0),
            ]);
            if ($pfUpsert->rowCount() === 1) {
                $pfAdded++;
            } else {
                $pfUpdated++;
            }
        }
    }

    $msg = sprintf(
        'Sync selesai. Users: %d ditambahkan, %d diperbarui. Port forwardings: %d ditambahkan, %d diperbarui.',
        $usersAdded, $usersUpdated, $pfAdded, $pfUpdated
    );

    writeVpnAuditLog($db, $actorUserId, 'vpn_sync_server', 'vpn_sync', 'all', [
        'users_added' => $usersAdded,
        'users_updated' => $usersUpdated,
        'pf_added' => $pfAdded,
        'pf_updated' => $pfUpdated,
    ]);

    echo json_encode([
        'status' => 'success',
        'message' => $msg,
        'code' => 200,
        'data' => [
            'users_added' => $usersAdded,
            'users_updated' => $usersUpdated,
            'pf_added' => $pfAdded,
            'pf_updated' => $pfUpdated,
        ],
    ]);
}
