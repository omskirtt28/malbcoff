-- FIX 8C-2. Apply after P3_006 and BEFORE the app update. Safe to re-run.
-- Schema only: no units are deleted by migration. Admin confirmation is required.
-- Detached ledger rows retain every original quantity, date and transfer reference.
SET @sql := IF((SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transfer_units' AND COLUMN_NAME='unit_id')='NO', CONCAT('ALTER TABLE inventory_transfer_units MODIFY unit_id ',(SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transfer_units' AND COLUMN_NAME='unit_id'),' NULL'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_movements' AND COLUMN_NAME='unit_id')='NO', CONCAT('ALTER TABLE stock_movements MODIFY unit_id ',(SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_movements' AND COLUMN_NAME='unit_id'),' NULL'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transfer_units' AND COLUMN_NAME='deleted_unit_id')=0, 'ALTER TABLE inventory_transfer_units ADD deleted_unit_id BIGINT UNSIGNED NULL COMMENT ''Audit identity only; never a live unit reference''', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transfer_units' AND COLUMN_NAME='unit_snapshot')=0, 'ALTER TABLE inventory_transfer_units ADD unit_snapshot LONGTEXT NULL COMMENT ''Deleted unit product label and identifiers (JSON)''', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_movements' AND COLUMN_NAME='deleted_unit_id')=0, 'ALTER TABLE stock_movements ADD deleted_unit_id BIGINT UNSIGNED NULL COMMENT ''Audit identity only; never a live unit reference''', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_movements' AND COLUMN_NAME='unit_snapshot')=0, 'ALTER TABLE stock_movements ADD unit_snapshot LONGTEXT NULL COMMENT ''Deleted unit product label and identifiers (JSON)''', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
