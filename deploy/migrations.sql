-- Malbcoff deploy migrations — every section is safe to run more than once.
-- Run in order, on the live database, BEFORE copying the new code. P3_001-P3_003 may already be applied (re-running is harmless).
-- P3_001 and P3_003 need the TRIGGER privilege. Patch 6c-1 adds P3_004 (outbox_reports). Patch 6c-2 adds P3_005 (offline sales columns on `sales`).

-- ============================================================
-- P3_001_user_name_snapshots.sql
-- ============================================================
-- P3_001 — User display-name snapshots (Patch 5: edit own display name)
--
-- Purpose: when a user renames themselves, HISTORY must keep the name used at the time.
-- Records that only stored a user id get a snapshot column that is filled when the row is
-- created (BEFORE INSERT trigger — no application INSERT had to change) and backfilled once
-- for existing rows with the user's CURRENT name (the name that was in use until now).
--
--   sales.cashier_name                         <- users.name of created_by
--   stock_movements.created_by_name            <- users.name of created_by   (stock in, sold, transfers, adjustments…)
--   inventory_transfers.forwarded_by_snapshot  <- users.name of forwarded_by
--   inventory_transfers.received_by_snapshot   <- users.name of received_by  (set when the transfer is received)
--
-- Safe to run more than once: columns are added only when missing, the backfill only touches
-- rows whose snapshot is still NULL, triggers are dropped and recreated.
-- Needs the TRIGGER privilege. If the host refuses triggers the app still works (readers fall back
-- to the live users.name when a snapshot is empty) but renames would then show on new rows.
-- Import with phpMyAdmin (Import tab) or:  mysql -u USER -p DATABASE < database/migrations/P3_001_user_name_snapshots.sql

-- ---------------------------------------------------------------- columns (add only if missing)
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales' AND COLUMN_NAME='cashier_name')=0,
  'ALTER TABLE sales ADD COLUMN cashier_name VARCHAR(120) NULL AFTER created_by', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_movements' AND COLUMN_NAME='created_by_name')=0,
  'ALTER TABLE stock_movements ADD COLUMN created_by_name VARCHAR(120) NULL AFTER created_by', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transfers' AND COLUMN_NAME='forwarded_by_snapshot')=0,
  'ALTER TABLE inventory_transfers ADD COLUMN forwarded_by_snapshot VARCHAR(120) NULL AFTER forwarded_by', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transfers' AND COLUMN_NAME='received_by_snapshot')=0,
  'ALTER TABLE inventory_transfers ADD COLUMN received_by_snapshot VARCHAR(120) NULL AFTER received_by', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------- backfill (only rows still NULL)
UPDATE sales s JOIN users u ON u.id = s.created_by SET s.cashier_name = u.name WHERE s.cashier_name IS NULL;
UPDATE stock_movements sm JOIN users u ON u.id = sm.created_by SET sm.created_by_name = u.name WHERE sm.created_by_name IS NULL;
UPDATE inventory_transfers t JOIN users u ON u.id = t.forwarded_by SET t.forwarded_by_snapshot = u.name WHERE t.forwarded_by_snapshot IS NULL;
UPDATE inventory_transfers t JOIN users u ON u.id = t.received_by SET t.received_by_snapshot = u.name WHERE t.received_by_snapshot IS NULL AND t.received_by IS NOT NULL;

-- ---------------------------------------------------------------- triggers (fill the snapshot when a row is created)
DROP TRIGGER IF EXISTS trg_sales_name_snapshot;
DROP TRIGGER IF EXISTS trg_stock_movements_name_snapshot;
DROP TRIGGER IF EXISTS trg_inventory_transfers_name_snapshot_ins;
DROP TRIGGER IF EXISTS trg_inventory_transfers_name_snapshot_upd;

DELIMITER $$
CREATE TRIGGER trg_sales_name_snapshot BEFORE INSERT ON sales FOR EACH ROW
BEGIN
  IF NEW.cashier_name IS NULL OR NEW.cashier_name = '' THEN
    SET NEW.cashier_name = (SELECT u.name FROM users u WHERE u.id = NEW.created_by LIMIT 1);
  END IF;
END$$

CREATE TRIGGER trg_stock_movements_name_snapshot BEFORE INSERT ON stock_movements FOR EACH ROW
BEGIN
  IF NEW.created_by_name IS NULL OR NEW.created_by_name = '' THEN
    SET NEW.created_by_name = (SELECT u.name FROM users u WHERE u.id = NEW.created_by LIMIT 1);
  END IF;
END$$

CREATE TRIGGER trg_inventory_transfers_name_snapshot_ins BEFORE INSERT ON inventory_transfers FOR EACH ROW
BEGIN
  IF NEW.forwarded_by_snapshot IS NULL OR NEW.forwarded_by_snapshot = '' THEN
    SET NEW.forwarded_by_snapshot = (SELECT u.name FROM users u WHERE u.id = NEW.forwarded_by LIMIT 1);
  END IF;
  IF NEW.received_by IS NOT NULL AND (NEW.received_by_snapshot IS NULL OR NEW.received_by_snapshot = '') THEN
    SET NEW.received_by_snapshot = (SELECT u.name FROM users u WHERE u.id = NEW.received_by LIMIT 1);
  END IF;
END$$

CREATE TRIGGER trg_inventory_transfers_name_snapshot_upd BEFORE UPDATE ON inventory_transfers FOR EACH ROW
BEGIN
  IF NEW.received_by IS NOT NULL AND (NEW.received_by_snapshot IS NULL OR NEW.received_by_snapshot = '') THEN
    SET NEW.received_by_snapshot = (SELECT u.name FROM users u WHERE u.id = NEW.received_by LIMIT 1);
  END IF;
END$$
DELIMITER ;

-- ============================================================
-- P3_002_idempotency_keys.sql
-- ============================================================
-- P3_002 — Idempotency keys (Patch 6a: safe retries on weak internet)
--
-- One row per client-generated request key. It is inserted inside the SAME database transaction as the
-- work it protects (sale, receive stock, transfer, transfer receipt, stock adjustment) and stores the
-- response, so a retry with the same key returns the original result and never creates a second
-- record or moves stock twice. Requests without a key keep working as before.
--
--   client_key     UUID sent by the browser (unique index = the guarantee)
--   action         sale | stock_in | transfer_forward | transfer_receive | stock_adjust
--   user_id        who made the request (a key can't be replayed by someone else)
--   request_hash   sha256 of the submitted content (a different payload under the same key is refused)
--   reference_no   e.g. SALE-…, STK-…, TRF-… of the resulting record (for support lookups)
--   result_json    the original response body returned on replay
--
-- Safe to run more than once (CREATE TABLE IF NOT EXISTS). Old rows are only useful for a short time;
-- they may be deleted after ~30 days:  DELETE FROM idempotency_keys WHERE created_at < NOW() - INTERVAL 30 DAY;

CREATE TABLE IF NOT EXISTS idempotency_keys (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_key    CHAR(36)        NOT NULL,
  action        VARCHAR(40)     NOT NULL,
  user_id       INT UNSIGNED    NOT NULL,
  request_hash  CHAR(64)        NOT NULL,
  reference_no  VARCHAR(100)    NULL,
  result_json   MEDIUMTEXT      NULL,
  created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_idempotency_client_key (client_key),
  KEY idx_idempotency_created_at (created_at),
  KEY idx_idempotency_reference (reference_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- P3_003_price_at_checkout.sql
-- ============================================================
-- P3_003 — Price at checkout (Patch 8)
--
-- Purpose: every sale line now carries the price the cashier typed at checkout.
--   sale_items.line_total        = SOURCE OF TRUTH for the line (what the customer pays)
--   sale_items.unit_price        = computed per-piece price (line_total / quantity, 2 decimals)
--   sale_items.price_entry_mode  = 'piece' (cashier typed a per-piece price) or 'total' (typed the total for all)
--                                  NULL on old rows (sold before this patch: they keep their old unit_price / line_total)
--
-- Completed sales are FINAL: a BEFORE UPDATE trigger refuses any change to quantity, unit_price, line_total
-- or price_entry_mode of a saved sale line — for everybody, including the owner and database tools used by the app.
-- (Voiding a line does not edit it: it is recorded in inventory_movement_voids.)
--
-- Safe to run more than once: the column is added only if missing, the trigger is dropped and recreated.
-- Needs the TRIGGER privilege. Without the trigger the app still works; the price lock is then only in the PHP code.
-- Import with phpMyAdmin (Import tab) or:  mysql -u USER -p DATABASE < database/migrations/P3_003_price_at_checkout.sql

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sale_items' AND COLUMN_NAME='price_entry_mode')=0,
  'ALTER TABLE sale_items ADD COLUMN price_entry_mode VARCHAR(10) NULL AFTER line_total', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

DROP TRIGGER IF EXISTS trg_sale_items_price_final;

DELIMITER $$
CREATE TRIGGER trg_sale_items_price_final BEFORE UPDATE ON sale_items FOR EACH ROW
BEGIN
  IF NOT (NEW.quantity <=> OLD.quantity)
     OR NOT (NEW.unit_price <=> OLD.unit_price)
     OR NOT (NEW.line_total <=> OLD.line_total)
     OR NOT (NEW.price_entry_mode <=> OLD.price_entry_mode) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Completed sales are final. The price cannot be changed.';
  END IF;
END$$
DELIMITER ;

-- ============================================================
-- P3_004_outbox_reports.sql
-- ============================================================
-- P3_004 — Outbox "Needs action" reports (Patch 6c-1: offline outbox for stock actions)
--
-- When a queued stock action (Receive Stock, Branch Transfer create, Transfer receive) is finally refused by the
-- server (serial already received, not enough stock, transfer already received…), the phone reports it here so the
-- OWNER can see it. The report holds NO stock data of its own and never changes stock; it is only a to-do list.
--
--   client_key       the outbox item id = the Patch 6a idempotency key (UNIQUE → reporting twice is harmless)
--   user_id          who queued it (taken from the session, never from the browser)
--   branch_id        that user's branch (from the session)
--   action_type      stock_in | transfer_forward | transfer_receive
--   summary          one plain line, e.g. "Receive 2 × Samsung Galaxy A15 • 128GB"
--   details          optional extra lines (identifiers, destination…) for the owner
--   reason           why the server refused it, in plain English
--   queued_at        when the staff member tapped the button (phone clock, informational)
--   staff_discarded  1 = the staff member discarded the item on the device
--   status           open | resolved
--
-- Safe to run more than once (CREATE TABLE IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS outbox_reports (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_key       CHAR(36)        NOT NULL,
  user_id          INT UNSIGNED    NOT NULL,
  branch_id        INT UNSIGNED    NULL,
  action_type      VARCHAR(40)     NOT NULL,
  summary          VARCHAR(255)    NOT NULL,
  details          VARCHAR(600)    NULL,
  reason           VARCHAR(500)    NOT NULL,
  queued_at        DATETIME        NULL,
  staff_discarded  TINYINT(1)      NOT NULL DEFAULT 0,
  status           VARCHAR(10)     NOT NULL DEFAULT 'open',
  resolved_by      INT UNSIGNED    NULL,
  resolved_at      DATETIME        NULL,
  resolved_note    VARCHAR(255)    NULL,
  created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_outbox_reports_client_key (client_key),
  KEY idx_outbox_reports_status (status, created_at),
  KEY idx_outbox_reports_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- P3_005_offline_sales.sql
-- ============================================================
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
