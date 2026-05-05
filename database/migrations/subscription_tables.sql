-- =====================================================
-- Subscription & Payment Schema
-- Phase 1: Plans, User Subscriptions, Feature Usage,
--           Payments, Invoices, Payment Notifications
-- =====================================================

USE vpn_wa_manager;

-- =========================
-- Plans (Paket berlangganan)
-- =========================
CREATE TABLE IF NOT EXISTS plans (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                VARCHAR(50)    NOT NULL UNIQUE,          -- 'free','basic','premium'
  label               VARCHAR(100)   NOT NULL,                 -- 'Free Trial','Basic','Premium'
  duration_days       SMALLINT UNSIGNED NOT NULL DEFAULT 30,   -- 3 / 30
  price               DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
  vpn_limit           TINYINT UNSIGNED NOT NULL DEFAULT 1,
  wa_device_limit     TINYINT UNSIGNED NOT NULL DEFAULT 1,
  proxy_route_limit   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  is_active           TINYINT(1) NOT NULL DEFAULT 1,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =========================
-- User Subscriptions
-- =========================
CREATE TABLE IF NOT EXISTS user_subscriptions (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         BIGINT UNSIGNED NOT NULL,
  plan_id         INT UNSIGNED NOT NULL,
  started_at      DATETIME NOT NULL,
  expires_at      DATETIME NOT NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  disabled_reason VARCHAR(100) NULL COMMENT 'expired | manual_block | payment_failed',
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_usub_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_usub_plan FOREIGN KEY (plan_id) REFERENCES plans(id),
  UNIQUE KEY uq_user_subscription (user_id),
  INDEX idx_usub_expires (expires_at),
  INDEX idx_usub_active  (is_active)
) ENGINE=InnoDB;

-- =========================
-- User Feature Usage
-- (direset setiap renew/upgrade)
-- =========================
CREATE TABLE IF NOT EXISTS user_features_usage (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id             BIGINT UNSIGNED NOT NULL,
  vpn_count           TINYINT UNSIGNED NOT NULL DEFAULT 0,
  wa_device_count     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  proxy_route_count   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  reset_at            DATETIME NULL COMMENT 'Kapan terakhir counter direset',
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ufusage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_ufusage_user (user_id)
) ENGINE=InnoDB;

-- =========================
-- Payments
-- =========================
CREATE TABLE IF NOT EXISTS payments (
  id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id                  BIGINT UNSIGNED NOT NULL,
  plan_id                  INT UNSIGNED NOT NULL,
  amount                   DECIMAL(12,2) NOT NULL,
  invoice_number           VARCHAR(100) NOT NULL UNIQUE,       -- INV-20260505-0001
  gateway_transaction_id   VARCHAR(150) NULL,
  gateway_session_id       VARCHAR(150) NULL,
  payment_method           VARCHAR(50) NULL,                   -- 'va','qris','cc'
  status                   ENUM('pending','success','failed','expired') NOT NULL DEFAULT 'pending',
  paid_at                  DATETIME NULL,
  invoice_expired_at       DATETIME NULL,
  notes                    TEXT NULL,
  created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pay_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_pay_plan FOREIGN KEY (plan_id) REFERENCES plans(id),
  INDEX idx_pay_user   (user_id),
  INDEX idx_pay_status (status),
  INDEX idx_pay_created (created_at)
) ENGINE=InnoDB;

-- =========================
-- Invoices
-- =========================
CREATE TABLE IF NOT EXISTS invoices (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id          BIGINT UNSIGNED NOT NULL,
  payment_id       BIGINT UNSIGNED NOT NULL,
  plan_id          INT UNSIGNED NOT NULL,
  invoice_number   VARCHAR(100) NOT NULL UNIQUE,
  amount           DECIMAL(12,2) NOT NULL,
  due_date         DATETIME NOT NULL,
  status           ENUM('draft','sent','paid','overdue','cancelled') NOT NULL DEFAULT 'draft',
  payment_url      VARCHAR(512) NULL COMMENT 'URL pembayaran dari iPaymu',
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_inv_user    FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_inv_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
  CONSTRAINT fk_inv_plan    FOREIGN KEY (plan_id)    REFERENCES plans(id),
  INDEX idx_inv_user   (user_id),
  INDEX idx_inv_status (status)
) ENGINE=InnoDB;

-- =========================
-- Payment Notifications
-- (raw webhook dari iPaymu)
-- =========================
CREATE TABLE IF NOT EXISTS payment_notifications (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id          BIGINT UNSIGNED NULL,
  raw_payload         JSON NULL COMMENT 'Seluruh body webhook dari iPaymu',
  notification_type   VARCHAR(50) NULL,                        -- 'payment','refund'
  status_code         SMALLINT NULL,
  trx_id              VARCHAR(150) NULL,
  processed_at        DATETIME NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notif_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
  INDEX idx_notif_trx (trx_id)
) ENGINE=InnoDB;

-- =========================
-- Seed: Plans
-- =========================
INSERT INTO plans (name, label, duration_days, price, vpn_limit, wa_device_limit, proxy_route_limit, is_active)
VALUES
  ('free',    'Free Trial', 3,  0.00,      1, 1, 1, 1),
  ('basic',   'Basic',      30, 50000.00,  2, 1, 1, 1),
  ('premium', 'Premium',    30, 100000.00, 4, 2, 4, 1)
ON DUPLICATE KEY UPDATE
  label             = VALUES(label),
  duration_days     = VALUES(duration_days),
  price             = VALUES(price),
  vpn_limit         = VALUES(vpn_limit),
  wa_device_limit   = VALUES(wa_device_limit),
  proxy_route_limit = VALUES(proxy_route_limit),
  updated_at        = CURRENT_TIMESTAMP;
