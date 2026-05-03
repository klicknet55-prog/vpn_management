-- =====================================================
-- VPN & WhatsApp API Manager - Initial Database Schema
-- Target: MySQL / MariaDB (XAMPP)
-- =====================================================

CREATE DATABASE IF NOT EXISTS vpn_wa_manager
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE vpn_wa_manager;

-- =========================
-- Master: Roles
-- =========================
CREATE TABLE IF NOT EXISTS roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(50) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =========================
-- Master: Users
-- =========================
CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  phone_number VARCHAR(30) NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_roles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  UNIQUE KEY uq_user_role (user_id, role_id)
) ENGINE=InnoDB;

-- =========================
-- App Settings (SEO / Web Page)
-- =========================
CREATE TABLE IF NOT EXISTS app_settings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  config_key VARCHAR(100) NOT NULL UNIQUE,
  config_value LONGTEXT NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_app_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =========================
-- API Configurations
-- =========================
CREATE TABLE IF NOT EXISTS api_configurations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service_type ENUM('vpn','whatsapp') NOT NULL,
  provider_name VARCHAR(100) NOT NULL,
  base_url VARCHAR(255) NOT NULL,
  api_key VARCHAR(255) NULL,
  api_secret VARCHAR(255) NULL,
  access_token TEXT NULL,
  webhook_url VARCHAR(255) NULL,
  verify_token VARCHAR(255) NULL,
  admin_send_device_id VARCHAR(100) NULL,
  request_timeout_seconds INT UNSIGNED NOT NULL DEFAULT 30,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_api_config_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =========================
-- VPN Managed Users
-- =========================
CREATE TABLE IF NOT EXISTS vpn_users (
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
) ENGINE=InnoDB;

-- =========================
-- VPN Port Forwardings (NAT)
-- =========================
CREATE TABLE IF NOT EXISTS vpn_port_forwardings (
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
) ENGINE=InnoDB;

-- =========================
-- WhatsApp Templates
-- =========================
CREATE TABLE IF NOT EXISTS whatsapp_templates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_name VARCHAR(120) NOT NULL,
  language_code VARCHAR(10) NOT NULL DEFAULT 'id',
  category VARCHAR(50) NOT NULL DEFAULT 'utility',
  body_text TEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_wa_template_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_template_name_lang (template_name, language_code)
) ENGINE=InnoDB;

-- =========================
-- Audit Logs
-- =========================
CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_user_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  target_type VARCHAR(80) NULL,
  target_id VARCHAR(80) NULL,
  detail JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_actor_user FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_audit_action_time (action, created_at)
) ENGINE=InnoDB;

-- =========================
-- User OTP Codes
-- =========================
CREATE TABLE IF NOT EXISTS user_otp_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  purpose VARCHAR(40) NOT NULL COMMENT 'Contoh: register',
  otp_hash VARCHAR(255) NOT NULL,
  phone_number VARCHAR(30) NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  used_reason VARCHAR(40) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_otp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_otp_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_user_otp_lookup (user_id, purpose, used_at, expires_at),
  INDEX idx_user_otp_created_at (created_at)
) ENGINE=InnoDB;

-- =========================
-- WA Message Queue
-- =========================
CREATE TABLE IF NOT EXISTS wa_message_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(100) NOT NULL,
  phone_number VARCHAR(30) NOT NULL,
  message_text TEXT NOT NULL,
  source VARCHAR(40) NOT NULL DEFAULT 'user_api',
  queue_scope VARCHAR(20) NOT NULL DEFAULT 'user',
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_at DATETIME NULL,
  sent_at DATETIME NULL,
  worker_token VARCHAR(64) NULL,
  last_error TEXT NULL,
  payload JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_wa_queue_status_available (status, available_at, id),
  INDEX idx_wa_queue_worker (worker_token),
  INDEX idx_wa_queue_device_created (device_id, created_at)
) ENGINE=InnoDB;

-- =========================
-- WA Accounts (Device Isolation)
-- Setiap baris = 1 device GoWA dengan database tersendiri
-- =========================
CREATE TABLE IF NOT EXISTS wa_accounts (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_user_id     BIGINT UNSIGNED NOT NULL,
  device_id         VARCHAR(100) NOT NULL COMMENT 'ID device dari server GoWA',
  label             VARCHAR(100) NOT NULL COMMENT 'Nama / label device',
  phone_jid         VARCHAR(50)  NULL COMMENT 'Nomor WA format JID setelah terhubung',
  status            ENUM('pending','qr_ready','connected','disconnected','error') NOT NULL DEFAULT 'pending',
  db_name           VARCHAR(100) NULL    COMMENT 'Nama database isolasi di server GoWA',
  secret            VARCHAR(64)  NOT NULL UNIQUE COMMENT 'API secret key untuk endpoint wa-send.php',
  webhook_url       VARCHAR(512) NULL,
  qr_code           TEXT         NULL COMMENT 'Base64 / URL QR terakhir',
  qr_fetched_at     TIMESTAMP    NULL,
  connected_at      TIMESTAMP    NULL,
  disconnected_at   TIMESTAMP    NULL,
  last_error        TEXT         NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_wa_accounts_user FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_device_id (device_id),
  INDEX idx_wa_owner (owner_user_id),
  INDEX idx_wa_status (status)
) ENGINE=InnoDB COMMENT='GoWA device registry — data isolation per device';

-- =========================
-- Login Rate Limiting
-- =========================
CREATE TABLE IF NOT EXISTS login_attempts (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  identifier   VARCHAR(255) NOT NULL COMMENT 'email|ip composite key',
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_rl_id_time (identifier, attempted_at)
) ENGINE=InnoDB;

-- =========================
-- Seed Data
-- =========================
INSERT INTO roles (code, name, description)
VALUES
  ('admin', 'Admin', 'Akses penuh semua modul'),
  ('user', 'User', 'Kelola data VPN dan WA milik sendiri')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

-- Akun Admin Default:
-- 1) admin / admin
-- 2) admin@local / admin
-- Password hash: $2y$10$Nkrftu97670PgZvzzAkhmOx6NuPY9e0sZhIMrvL4FvZLTSJrCXrWW
-- Hash untuk password "admin" menggunakan bcrypt (cost 10)
INSERT INTO users (full_name, email, password_hash, is_active)
VALUES
  ('Admin User', 'admin', '$2y$10$Nkrftu97670PgZvzzAkhmOx6NuPY9e0sZhIMrvL4FvZLTSJrCXrWW', 1),
  ('Administrator', 'admin@local', '$2y$10$Nkrftu97670PgZvzzAkhmOx6NuPY9e0sZhIMrvL4FvZLTSJrCXrWW', 1)
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), is_active = VALUES(is_active);

INSERT INTO user_roles (user_id, role_id)
SELECT u.id, r.id
FROM users u
JOIN roles r ON r.code = 'admin'
WHERE u.email IN ('admin', 'admin@local')
ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);

-- Seed default setting agar modul SEO/Web Config langsung aktif
INSERT INTO app_settings (config_key, config_value, updated_by)
VALUES
  ('web.company_name', 'DayNight', NULL),
  ('web.template_name', 'daynight-default', NULL),
  ('web.font_family', 'Poppins', NULL),
  ('web.logo_url', '', NULL),
  ('web.favicon_url', '', NULL),
  ('seo.site_title', '', NULL),
  ('seo.meta_description', '', NULL),
  ('seo.meta_keywords', '', NULL),
  ('seo.og_image_url', '', NULL),
  ('seo.robots_policy', 'index,follow', NULL),
  ('seo.google_analytics_id', '', NULL),
  ('seo.google_tag_manager_id', '', NULL),
  ('wa_runtime.delay_min_ms', '1000', NULL),
  ('wa_runtime.delay_max_ms', '5000', NULL),
  ('wa_runtime.burst_limit', '5', NULL),
  ('wa_runtime.burst_window_seconds', '60', NULL),
  ('wa_runtime.burst_pause_min_ms', '25000', NULL),
  ('wa_runtime.burst_pause_max_ms', '45000', NULL),
  ('wa_runtime.admin_queue_enabled', '1', NULL),
  ('wa_runtime.user_queue_enabled', '0', NULL),
  ('wa_runtime.queue_batch_limit', '20', NULL),
  ('wa_runtime.queue_max_attempts', '5', NULL),
  ('vpn.auth_scheme', 'basic', NULL),
  ('vpn.endpoint_users', '/users', NULL),
  ('vpn.endpoint_port_forwardings', '/port-forwardings', NULL)
ON DUPLICATE KEY UPDATE
  config_value = VALUES(config_value),
  updated_at = CURRENT_TIMESTAMP;
