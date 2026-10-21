-- P3_006: Soft deletion, history-preserving identifier release, and legacy removals.
-- Import BEFORE uploading the PHP/JS update. Safe to run repeatedly. Never delete history.
-- Legacy adjusted_out = removed inventory, not sold/defective/in-transit stock.
-- Interpret legacy TIMESTAMP evidence in the application's Asia/Manila timezone.
SET @p3006_previous_time_zone := @@session.time_zone;
SET SESSION time_zone = '+08:00';

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_units' AND COLUMN_NAME='deleted_at')=0, 'ALTER TABLE inventory_units ADD COLUMN deleted_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_units' AND COLUMN_NAME='deleted_by')=0, 'ALTER TABLE inventory_units ADD COLUMN deleted_by INT UNSIGNED NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_units' AND COLUMN_NAME='deleted_by_name')=0, 'ALTER TABLE inventory_units ADD COLUMN deleted_by_name VARCHAR(160) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_units' AND COLUMN_NAME='deleted_reason')=0, 'ALTER TABLE inventory_units ADD COLUMN deleted_reason VARCHAR(255) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_units' AND COLUMN_NAME='archived_at')=0, 'ALTER TABLE inventory_units ADD COLUMN archived_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_units' AND COLUMN_NAME='archived_by')=0, 'ALTER TABLE inventory_units ADD COLUMN archived_by INT UNSIGNED NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_units' AND COLUMN_NAME='archived_imei')=0, 'ALTER TABLE inventory_units ADD COLUMN archived_imei VARCHAR(80) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_units' AND COLUMN_NAME='archived_imei2')=0, 'ALTER TABLE inventory_units ADD COLUMN archived_imei2 VARCHAR(80) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_units' AND COLUMN_NAME='archived_serial_no')=0, 'ALTER TABLE inventory_units ADD COLUMN archived_serial_no VARCHAR(120) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Released identifiers use NULL; unique indexes are retained. Preserve existing column widths.
SET @sql := IF((SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_units' AND COLUMN_NAME='imei')='NO', CONCAT('ALTER TABLE inventory_units MODIFY COLUMN imei ',(SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_units' AND COLUMN_NAME='imei'),' NULL'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_units' AND COLUMN_NAME='imei2')='NO', CONCAT('ALTER TABLE inventory_units MODIFY COLUMN imei2 ',(SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_units' AND COLUMN_NAME='imei2'),' NULL'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_units' AND COLUMN_NAME='serial_no')='NO', CONCAT('ALTER TABLE inventory_units MODIFY COLUMN serial_no ',(SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_units' AND COLUMN_NAME='serial_no'),' NULL'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Preserve all pre-existing enum states, including host-specific ones.
SET @status_type := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_units' AND COLUMN_NAME='status');
SET @sql := IF(LOCATE('''deleted''',@status_type)=0, CONCAT('ALTER TABLE inventory_units MODIFY status ',LEFT(@status_type,LENGTH(@status_type)-1), ',''deleted'') NOT NULL DEFAULT ''available'''), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @status_type := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_units' AND COLUMN_NAME='status');
SET @sql := IF(LOCATE('''archived''',@status_type)=0, CONCAT('ALTER TABLE inventory_units MODIFY status ',LEFT(@status_type,LENGTH(@status_type)-1), ',''archived'') NOT NULL DEFAULT ''available'''), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS inventory_unit_delete_audits (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 unit_id BIGINT UNSIGNED NOT NULL,
 branch_id INT UNSIGNED NOT NULL,
 actor_id INT UNSIGNED NULL,
 operation VARCHAR(32) NOT NULL,
 snapshot_json LONGTEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_unit_delete_audit (unit_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_units' AND INDEX_NAME='idx_units_deleted')=0, 'ALTER TABLE inventory_units ADD INDEX idx_units_deleted (status,branch_id,deleted_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

START TRANSACTION;
-- Only retained removed rows can be recovered. Physical deletes stay in security_audit_logs.
-- Use the last negative adjustment as evidence, falling back to the last known update time.
UPDATE inventory_units iu
LEFT JOIN stock_movements sm ON sm.id=(SELECT MAX(s.id) FROM stock_movements s WHERE s.unit_id=iu.id AND s.movement_type='adjustment' AND s.quantity<0)
LEFT JOIN users u ON u.id=sm.created_by
SET iu.deleted_at=COALESCE(iu.deleted_at,sm.created_at,iu.updated_at,iu.created_at),
    iu.deleted_by=COALESCE(iu.deleted_by,sm.created_by),
    iu.deleted_by_name=COALESCE(iu.deleted_by_name,u.name),
    iu.deleted_reason=COALESCE(iu.deleted_reason,sm.notes,'Legacy removal; exact deletion details unavailable'),
    iu.status='deleted'
WHERE iu.status IN ('adjusted_out','deleted') AND iu.archived_at IS NULL;

-- Some older removals did not record an outgoing movement. Close only a positive
-- residual in the unit's last branch; do not double-subtract an existing removal.
-- Include void originals + restores here: as pairs they have net zero.
INSERT INTO stock_movements (product_id,unit_id,branch_id,movement_type,quantity,reference_no,notes,created_by,created_at)
SELECT iu.product_id,iu.id,iu.branch_id,'adjustment',-SUM(sm.quantity),CONCAT('REMOVE-BACKFILL-',iu.id),
 'Removed from inventory — legacy removal reconciliation',COALESCE(iu.deleted_by,iu.created_by),iu.deleted_at
FROM inventory_units iu JOIN stock_movements sm ON sm.unit_id=iu.id AND sm.branch_id=iu.branch_id
WHERE iu.status='deleted'
GROUP BY iu.id,iu.product_id,iu.branch_id,iu.deleted_by,iu.created_by,iu.deleted_at
HAVING SUM(sm.quantity)>0;
COMMIT;
SET SESSION time_zone = @p3006_previous_time_zone;
