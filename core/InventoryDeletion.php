<?php
/** Inventory removal keeps stock history. Only an unimpersonated System Admin releases identifiers. */
final class InventoryDeletion
{
    public static function ready(): bool
    {
        static $ready;
        if ($ready !== null) return $ready;
        try {
            $columns = array_column(Database::query('SHOW COLUMNS FROM inventory_units')->fetchAll(), null, 'Field');
            foreach (['deleted_at','deleted_by','deleted_by_name','deleted_reason','archived_at','archived_by','archived_imei','archived_imei2','archived_serial_no'] as $name) {
                if (!isset($columns[$name])) return $ready = false;
            }
            foreach (['imei','imei2','serial_no'] as $name) {
                if (($columns[$name]['Null'] ?? '') !== 'YES') return $ready = false;
            }
            return $ready = str_contains((string)($columns['status']['Type'] ?? ''), "'archived'")
                && str_contains((string)($columns['status']['Type'] ?? ''), "'deleted'")
                && (bool)Database::query("SHOW TABLES LIKE 'inventory_unit_delete_audits'")->fetchColumn();
        } catch (Throwable $e) { return $ready = false; }
    }

    public static function requireReady(): void
    {
        if (!self::ready()) throw new RuntimeException('Inventory cleanup is not set up yet. Ask the System Admin to install the update.');
    }

    public static function snapshotsReady(): bool
    {
        static $ready;
        if ($ready !== null) return $ready;
        try {
            foreach (['inventory_transfer_units','stock_movements'] as $table) {
                $columns = array_column(Database::query("SHOW COLUMNS FROM $table")->fetchAll(), null, 'Field');
                if (!isset($columns['unit_snapshot'], $columns['deleted_unit_id']) || ($columns['unit_id']['Null'] ?? '') !== 'YES') return $ready = false;
            }
            return $ready = true;
        } catch (Throwable $e) { return $ready = false; }
    }

    public static function noSalesSql(): string
    {
        return "iu.status IN ('deleted','archived') AND NOT EXISTS (SELECT 1 FROM sale_items si WHERE si.unit_id=iu.id)"
            . (inventory_void_schema_ready() ? ' AND NOT EXISTS (SELECT 1 FROM inventory_movement_voids v WHERE v.unit_id=iu.id OR v.movement_id IN (SELECT sm.id FROM stock_movements sm WHERE sm.unit_id=iu.id))' : '');
    }

    public static function previewNoSales(bool $lock = false): array
    {
        $ids = array_map('intval', Database::query('SELECT iu.id FROM inventory_units iu WHERE '.self::noSalesSql().' ORDER BY iu.id'.($lock ? ' FOR UPDATE' : ''))->fetchAll(PDO::FETCH_COLUMN));
        return ['ids' => $ids, 'count' => count($ids), 'preview_token' => hash('sha256', json_encode($ids))];
    }

    public static function references(int $id): array
    {
        $out = [];
        foreach (['sales' => 'sale_items', 'transfers' => 'inventory_transfer_units', 'movements' => 'stock_movements'] as $key => $table) {
            $count = $key === 'sales' ? 'COUNT(DISTINCT sale_id)' : 'COUNT(*)';
            $out[$key] = (int)Database::query("SELECT $count FROM $table WHERE unit_id=?", [$id])->fetchColumn();
        }
        $out['voids'] = inventory_void_schema_ready()
            ? (int)Database::query('SELECT COUNT(*) FROM inventory_movement_voids v WHERE v.unit_id=? OR v.movement_id IN (SELECT sm.id FROM stock_movements sm WHERE sm.unit_id=?)', [$id, $id])->fetchColumn() : 0;
        return $out;
    }

    private static function audit(string $operation, array $unit, int $actorId, array $details = []): void
    {
        // Required audit, in the caller's transaction. No FK: the evidence survives physical deletion.
        Database::query('INSERT INTO inventory_unit_delete_audits (unit_id,branch_id,actor_id,operation,snapshot_json) VALUES (?,?,?,?,?)', [
            $unit['id'], $unit['branch_id'], $actorId, $operation,
            json_encode(['unit' => $unit, 'details' => $details], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public static function softDelete(array $unit, int $actorId, string $reason = ''): void
    {
        self::requireReady();
        if ($unit['status'] === 'deleted') return;
        if ($unit['status'] !== 'available') throw new RuntimeException('Only available units can be removed from Inventory.');
        $changed = Database::query("UPDATE inventory_units SET status='deleted',deleted_at=NOW(),deleted_by=?,deleted_by_name=?,deleted_reason=? WHERE id=? AND status='available'", [
            $actorId, Auth::actorUser()['name'] ?? Auth::user()['name'] ?? 'System User', mb_substr($reason, 0, 255) ?: null, $unit['id'],
        ]);
        if ($changed->rowCount() !== 1) throw new RuntimeException('Inventory changed. Refresh and try again.');
        Database::query('INSERT INTO stock_movements (product_id,unit_id,branch_id,movement_type,quantity,reference_no,notes,created_by) VALUES (?,?,?,?,?,?,?,?)', [
            $unit['product_id'], $unit['id'], $unit['branch_id'], 'adjustment', -1, 'REMOVE-'.$unit['id'],
            'Removed from inventory'.($reason !== '' ? ' — '.mb_substr($reason, 0, 200) : ''), $actorId,
        ]);
        self::audit('soft_delete', $unit, $actorId, ['reason' => $reason]);
        Security::audit('inventory.unit_soft_deleted', 'inventory_unit', $unit['id'], ['branch_id' => $unit['branch_id']]);
    }

    public static function purge(array $ids, int $actorId): array
    {
        self::requireReady();
        if (!self::snapshotsReady()) throw new RuntimeException('Inventory cleanup needs the latest update. Ask the System Admin to install it.');
        if (!Database::connection()->inTransaction()) throw new LogicException('Inventory cleanup requires a transaction.');
        $result = ['deleted' => 0, 'archived' => 0, 'already_processed' => 0];
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            $unit = Database::query('SELECT * FROM inventory_units WHERE id=? FOR UPDATE', [$id])->fetch();
            if (!$unit) { $result['already_processed']++; continue; }
            if (!in_array($unit['status'], ['deleted','archived'], true)) throw new RuntimeException('Only removed inventory units can be permanently cleaned.');
            $refs = self::references((int)$id);
            $product = Database::query('SELECT p.*,b.name brand_name,pm.name model_name FROM products p LEFT JOIN brands b ON b.id=p.brand_id LEFT JOIN product_models pm ON pm.id=p.model_id WHERE p.id=?', [$unit['product_id']])->fetch();
            $label = trim(($product['brand_name'] ?? '').' '.($product['model_name'] ?? '')) ?: ($product['product_name'] ?? 'Unknown product');
            $specs = array_filter(array_intersect_key($product ?: [], array_flip(['ram','storage','connectivity','color'])));
            if ($specs) $label .= ' · '.implode(' · ', $specs);
            $unit['product_label'] = $label;
            $unit['branch_name'] = Database::query('SELECT name FROM branches WHERE id=?', [$unit['branch_id']])->fetchColumn();
            $unit['permanently_deleted_by'] = $actorId;
            $unit['permanently_deleted_by_name'] = Auth::user()['name'] ?? 'System Admin';
            $unit['permanently_deleted_at'] = date('Y-m-d H:i:s');
            if ($refs['sales'] > 0 || $refs['voids'] > 0) {
                if ($unit['status'] === 'archived') { $result['already_processed']++; continue; }
                // Separate archive fields preserve the exact identifiers without length/collision problems.
                Database::query("UPDATE inventory_units SET archived_imei=imei,archived_imei2=imei2,archived_serial_no=serial_no,imei=NULL,imei2=NULL,serial_no=NULL,status='archived',archived_at=NOW(),archived_by=? WHERE id=? AND status='deleted'", [$actorId, $id]);
                $result['archived']++;
                $operation = 'archive';
            } else {
                $snapshot = json_encode([
                    'unit_id' => (int)$id, 'product_label' => $label,
                    'imei' => $unit['imei'] ?: $unit['archived_imei'], 'imei2' => $unit['imei2'] ?: $unit['archived_imei2'],
                    'serial_no' => $unit['serial_no'] ?: $unit['archived_serial_no'],
                    'condition_type' => $unit['condition_type'], 'condition_grade' => $unit['condition_grade'],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $refs['transfer_lines_detached'] = $refs['transfers'];
                $refs['movement_rows_detached'] = $refs['movements'];
                $refs['movement_rows_removed'] = 0;
                // Required evidence is written BEFORE detachment/deletion in the same transaction.
                self::audit('permanent_delete', $unit, $actorId, $refs);
                Database::query('UPDATE inventory_transfer_units SET deleted_unit_id=unit_id,unit_snapshot=?,unit_id=NULL WHERE unit_id=?', [$snapshot, $id]);
                // Quantities, dates and references stay unchanged: every dated report still reconciles.
                Database::query('UPDATE stock_movements SET deleted_unit_id=unit_id,unit_snapshot=?,unit_id=NULL WHERE unit_id=?', [$snapshot, $id]);
                $deleted = Database::query("DELETE FROM inventory_units WHERE id=? AND status IN ('deleted','archived')", [$id]);
                if ($deleted->rowCount() !== 1) throw new RuntimeException('Inventory changed. Refresh and try again.');
                $result['deleted']++;
                $operation = 'permanent_delete';
            }
            if ($operation === 'archive') self::audit($operation, $unit, $actorId, $refs);
            // Keep the existing Permanent Inventory Deletes list working; one entry per unit avoids truncation.
            Security::audit('inventory.units_permanently_deleted', 'product', $unit['product_id'], [
                'branch_id' => $unit['branch_id'],
                'branch_name' => $unit['branch_name'],
                'deleted_count' => 1, 'deleted_units' => [$unit], 'result' => $operation, 'references' => $refs,
            ]);
        }
        return $result;
    }
}
