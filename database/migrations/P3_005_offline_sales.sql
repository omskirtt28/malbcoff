-- P3_005 — Offline sales outbox (Patch 6c-2: POS checkout can be saved on the device and sent later)
--
-- A sale completed while the internet was down is kept on the cashier's device and sent when the connection returns.
-- The server creates it exactly once, using the sale's client id (the Patch 6a idempotency key).
--
--   sales.client_id           the device's UUID for this sale (UNIQUE → a retry or duplicate can never create a second sale).
--                             NULL on every sale made online or before this patch (many NULLs are allowed by a UNIQUE key).
--   sales.is_offline          1 = the sale was saved on a device first and sent later.
--   sales.device_time         the time on the cashier's device when the sale was made (as reported by the device).
--   sales.received_at         the server time when the sale was accepted. sales.created_at keeps the sale time
--                             (= device time when it is plausible, otherwise = received_at) so every report counts the sale on the right day.
--   sales.offline_receipt_no  the temporary receipt number shown on the device, e.g. OFFLINE-CBU1-20261017-1.
--
--   outbox_reports.details    widened to 2000 characters: a refused sale lists its items, amounts, payment and device time for the owner.
--
-- Old sales keep working: every new column is NULL / 0 for them. Nothing is dropped, truncated or rewritten.
-- Safe to run more than once: each change runs only when it is still missing.
-- Import with phpMyAdmin (Import tab) or:  mysql -u USER -p DATABASE < database/migrations/P3_005_offline_sales.sql

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales' AND COLUMN_NAME='client_id')=0,
  'ALTER TABLE sales ADD COLUMN client_id CHAR(36) NULL AFTER sale_no', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales' AND COLUMN_NAME='is_offline')=0,
  'ALTER TABLE sales ADD COLUMN is_offline TINYINT(1) NOT NULL DEFAULT 0 AFTER client_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales' AND COLUMN_NAME='device_time')=0,
  'ALTER TABLE sales ADD COLUMN device_time DATETIME NULL AFTER is_offline', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales' AND COLUMN_NAME='received_at')=0,
  'ALTER TABLE sales ADD COLUMN received_at DATETIME NULL AFTER device_time', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales' AND COLUMN_NAME='offline_receipt_no')=0,
  'ALTER TABLE sales ADD COLUMN offline_receipt_no VARCHAR(60) NULL AFTER received_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales' AND INDEX_NAME='uq_sales_client_id')=0,
  'ALTER TABLE sales ADD UNIQUE KEY uq_sales_client_id (client_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Needs-action reports of refused sales are longer than a stock action (items + amounts + payment + device time).
-- Only when the table exists (P3_004) and is still narrower than 2000 characters.
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='outbox_reports' AND COLUMN_NAME='details' AND CHARACTER_MAXIMUM_LENGTH<2000)>0,
  'ALTER TABLE outbox_reports MODIFY COLUMN details VARCHAR(2000) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
