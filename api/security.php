<?php
/**
 * Security helpers:
 * - Session hardening (httponly, secure, samesite)
 * - Login rate limiting (5 attempts → 15-min lockout via DB table)
 * - CORS restriction to APP_URL origin
 */

require_once __DIR__ . '/config.php';

// ── Session Hardening ─────────────────────────────────────────────────────────
function secureSessionStart(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;

    // Lax keeps session on top-level return navigation from payment gateways.
    $sameSite = 'Lax';

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => $sameSite,
    ]);

    ini_set('session.use_strict_mode',   '1');
    ini_set('session.use_only_cookies',  '1');
    ini_set('session.cookie_httponly',   '1');
    ini_set('session.cookie_samesite',   $sameSite);

    session_start();
}

// ── CORS Helper ───────────────────────────────────────────────────────────────
/**
 * Send CORS headers restricted to the configured APP_URL origin.
 * For wa-send.php (public API), pass $public = true to allow any origin.
 */
function setCorsHeaders(bool $public = false): void
{
    if ($public) {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-WA-Secret');
        return;
    }

    $appUrl = defined('APP_URL') ? APP_URL : '';
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');

    // Allow same-origin requests (no Origin header) and configured APP_URL
    $allowedOrigins = array_filter([$appUrl]);
    if ($origin === '' || in_array($origin, $allowedOrigins, true)) {
        if ($origin !== '') {
            header('Access-Control-Allow-Origin: ' . $origin);
        }
        header('Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    } else {
        // Unknown origin — do not echo origin back, send nothing
        header('Access-Control-Allow-Origin: null');
    }
}

// ── Login Rate Limiting ───────────────────────────────────────────────────────
const RATE_MAX_ATTEMPTS  = 5;
const RATE_WINDOW_SECS   = 900; // 15 minutes

/**
 * Ensure the login_attempts table exists.
 */
function ensureRateLimitTable(PDO $db): void
{
    $db->exec(
        'CREATE TABLE IF NOT EXISTS login_attempts (
            id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            identifier VARCHAR(255) NOT NULL COMMENT "email or IP",
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_rl_id_time (identifier, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

/**
 * Returns true if the identifier is currently locked out.
 */
function isRateLimited(PDO $db, string $identifier): bool
{
    ensureRateLimitTable($db);
    $since = date('Y-m-d H:i:s', time() - RATE_WINDOW_SECS);
    $stmt  = $db->prepare(
        'SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND attempted_at >= ?'
    );
    $stmt->execute([$identifier, $since]);
    return (int) $stmt->fetchColumn() >= RATE_MAX_ATTEMPTS;
}

/**
 * Record one failed attempt for an identifier.
 */
function recordFailedAttempt(PDO $db, string $identifier): void
{
    ensureRateLimitTable($db);
    $stmt = $db->prepare('INSERT INTO login_attempts (identifier, attempted_at) VALUES (?, NOW())');
    $stmt->execute([$identifier]);
    // Purge old records to keep the table small
    $purge = $db->prepare('DELETE FROM login_attempts WHERE attempted_at < ?');
    $purge->execute([date('Y-m-d H:i:s', time() - RATE_WINDOW_SECS * 4)]);
}

/**
 * Clear attempts after successful login.
 */
function clearLoginAttempts(PDO $db, string $identifier): void
{
    ensureRateLimitTable($db);
    $stmt = $db->prepare('DELETE FROM login_attempts WHERE identifier = ?');
    $stmt->execute([$identifier]);
}

/**
 * Get the client IP safely.
 */
function clientIp(): string
{
    // Prefer REMOTE_ADDR — do not trust X-Forwarded-For without a trusted proxy list
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}
