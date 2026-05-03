<?php
/**
 * Application Configuration
 * ─────────────────────────────────────────────────────────────────────────────
 * Credentials are loaded from the .env file at the project root.
 * DO NOT hardcode secrets here. DO NOT commit .env to version control.
 * ─────────────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/env.php';
loadEnv();

// ── Database ──────────────────────────────────────────────────────────────────
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', 'vpn_wa_manager'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));

// ── GoWA Server ───────────────────────────────────────────────────────────────
define('GOWA_BASE_URL',            env('GOWA_BASE_URL',            ''));
define('GOWA_USERNAME',            env('GOWA_USERNAME',            ''));
define('GOWA_PASSWORD',            env('GOWA_PASSWORD',            ''));
define('GOWA_ADMIN_SEND_DEVICE_ID', env('GOWA_ADMIN_SEND_DEVICE_ID', ''));

// ── App URL ───────────────────────────────────────────────────────────────────
define('APP_URL', rtrim(env('APP_URL', 'http://localhost/templatemo'), '/'));

// ── Security ──────────────────────────────────────────────────────────────────
define('SECRET_BYTES', (int) env('SECRET_BYTES', 16));

// ── WA Send Runtime ───────────────────────────────────────────────────────────
define('WA_SEND_DELAY_MIN_MS', (int) env('WA_SEND_DELAY_MIN_MS', 1000));
define('WA_SEND_DELAY_MAX_MS', (int) env('WA_SEND_DELAY_MAX_MS', 5000));
define('WA_SEND_BURST_LIMIT', (int) env('WA_SEND_BURST_LIMIT', 5));
define('WA_SEND_BURST_WINDOW_SECONDS', (int) env('WA_SEND_BURST_WINDOW_SECONDS', 60));
define('WA_SEND_BURST_PAUSE_MIN_MS', (int) env('WA_SEND_BURST_PAUSE_MIN_MS', 25000));
define('WA_SEND_BURST_PAUSE_MAX_MS', (int) env('WA_SEND_BURST_PAUSE_MAX_MS', 45000));
define('WA_ADMIN_QUEUE_ENABLED', env('WA_ADMIN_QUEUE_ENABLED', '0'));
define('WA_USER_QUEUE_ENABLED', env('WA_USER_QUEUE_ENABLED', '0'));
define('WA_QUEUE_BATCH_LIMIT', (int) env('WA_QUEUE_BATCH_LIMIT', 20));
define('WA_QUEUE_MAX_ATTEMPTS', (int) env('WA_QUEUE_MAX_ATTEMPTS', 5));
