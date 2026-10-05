<?php
if (!Auth::isSystemAdmin() || Auth::isImpersonating()) {
    echo '<section class="card"><div class="empty-state"><strong>Access denied.</strong><span>Return to the System Admin account to use Deleted Records.</span></div></section>';
    return;
}

$q = trim((string)($_GET['q'] ?? ''));
$type = strtolower(trim((string)($_GET['type'] ?? 'all')));
if (!in_array($type, ['all','device','accessory'], true)) $type = 'all';

$rows = [];
$models = [];
$recentDeletes = [];
$stats = ['records' => 0, 'ready' => 0, 'protected' => 0, 'models' => 0];
$dbError = null;

try {
    $hasCatalogDeletedAt = (bool)Database::query("SHOW COLUMNS FROM products LIKE 'catalog_deleted_at'")->fetch();
    $hasCatalogDeletedBy = (bool)Database::query("SHOW COLUMNS FROM products LIKE 'catalog_deleted_by'")->fetch();
    $deletedAtExpr = $hasCatalogDeletedAt ? 'p.catalog_deleted_at' : 'NULL';
    $deletedByJoin = $hasCatalogDeletedBy ? 'LEFT JOIN users du ON du.id=p.catalog_deleted_by' : '';
    $deletedByExpr = $hasCatalogDeletedBy ? 'du.name' : 'NULL';

    $where = ["(p.is_active=0" . ($hasCatalogDeletedAt ? " OR p.catalog_deleted_at IS NOT NULL" : "") . ")"];
    $params = [];
    if ($type === 'device') $where[] = "p.product_type IN ('phone','tablet','preloved')";
    if ($type === 'accessory') $where[] = "p.product_type='accessory'";
    if ($q !== '') {
        $where[] = "(COALESCE(p.product_name,'') LIKE ? OR COALESCE(pm.name,'') LIKE ? OR COALESCE(b.name,'') LIKE ? OR COALESCE(p.storage,'') LIKE ? OR COALESCE(p.color,'') LIKE ?)";
        $needle = '%' . $q . '%';
        $params = [$needle,$needle,$needle,$needle,$needle];
    }

    $sql = "SELECT p.id,p.product_type,p.product_name,p.ram,p.storage,p.connectivity,p.color,p.barcode,p.is_active,p.created_at,
                   b.name AS brand_name,pm.name AS model_name,pm.id AS model_id,c.name AS category_name,
                   {$deletedAtExpr} AS deleted_at,{$deletedByExpr} AS deleted_by_name,
                   (SELECT COUNT(*) FROM inventory_units iu WHERE iu.product_id=p.id) AS unit_count,
                   (SELECT COUNT(*) FROM inventory_units iu WHERE iu.product_id=p.id AND iu.status='available') AS available_units,
                   (SELECT COUNT(*) FROM stock_movements sm WHERE sm.product_id=p.id) AS movement_count,
                   (SELECT COUNT(*) FROM sale_items si WHERE si.product_id=p.id) AS sale_count,
                   (SELECT COUNT(*) FROM inventory_transfers it WHERE it.product_id=p.id) AS transfer_count,
                   COALESCE((SELECT SUM(ib.quantity) FROM inventory_balances ib WHERE ib.product_id=p.id),0) AS balance_qty,
                   (SELECT COUNT(*) FROM branch_product_prices bp WHERE bp.product_id=p.id) AS price_count
            FROM products p
            LEFT JOIN brands b ON b.id=p.brand_id
            LEFT JOIN product_models pm ON pm.id=p.model_id
            LEFT JOIN categories c ON c.id=p.category_id
            {$deletedByJoin}
            WHERE " . implode(' AND ', $where) . "
            ORDER BY COALESCE({$deletedAtExpr},p.created_at) DESC,p.id DESC
            LIMIT 250";
    $rows = Database::query($sql, $params)->fetchAll();

    foreach ($rows as &$row) {
        $row['purge_ready'] = ((int)$row['unit_count'] === 0)
            && ((int)$row['sale_count'] === 0)
            && ((int)$row['transfer_count'] === 0)
            && ((int)$row['balance_qty'] === 0);
    }
    unset($row);

    $models = Database::query(
        "SELECT pm.id,pm.name,pm.device_type,b.name AS brand_name,pm.is_active,
                (SELECT COUNT(*) FROM products p WHERE p.model_id=pm.id) AS product_count
         FROM product_models pm
         JOIN brands b ON b.id=pm.brand_id
         WHERE pm.is_active=0
         ORDER BY b.name,pm.name
         LIMIT 200"
    )->fetchAll();

    $recentDeletes = Database::query(
        "SELECT l.id,l.user_id,l.branch_id,l.entity_id,l.metadata_json,l.created_at,
                u.name AS user_name,b.name AS branch_name
         FROM security_audit_logs l
         LEFT JOIN users u ON u.id=l.user_id
         LEFT JOIN branches b ON b.id=l.branch_id
         WHERE l.event_type='inventory.units_permanently_deleted'
         ORDER BY l.id DESC
         LIMIT 40"
    )->fetchAll();

    $stats['records'] = count($rows);
    $stats['ready'] = count(array_filter($rows, static fn($r) => !empty($r['purge_ready'])));
    $stats['protected'] = $stats['records'] - $stats['ready'];
    $stats['models'] = count($models);
} catch (Throwable $e) {
    Security::reportException($e, 'system_admin_deleted_records');
    $dbError = 'Deleted Records could not be loaded right now.';
}

function cleanup_record_label(array $row): string {
    if (($row['product_type'] ?? '') === 'accessory') {
        return trim((string)($row['product_name'] ?? 'Accessory')) ?: 'Accessory';
    }
    $name = trim(((string)($row['brand_name'] ?? '')) . ' ' . ((string)($row['model_name'] ?? '')));
    $parts = [];
    foreach (['ram','storage','connectivity','color'] as $key) {
        if (!empty($row[$key])) $parts[] = $row[$key];
    }
    return trim($name . ($parts ? ' • ' . implode(' • ', $parts) : ''));
}
?>
<section class="page-heading system-cleanup-heading">
    <div>
        <span class="eyebrow">SYSTEM CLEANUP</span>
        <h1>Deleted Records</h1>
        <p>Review records hidden from branch workflows and permanently purge only incorrect entries that no longer have protected sales or transfer data.</p>
    </div>
    <a class="btn btn-secondary" href="<?= e(app_url('system-admin-dashboard')) ?>"><?= icon('dashboard') ?> System Admin</a>
</section>

<?php if ($dbError): ?><div class="alert alert-error"><?= e($dbError) ?></div><?php endif; ?>

<div class="kpi-grid system-cleanup-kpis">
    <article class="kpi-card tint-blue"><span>Deleted / Archived</span><strong><?= (int)$stats['records'] ?></strong><small>Product and variant records</small></article>
    <article class="kpi-card tint-green"><span>Ready to Purge</span><strong><?= (int)$stats['ready'] ?></strong><small>No protected stock or history</small></article>
    <article class="kpi-card tint-amber"><span>Protected</span><strong><?= (int)$stats['protected'] ?></strong><small>Requires cleanup before purge</small></article>
    <article class="kpi-card tint-violet"><span>Archived Models</span><strong><?= (int)$stats['models'] ?></strong><small>Model master leftovers</small></article>
</div>

<form class="card filter-card system-cleanup-filter" method="get" action="<?= e(app_url('system-admin-deleted-records')) ?>">
    <label class="search-box"><?= icon('search') ?><input name="q" value="<?= e($q) ?>" placeholder="Search deleted product, model, brand, color…"></label>
    <select name="type">
        <option value="all" <?= $type==='all'?'selected':'' ?>>All Records</option>
        <option value="device" <?= $type==='device'?'selected':'' ?>>Devices</option>
        <option value="accessory" <?= $type==='accessory'?'selected':'' ?>>Accessories</option>
    </select>
    <button class="btn btn-primary" type="submit">Apply</button>
    <a class="btn btn-ghost" href="<?= e(app_url('system-admin-deleted-records')) ?>">Reset</a>
</form>

<section class="card table-card system-cleanup-card">
    <div class="card-header">
        <div><span class="section-kicker">DATABASE CLEANUP</span><h2>Deleted Product & Variant Records</h2><p>Permanent delete removes the wrong catalog row and any non-protected stock-movement remnants. Sales and transfer history are never auto-purged.</p></div>
        <span class="security-note"><?= icon('shield') ?> System Admin only</span>
    </div>
    <div class="table-wrap">
        <table class="data-table system-cleanup-table">
            <thead><tr><th>Record</th><th>Deleted / Archived</th><th>Database References</th><th>Cleanup State</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row):
                $ready = !empty($row['purge_ready']);
                $deletedWhen = $row['deleted_at'] ?: $row['created_at'];
            ?>
                <tr>
                    <td data-label="Record">
                        <strong><?= e(cleanup_record_label($row)) ?></strong>
                        <span class="table-subtext"><?= e(($row['product_type'] ?? '') === 'accessory' ? ('Accessory • '.($row['category_name'] ?: 'Uncategorized')) : ucfirst((string)$row['product_type'])) ?> • Product ID <?= (int)$row['id'] ?></span>
                    </td>
                    <td data-label="Deleted / Archived">
                        <strong><?= $deletedWhen ? e(date('M j, Y • g:i A', strtotime((string)$deletedWhen))) : '—' ?></strong>
                        <span class="table-subtext"><?= !empty($row['deleted_by_name']) ? 'By '.e($row['deleted_by_name']) : 'Archived / system-generated cleanup candidate' ?></span>
                    </td>
                    <td data-label="Database References">
                        <div class="cleanup-reference-grid">
                            <span>Units <b><?= (int)$row['unit_count'] ?></b></span>
                            <span>Balance <b><?= (int)$row['balance_qty'] ?></b></span>
                            <span>Movements <b><?= (int)$row['movement_count'] ?></b></span>
                            <span>Sales <b><?= (int)$row['sale_count'] ?></b></span>
                            <span>Transfers <b><?= (int)$row['transfer_count'] ?></b></span>
                        </div>
                    </td>
                    <td data-label="Cleanup State">
                        <?php if ($ready): ?>
                            <span class="cleanup-state ready">Ready to purge</span>
                            <?php if ((int)$row['movement_count'] > 0): ?><small class="cleanup-state-note"><?= (int)$row['movement_count'] ?> non-protected movement<?= (int)$row['movement_count']===1?'':'s' ?> will also be removed.</small><?php endif; ?>
                        <?php else: ?>
                            <span class="cleanup-state protected">Protected references</span>
                            <small class="cleanup-state-note">Remove remaining stock first. Sales/transfer history cannot be permanently purged here.</small>
                        <?php endif; ?>
                    </td>
                    <td data-label="Action">
                        <?php if ($ready): ?>
                        <form method="post" action="actions/system_admin_deleted_records.php" data-confirm="Permanently delete this incorrect database record? This cannot be undone.">
                            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
                            <input type="hidden" name="action" value="purge_product">
                            <input type="hidden" name="product_id" value="<?= (int)$row['id'] ?>">
                            <button class="btn btn-danger btn-sm" type="submit"><?= icon('trash') ?> Permanently Delete</button>
                        </form>
                        <?php else: ?><button class="btn btn-secondary btn-sm" type="button" disabled>Protected</button><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="5"><div class="empty-state small"><strong>No deleted records found.</strong><span>Branch cleanup leftovers will appear here when a catalog row remains in the database.</span></div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card system-cleanup-models">
    <div class="card-header"><div><span class="section-kicker">MODEL MASTER</span><h2>Archived Models</h2><p>Unused archived models can be removed after every child product/variant has been cleaned.</p></div><span class="count-badge"><?= count($models) ?></span></div>
    <div class="cleanup-model-grid">
        <?php foreach ($models as $model): ?>
        <article class="cleanup-model-item">
            <div><strong><?= e($model['brand_name'].' '.$model['name']) ?></strong><span><?= e(ucfirst((string)$model['device_type'])) ?> • <?= (int)$model['product_count'] ?> linked product<?= (int)$model['product_count']===1?'':'s' ?></span></div>
            <?php if ((int)$model['product_count'] === 0): ?>
            <form method="post" action="actions/system_admin_deleted_records.php" data-confirm="Permanently delete this unused archived model?">
                <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
                <input type="hidden" name="action" value="purge_model">
                <input type="hidden" name="model_id" value="<?= (int)$model['id'] ?>">
                <button class="table-action-btn danger" type="submit"><?= icon('trash') ?><span>Delete</span></button>
            </form>
            <?php else: ?><span class="cleanup-state protected compact">Clean variants first</span><?php endif; ?>
        </article>
        <?php endforeach; ?>
        <?php if (!$models): ?><div class="empty-state small"><strong>No archived model leftovers.</strong></div><?php endif; ?>
    </div>
</section>

<section class="card table-card system-cleanup-audit">
    <div class="card-header"><div><span class="section-kicker">RECENT ACTIVITY</span><h2>Permanent Inventory Deletes</h2><p>Audit-only history of device units that Branch Managers or System Admin already removed from inventory.</p></div></div>
    <div class="table-wrap"><table class="data-table compact-table"><thead><tr><th>Date</th><th>User</th><th>Branch</th><th>Product ID</th><th>Deleted Units</th></tr></thead><tbody>
    <?php foreach ($recentDeletes as $log):
        $meta = json_decode((string)($log['metadata_json'] ?? ''), true);
        if (!is_array($meta)) $meta = [];
        $deletedCount = (int)($meta['deleted_count'] ?? 0);
    ?>
    <tr><td><?= e(date('M j, Y • g:i A',strtotime((string)$log['created_at']))) ?></td><td><strong><?= e($log['user_name'] ?: 'System User') ?></strong></td><td><?= e($meta['branch_name'] ?? $log['branch_name'] ?? '—') ?></td><td><?= e((string)($log['entity_id'] ?? '—')) ?></td><td><span class="cleanup-state removed"><?= $deletedCount ?> unit<?= $deletedCount===1?'':'s' ?></span></td></tr>
    <?php endforeach; ?>
    <?php if (!$recentDeletes): ?><tr><td colspan="5"><div class="empty-state small"><strong>No permanent inventory deletions yet.</strong></div></td></tr><?php endif; ?>
    </tbody></table></div>
</section>
