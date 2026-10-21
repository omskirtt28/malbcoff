<?php
require_once __DIR__.'/../core/InventoryDeletion.php';
$deletedUnits = [];
$unitCount = 0;
$unitPage = max(1, (int)($_GET['unit_page'] ?? 1));
$unitSearch = mb_substr(trim((string)($_GET['unit_q'] ?? '')), 0, 120);
$unitBranch = max(0, (int)($_GET['unit_branch'] ?? 0));
$unitFrom = (string)($_GET['unit_from'] ?? '');
$unitTo = (string)($_GET['unit_to'] ?? '');
foreach (['unitFrom','unitTo'] as $dateVar) {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $$dateVar);
    if (!$d || $d->format('Y-m-d') !== $$dateVar) $$dateVar = '';
}
$unitBranches = [];
$unitError = '';
try {
    InventoryDeletion::requireReady();
    $unitBranches = Database::query('SELECT id,name FROM branches ORDER BY name')->fetchAll();
    $where = ["iu.status IN ('deleted','archived')"];
    $params = [];
    if ($unitBranch) { $where[] = 'iu.branch_id=?'; $params[] = $unitBranch; }
    if ($unitFrom !== '') { $where[] = 'iu.deleted_at>=?'; $params[] = $unitFrom.' 00:00:00'; }
    if ($unitTo !== '') { $where[] = 'iu.deleted_at<?'; $params[] = date('Y-m-d', strtotime($unitTo.' +1 day')).' 00:00:00'; }
    if ($unitSearch !== '') {
        $fields = ['iu.imei','iu.imei2','iu.serial_no','iu.archived_imei','iu.archived_imei2','iu.archived_serial_no','p.product_name','pm.name','br.name'];
        $where[] = '('.implode(' OR ', array_map(static fn($f) => "$f LIKE ?", $fields)).')';
        foreach ($fields as $field) $params[] = '%'.$unitSearch.'%';
    }
    $joins = ' FROM inventory_units iu JOIN products p ON p.id=iu.product_id LEFT JOIN product_models pm ON pm.id=p.model_id LEFT JOIN brands br ON br.id=p.brand_id LEFT JOIN branches b ON b.id=iu.branch_id LEFT JOIN users du ON du.id=iu.deleted_by WHERE '.implode(' AND ', $where);
    $unitCount = (int)Database::query('SELECT COUNT(*)'.$joins, $params)->fetchColumn();
    $unitPages = max(1, (int)ceil($unitCount/25));
    $unitPage = min($unitPage, $unitPages);
    $offset = ($unitPage-1)*25;
    $deletedUnits = Database::query("SELECT iu.*,p.product_type,p.product_name,p.ram,p.storage,p.connectivity,p.color,pm.name model_name,br.name brand_name,b.name branch_name,COALESCE(iu.deleted_by_name,du.name) deleted_by_label".$joins." ORDER BY iu.deleted_at DESC,iu.id DESC LIMIT 25 OFFSET $offset", $params)->fetchAll();
    foreach ($deletedUnits as &$unit) $unit['refs'] = InventoryDeletion::references((int)$unit['id']);
    unset($unit);
} catch (Throwable $e) {
    $unitError = $e instanceof RuntimeException ? $e->getMessage() : 'Deleted inventory units could not be loaded right now.';
    Security::reportException($e, 'deleted_inventory_units');
}
$unitPageUrl = static fn(int $page): string => app_url('system-admin-deleted-records', array_merge($_GET, ['unit_page' => $page]), 'deleted-inventory-units');
?>
<section class="card table-card system-cleanup-card deleted-inventory-card" id="deleted-inventory-units">
    <div class="card-header"><div><span class="section-kicker">INVENTORY CLEANUP</span><h2>Deleted Inventory Units</h2><p>Units with no sales or voids can be deleted permanently, including units with transfers or movements. Transfer and stock history stay available. Units with sales history are kept for records, with their IMEI and serial released for receiving again.</p></div><span class="count-badge"><?= $unitCount ?></span></div>
    <?php if ($unitError): ?><div class="alert alert-error"><?= e($unitError) ?></div><?php else: ?>
    <form method="get" action="<?= e(app_url('system-admin-deleted-records')) ?>#deleted-inventory-units" class="deleted-unit-filters">
        <label class="field"><span>Search inventory units</span><input type="search" name="unit_q" value="<?= e($unitSearch) ?>" placeholder="Model, IMEI or serial"></label>
        <label class="field"><span>Branch</span><select name="unit_branch"><option value="">All branches</option><?php foreach ($unitBranches as $b): ?><option value="<?= (int)$b['id'] ?>" <?= (int)$b['id']===$unitBranch?'selected':'' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Deleted from</span><input type="date" name="unit_from" value="<?= e($unitFrom) ?>"></label>
        <label class="field"><span>Deleted to</span><input type="date" name="unit_to" value="<?= e($unitTo) ?>"></label>
        <button type="submit" class="btn btn-primary">Apply</button><a class="btn btn-ghost" href="<?= e(app_url('system-admin-deleted-records')) ?>#deleted-inventory-units">Reset</a>
    </form>
    <div class="deleted-unit-toolbar"><label><input type="checkbox" data-unit-select-all> Select all on this page</label><button type="button" class="btn btn-danger" data-purge-selected disabled>Delete selected permanently</button><span data-unit-selected-count role="status">0 selected</span><button type="button" class="btn btn-danger" data-purge-all-no-sales>Delete all with no sales</button><span data-unit-preview-status role="status"></span></div>
    <div class="table-wrap"><table class="data-table system-cleanup-table deleted-unit-table"><thead><tr><th>Select</th><th>Unit</th><th>Branch</th><th>Deleted by / at</th><th>Database References</th><th>Action</th></tr></thead><tbody>
    <?php foreach ($deletedUnits as $unit):
        $label = cleanup_record_label($unit);
        $identifier = $unit['serial_no'] ?: $unit['imei'] ?: $unit['archived_serial_no'] ?: $unit['archived_imei'] ?: 'No identifier recorded';
        $archived = $unit['status']==='archived';
        $hasSales = $unit['refs']['sales'] > 0 || $unit['refs']['voids'] > 0;
    ?>
    <tr>
        <td data-label="Select"><?php if (!$hasSales): ?><label class="deleted-unit-select"><input type="checkbox" data-unit-select value="<?= (int)$unit['id'] ?>" data-summary="<?= e($label.' · '.$identifier.' · '.$unit['branch_name']) ?>" aria-label="Select <?= e($identifier) ?>"></label><?php else: ?>—<?php endif; ?></td>
        <td data-label="Unit"><strong><?= e($label) ?></strong><span class="table-subtext"><?= e($identifier) ?></span><?php if ($unit['imei2'] ?: $unit['archived_imei2']): ?><span class="table-subtext">IMEI 2: <?= e($unit['imei2'] ?: $unit['archived_imei2']) ?></span><?php endif; ?><span class="table-subtext">Unit #<?= (int)$unit['id'] ?></span></td>
        <td data-label="Branch"><?= e($unit['branch_name'] ?: 'Unknown branch') ?></td>
        <td data-label="Deleted by / at"><strong><?= e($unit['deleted_by_label'] ?: 'Unknown (legacy removal)') ?></strong><span class="table-subtext"><?= e($unit['deleted_at'] ?: 'Unknown') ?></span></td>
        <td data-label="Database References"><div class="cleanup-reference-grid"><?php foreach ($unit['refs'] as $key => $count): ?><span><?= e(ucfirst($key)) ?> <b><?= $count ?></b></span><?php endforeach; ?></div></td>
        <td data-label="Action"><?php if ($hasSales): ?><?php if ($archived): ?><span class="cleanup-state removed">Archived (history kept)</span><span class="table-subtext">This unit has sales history, so it is kept for records. Its IMEI can be used again.</span><?php else: ?><button type="button" class="btn btn-secondary" data-purge-unit="<?= (int)$unit['id'] ?>" data-summary="<?= e($label.' · '.$identifier) ?>">Release IMEI and keep records</button><?php endif; ?><?php else: ?><button type="button" class="btn btn-danger" data-purge-unit="<?= (int)$unit['id'] ?>">Delete permanently</button><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$deletedUnits): ?><tr><td colspan="6"><div class="empty-state small"><strong>No deleted inventory units found.</strong><span>Try another branch, date or search.</span></div></td></tr><?php endif; ?>
    </tbody></table></div>
    <nav class="deleted-unit-pagination" aria-label="Deleted inventory pages"><?php if ($unitPage>1): ?><a class="btn btn-secondary" href="<?= e($unitPageUrl($unitPage-1)) ?>">Previous</a><?php endif; ?><span>Page <?= $unitPage ?> of <?= $unitPages ?></span><?php if ($unitPage<$unitPages): ?><a class="btn btn-secondary" href="<?= e($unitPageUrl($unitPage+1)) ?>">Next</a><?php endif; ?></nav>
    <dialog class="unit-purge-dialog" aria-labelledby="unit-purge-title" data-unit-purge-dialog>
        <form data-unit-purge-form data-csrf="<?= e(Csrf::token()) ?>">
            <h2 id="unit-purge-title">Confirm inventory cleanup</h2><p data-purge-count></p><p>Units with no sales or voids will be deleted permanently. Transfer and stock history will be kept with a “Unit deleted” label. Units with sales history stay archived. IMEI and serial can be used again. This cannot be undone.</p>
            <ul data-purge-review></ul><label class="field" data-purge-type-label><span>Type DELETE to confirm</span><input data-purge-type autocomplete="off" spellcheck="false"></label>
            <div data-purge-status></div><div class="modal-actions"><button class="btn btn-secondary" type="button" data-purge-cancel>Cancel</button><button class="btn btn-danger" type="submit" data-purge-confirm>Delete permanently</button></div>
        </form>
    </dialog>
    <script src="assets/js/deleted-inventory-units.js?v=<?= (int)filemtime(__DIR__.'/../assets/js/deleted-inventory-units.js') ?>"></script>
    <?php endif; ?>
</section>
