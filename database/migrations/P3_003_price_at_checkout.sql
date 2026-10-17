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
