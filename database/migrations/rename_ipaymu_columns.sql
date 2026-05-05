-- Migration: rename iPaymu-specific columns to generic gateway columns
-- Run this on the vpn_wa_manager database
-- Date: 2026-05-05

ALTER TABLE payments
  CHANGE ipaymu_transaction_id gateway_transaction_id VARCHAR(150) NULL,
  CHANGE ipaymu_session_id     gateway_session_id     VARCHAR(150) NULL;
