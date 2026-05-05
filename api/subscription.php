<?php
/**
 * Subscription API
 *
 * GET  ?action=info         → info subscription + usage aktif user
 * GET  ?action=plans        → list semua plan tersedia
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/../system/services/SubscriptionService.php';
require_once __DIR__ . '/../system/models/Plan.php';

secureSessionStart();
setCorsHeaders(false);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$action = trim($_GET['action'] ?? 'info');

try {
    $db = getDB();

    if ($action === 'info') {
        $service = new SubscriptionService($db);
        $info    = $service->getInfo($userId);
        echo json_encode(['ok' => true, 'data' => $info]);
        exit;
    }

    if ($action === 'plans') {
        $planModel = new Plan($db);
        $plans     = $planModel->getAll();
        echo json_encode(['ok' => true, 'data' => $plans]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
