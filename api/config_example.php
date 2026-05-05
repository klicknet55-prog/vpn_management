<?php
/**
 * Application Configuration Example (Safe for GitHub)
 * -----------------------------------------------------------------------------
 * This file is a public-safe template. Do not put real secrets here.
 * Real credentials should be stored in the .env file at the project root.
 */

require_once __DIR__ . '/env.php';
loadEnv();

// -- Database -----------------------------------------------------------------
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', 'vpn_wa_manager'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));

// -- GoWA Server ---------------------------------------------------------------
define('GOWA_BASE_URL', env('GOWA_BASE_URL', 'https://example-gowa.domain.tld'));
define('GOWA_USERNAME', env('GOWA_USERNAME', 'your-gowa-username'));
define('GOWA_PASSWORD', env('GOWA_PASSWORD', 'your-gowa-password'));
define('GOWA_ADMIN_SEND_DEVICE_ID', env('GOWA_ADMIN_SEND_DEVICE_ID', 'optional-device-id'));

// -- App URL ------------------------------------------------------------------
define('APP_URL', rtrim(env('APP_URL', 'http://localhost/templatemo'), '/'));

// -- Security -----------------------------------------------------------------
define('SECRET_BYTES', (int) env('SECRET_BYTES', 16));

// -- iPaymu Payment Gateway ---------------------------------------------------
define('IPAYMU_VA',       env('IPAYMU_VA',       'your-virtual-account-number'));
define('IPAYMU_API_KEY',  env('IPAYMU_API_KEY',   'your-ipaymu-api-key'));
define('IPAYMU_BASE_URL', env('IPAYMU_BASE_URL',  'https://my.ipaymu.com/api/v2'));
define('IPAYMU_SANDBOX',  env('IPAYMU_SANDBOX',   '0') === '1');

// -- WA Send Runtime -----------------------------------------------------------
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

// -- Email Notifications -------------------------------------------------------
// Uses PHP mail() by default. Set MAIL_FROM and MAIL_FROM_NAME for sender identity.
// For SMTP delivery, integrate PHPMailer or SwiftMailer using these values.
define('MAIL_FROM',      env('MAIL_FROM',      'noreply@yourdomain.com'));
define('MAIL_FROM_NAME', env('MAIL_FROM_NAME', 'VPN & WA Manager'));
define('APP_NAME',       env('APP_NAME',       'VPN & WA Manager'));

// -- Cron Secret ---------------------------------------------------------------
// Required when running cron jobs via HTTP (not CLI).
// CRON_SECRET=<random-strong-secret>
// Already loaded via env() in each cron file.
