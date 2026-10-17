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
