<?php
if (!Auth::isSystemAdmin() || Auth::isImpersonating()) {
    echo '<section class="card"><div class="empty-state"><strong>Access denied.</strong><span>Return to the System Admin account to use Sold Units.</span></div></section>';
    return;
}

$voidReady = inventory_void_schema_ready();
$soldPage = max(1, (int)($_GET['sold_page'] ?? 1));
$soldSearch = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 120);
$soldBranch = max(0, (int)($_GET['branch'] ?? 0));
$soldFrom = (string)($_GET['from'] ?? '');
$soldTo = (string)($_GET['to'] ?? '');
foreach (['soldFrom', 'soldTo'] as $dateVar) {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $$dateVar);
    if (!$d || $d->format('Y-m-d') !== $$dateVar) $$dateVar = '';
}
$soldRows = [];
$soldCount = 0;
$soldPages = 1;
$soldBranches = [];
$soldError = '';
try {
    $soldBranches = Database::query('SELECT id,name FROM branches ORDER BY name')->fetchAll();
    // deleted_unit_id only exists after the inventory cleanup migration; Sold Units must work without it.
    $deletedCol = Database::query("SHOW COLUMNS FROM stock_movements LIKE 'deleted_unit_id'")->fetch() ? 'sm.deleted_unit_id' : 'NULL';
    $where = ["iu.status='sold'"];
    $params = [];
    if ($voidReady) $where[] = 'v.id IS NULL';
    if ($soldBranch) { $where[] = 'iu.branch_id=?'; $params[] = $soldBranch; }
    // The sale date comes from the sale stock movement, not from the unit row.
    if ($soldFrom !== '') { $where[] = 'sm.created_at>=?'; $params[] = $soldFrom . ' 00:00:00'; }
    if ($soldTo !== '') { $where[] = 'sm.created_at<?'; $params[] = date('Y-m-d', strtotime($soldTo . ' +1 day')) . ' 00:00:00'; }
    if ($soldSearch !== '') {
        $fields = ['iu.imei', 'iu.imei2', 'iu.serial_no', 'p.product_name', 'pm.name', 'br.name', 'sm.reference_no'];
        $where[] = '(' . implode(' OR ', array_map(static fn($f) => "$f LIKE ?", $fields)) . ')';
        foreach ($fields as $field) $params[] = '%' . $soldSearch . '%';
    }
    $voidJoin = $voidReady ? 'LEFT JOIN inventory_movement_voids v ON v.movement_id=sm.id' : '';
    $joins = " FROM inventory_units iu
        JOIN products p ON p.id=iu.product_id
        LEFT JOIN product_models pm ON pm.id=p.model_id
        LEFT JOIN brands br ON br.id=p.brand_id
        LEFT JOIN branches b ON b.id=iu.branch_id
        LEFT JOIN stock_movements sm ON sm.id=(SELECT MAX(x.id) FROM stock_movements x WHERE x.unit_id=iu.id AND x.movement_type='sale' AND x.quantity<0)
        LEFT JOIN sales s ON s.sale_no=sm.reference_no AND s.branch_id=sm.branch_id
        LEFT JOIN sale_items si ON si.sale_id=s.id AND si.unit_id=iu.id AND si.product_id=sm.product_id
        {$voidJoin}
        WHERE " . implode(' AND ', $where);
    $soldCount = (int)Database::query('SELECT COUNT(*)' . $joins, $params)->fetchColumn();
    $soldPages = max(1, (int)ceil($soldCount / 25));
    $soldPage = min($soldPage, $soldPages);
    $offset = ($soldPage - 1) * 25;
    $soldRows = Database::query(
        "SELECT iu.id unit_id,iu.imei,iu.imei2,iu.serial_no,iu.product_id,iu.branch_id,p.product_type,p.product_name,p.ram,p.storage,p.connectivity,p.color,p.is_active product_active,
            pm.name model_name,br.name brand_name,b.name branch_name,b.is_active branch_active,
            sm.id movement_id,sm.reference_no,sm.created_at sold_at,{$deletedCol} deleted_unit_id,sm.branch_id movement_branch_id,
            s.status sale_status,s.subtotal sale_subtotal,s.total sale_total,si.id sale_item_id,si.unit_price,si.line_total,
            (SELECT MAX(x.id) FROM stock_movements x WHERE x.unit_id=iu.id) latest_movement_id,
            (SELECT COALESCE(SUM(l.line_total),0) FROM sale_items l WHERE l.sale_id=s.id AND " . inventory_active_sale_item_sql('l') . ") active_total"
        . $joins . " ORDER BY sm.created_at DESC,iu.id DESC LIMIT 25 OFFSET $offset",
        $params
    )->fetchAll();
} catch (Throwable $e) {
    $soldError = 'Sold units could not be loaded right now.';
    Security::reportException($e, 'system_admin_sold_units');
}

$soldLabel = static function (array $row): string {
    if (($row['product_type'] ?? '') === 'accessory') return trim((string)($row['product_name'] ?? '')) ?: 'Accessory';
    $name = trim(((string)($row['brand_name'] ?? '')) . ' ' . ((string)($row['model_name'] ?? '')));
    return $name !== '' ? $name : (trim((string)($row['product_name'] ?? '')) ?: 'Unit');
};
$soldSpecs = static function (array $row): string {
    $parts = [];
    foreach (['ram', 'storage', 'connectivity', 'color'] as $key) if (!empty($row[$key])) $parts[] = $row[$key];
    return implode(' • ', $parts);
};
// Mirrors the checks in actions/void_inventory_movement.php so the button is only offered when the action can succeed.
$soldReason = static function (array $row) use ($voidReady): string {
    if (!$voidReady) return 'Void is not installed yet.';
    if (empty($row['movement_id'])) return 'No sale movement is recorded for this unit.';
    if (!empty($row['deleted_unit_id']) || str_starts_with((string)$row['reference_no'], 'REMOVE-')) return 'Removed inventory history cannot be restored.';
    if ((int)$row['latest_movement_id'] !== (int)$row['movement_id']) return 'This unit has later stock activity.';
    if (empty($row['sale_status']) || $row['sale_status'] !== 'completed') return 'The original completed sale could not be matched.';
    if (empty($row['sale_item_id'])) return 'The original sale item is missing.';
    if ((int)round((float)$row['active_total'] * 100) !== (int)round((float)$row['sale_total'] * 100)
        || (int)round((float)$row['sale_subtotal'] * 100) !== (int)round((float)$row['sale_total'] * 100)) return 'This receipt has an adjusted or discounted total.';
    if (!(int)$row['branch_active']) return 'The original branch is inactive.';
    if (!(int)$row['product_active']) return 'Restore the archived product first.';
    return '';
};
$soldPageUrl = static function (int $page): string {
    $q = $_GET;
    unset($q['page'], $q['view']);
    $q['sold_page'] = $page;
    return app_url('system-admin-sold-units', $q);
};
?>
<section class="page-heading system-cleanup-heading">
    <div>
        <span class="eyebrow">SYSTEM CLEANUP</span>
        <h1>Sold Units</h1>
        <p>Find a unit recorded as sold by mistake and void the sale to return it to available stock at its original branch.</p>
    </div>
    <?php if ($voidReady): ?><button type="button" class="btn btn-outline" id="reviewInventoryVoid">Void Selected</button><?php endif; ?>
</section>

<?php if (!$voidReady): ?><div class="alert alert-error">Import <strong>database/P2_024_system_admin_inventory_void.sql</strong> into the existing POS database to enable Void.</div><?php endif; ?>
<?php if ($soldError): ?><div class="alert alert-error"><?= e($soldError) ?></div><?php endif; ?>

<section class="card table-card system-cleanup-card deleted-inventory-card" id="sold-units">
    <div class="card-header"><div><span class="section-kicker">SALES CORRECTION</span><h2>Units Marked Sold</h2><p>Only units whose latest activity is the sale, on a receipt with no discount or adjustment, can be voided here. Voiding corrects inventory and recorded sales. Review any actual payment separately.</p></div><span class="count-badge"><?= $soldCount ?></span></div>
    <form method="get" action="<?= e(app_url('system-admin-sold-units')) ?>" class="deleted-unit-filters">
        <input type="hidden" name="view" value="sold-units">
        <label class="field"><span>Search sold units</span><input type="search" name="q" value="<?= e($soldSearch) ?>" placeholder="Model, IMEI, serial or receipt"></label>
        <label class="field"><span>Branch</span><select name="branch"><option value="">All branches</option><?php foreach ($soldBranches as $b): ?><option value="<?= (int)$b['id'] ?>" <?= (int)$b['id'] === $soldBranch ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Sold from</span><input type="date" name="from" value="<?= e($soldFrom) ?>"></label>
        <label class="field"><span>Sold to</span><input type="date" name="to" value="<?= e($soldTo) ?>"></label>
        <button type="submit" class="btn btn-primary">Apply</button><a class="btn btn-ghost" href="<?= e(app_url('system-admin-sold-units')) ?>">Reset</a>
    </form>
    <?php if ($voidReady): ?><p class="deleted-unit-toolbar" id="inventoryVoidSelectionMessage" role="status"></p><?php endif; ?>
    <div class="table-wrap"><table class="data-table system-cleanup-table deleted-unit-table"><thead><tr><th>Select</th><th>Unit</th><th>Branch</th><th>Sold</th><th>Receipt</th><th>Price</th><th>Action</th></tr></thead><tbody>
    <?php foreach ($soldRows as $row):
        $label = $soldLabel($row);
        $identifier = $row['serial_no'] ?: $row['imei'] ?: 'No identifier recorded';
        $reason = $soldReason($row);
        $summary = $identifier . ' • ' . ($row['branch_name'] ?: 'Unknown branch') . ' • ' . ($row['reference_no'] ?: 'No receipt') . ' • 1 unit(s)';
    ?>
    <tr>
        <td data-label="Select"><?php if ($reason === ''): ?><label class="deleted-unit-select"><input type="checkbox" data-inventory-void value="<?= (int)$row['movement_id'] ?>" data-void-summary="<?= e($summary) ?>" aria-label="Select <?= e($identifier) ?> for void"></label><?php else: ?>—<?php endif; ?></td>
        <td data-label="Unit"><strong><?= e($label) ?></strong><?php if ($soldSpecs($row) !== ''): ?><span class="table-subtext"><?= e($soldSpecs($row)) ?></span><?php endif; ?><span class="table-subtext"><?= e($identifier) ?></span><?php if (!empty($row['imei2'])): ?><span class="table-subtext">IMEI 2: <?= e($row['imei2']) ?></span><?php endif; ?></td>
        <td data-label="Branch"><?= e($row['branch_name'] ?: 'Unknown branch') ?></td>
        <td data-label="Sold"><?php if ($row['sold_at']): ?><strong><?= e(date('M d, Y', strtotime($row['sold_at']))) ?></strong><span class="table-subtext"><?= e(date('h:i A', strtotime($row['sold_at']))) ?></span><?php else: ?>—<?php endif; ?></td>
        <td data-label="Receipt"><?= e($row['reference_no'] ?: '—') ?></td>
        <td data-label="Price"><?= $row['line_total'] !== null ? e(peso($row['line_total'])) : '—' ?></td>
        <td data-label="Action"><?php if ($reason === ''): ?><button type="button" class="btn btn-danger" data-void-row="<?= (int)$row['movement_id'] ?>" data-void-summary="<?= e($summary) ?>">Void &amp; Restore</button><?php else: ?><button type="button" class="btn btn-secondary" disabled>Void &amp; Restore</button><span class="cleanup-state-note"><?= e($reason) ?></span><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$soldRows): ?><tr><td colspan="7"><div class="empty-state small"><strong>No sold units found.</strong><span>Try another branch, date or search.</span></div></td></tr><?php endif; ?>
    </tbody></table></div>
    <nav class="deleted-unit-pagination" aria-label="Sold unit pages"><?php if ($soldPage > 1): ?><a class="btn btn-secondary" href="<?= e($soldPageUrl($soldPage - 1)) ?>">Previous</a><?php endif; ?><span>Page <?= $soldPage ?> of <?= $soldPages ?></span><?php if ($soldPage < $soldPages): ?><a class="btn btn-secondary" href="<?= e($soldPageUrl($soldPage + 1)) ?>">Next</a><?php endif; ?></nav>
</section>

<?php if ($voidReady): ?>
<div class="modal" id="inventoryVoidModal" hidden><div class="modal-backdrop" data-void-close></div><div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="inventoryVoidTitle"><form method="post" action="actions/void_inventory_movement.php" id="inventoryVoidForm">
<input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="return_to" value="sold-units"><div id="inventoryVoidIds"></div>
<div class="modal-header"><div><span class="eyebrow">SYSTEM ADMIN</span><h2 id="inventoryVoidTitle">Void Selected Records</h2></div><button type="button" class="icon-button" data-void-close aria-label="Close">×</button></div>
<div class="modal-body"><p>These exact records will be voided and their stock restored to the original branch. Other sale items remain unchanged.</p><ul id="inventoryVoidReview"></ul><label class="field"><span>Reason <b>*</b></span><textarea name="reason" minlength="3" maxlength="180" rows="3" required placeholder="Recorded as sold by mistake; units never left the branch."></textarea></label><p>This corrects inventory and recorded sales. Review any actual payment separately.</p></div>
<div class="modal-actions"><button type="button" class="btn btn-secondary" data-void-close>Cancel</button><button type="submit" class="btn btn-primary">Void &amp; Restore Selected</button></div></form></div></div>
<script src="assets/js/inventory-void.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/inventory-void.js') ?>"></script>
<?php endif; ?>
