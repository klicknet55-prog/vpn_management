<?php
/**
 * API: Admin Subscription Management
 * ─────────────────────────────────────────────────────────────────────────────
 * Semua aksi membutuhkan sesi admin aktif.
 *
 * GET  ?action=list              → daftar semua user subscription
 * GET  ?action=plans             → daftar semua plan
 * POST action=manual_activate    → aktifkan / ubah plan user secara manual
 * POST action=manual_disable     → nonaktifkan subscription user
 * ─────────────────────────────────────────────────────────────────────────────
 */
session_start();

header('Content-Type: application/json; charset=utf-8');

// ── Auth guard ─────────────────────────────────────────────────────────────────
if (empty($_SESSION['logged_in'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthenticated']);
    exit;
}
$roles   = $_SESSION['roles'] ?? [];
$isAdmin = in_array('admin', $roles, true) || in_array('super_admin', $roles, true);
if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Forbidden']);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../system/models/Plan.php';
require_once __DIR__ . '/../system/models/UserSubscription.php';
require_once __DIR__ . '/../system/services/SubscriptionService.php';

$db         = getDB();
$actorId    = (int) ($_SESSION['user_id'] ?? 0);
$method     = $_SERVER['REQUEST_METHOD'];
$action     = trim($_GET['action'] ?? $_POST['action'] ?? '');

function ok(mixed $data = null): never
{
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

function fail(string $msg, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg]);
    exit;
}

function writeAdminSubAudit(PDO $db, int $actorId, int $targetUserId, string $action, array $detail): void
{
    try {
        $db->prepare(
            'INSERT INTO audit_logs (actor_user_id, action, target_type, target_id, detail, created_at)
             VALUES (:actor, :action, "user_subscription", :target, :detail, NOW())'
        )->execute([
            'actor'  => $actorId,
            'action' => $action,
            'target' => $targetUserId,
            'detail' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable) { /* ignore */ }
}

// ── Router ─────────────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'list') {
    $rows = $db->query(
        "SELECT
            u.id           AS user_id,
            u.full_name,
            u.email,
            p.label        AS plan_label,
            p.name         AS plan_name,
            us.is_active,
            us.started_at,
            us.expires_at,
            us.plan_id
         FROM users u
         LEFT JOIN user_subscriptions us ON us.user_id = u.id
         LEFT JOIN plans  p  ON p.id = us.plan_id
         WHERE u.id NOT IN (
            SELECT DISTINCT ur.user_id 
            FROM user_roles ur
            JOIN roles r ON r.id = ur.role_id
            WHERE r.name IN ('Admin', 'Super Admin')
         )
         ORDER BY u.id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    ok($rows);
}

if ($method === 'GET' && $action === 'plans') {
    $plan    = new Plan($db);
    $allPlans = $plan->getAll();
    ok($allPlans);
}

if ($method === 'POST' && $action === 'manual_activate') {
    $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $targetUserId = (int) ($body['user_id'] ?? 0);
    $planId       = (int) ($body['plan_id'] ?? 0);
    $durationDays = (int) ($body['duration_days'] ?? 30);

    if ($targetUserId <= 0) fail('user_id wajib diisi');
    if ($planId <= 0)       fail('plan_id wajib diisi');
    if ($durationDays <= 0) fail('duration_days harus > 0');

    try {
        $service = new SubscriptionService($db);
        $result = $service->activate($targetUserId, $planId, $durationDays);
        if (!$result) fail('Gagal aktivasi subscription (plan tidak ditemukan)');
    } catch (Throwable $e) {
        fail('Gagal aktivasi subscription: ' . $e->getMessage(), 400);
    }

    try {
        writeAdminSubAudit($db, $actorId, $targetUserId, 'admin_manual_activate', [
            'plan_id'       => $planId,
            'duration_days' => $durationDays,
        ]);
    } catch (Throwable) { /* ignore audit error */ }

    ok(['user_id' => $targetUserId, 'plan_id' => $planId, 'duration_days' => $durationDays]);
}

if ($method === 'POST' && $action === 'manual_disable') {
    $body         = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $targetUserId = (int) ($body['user_id'] ?? 0);

    if ($targetUserId <= 0) fail('user_id wajib diisi');

    $model = new UserSubscription($db);
    $model->disable($targetUserId);

    writeAdminSubAudit($db, $actorId, $targetUserId, 'admin_manual_disable', []);

    ok(['user_id' => $targetUserId]);
}

fail('Aksi tidak dikenal', 404);
