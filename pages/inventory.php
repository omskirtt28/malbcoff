<?php
$inventoryRole = Auth::user()['role'] ?? '';
$canReceiveStock = in_array($inventoryRole, ['owner','branch_manager','inventory'], true);
$userBranchId = Auth::branchId() ?: null;
$ownerScope = Auth::isOwner() ? current_branch_scope() : null;

$brand = filter_input(INPUT_GET, 'brand', FILTER_VALIDATE_INT) ?: null;
$type = (string)($_GET['type'] ?? '');
$search = trim((string)($_GET['q'] ?? ''));
$branchFilter = filter_input(INPUT_GET, 'stock_branch', FILTER_VALIDATE_INT) ?: null;
$statusFilter = (string)($_GET['status'] ?? '');
$statusFilter = in_array($statusFilter, ['available','low'], true) ? $statusFilter : '';
$perPage = (int)($_GET['per_page'] ?? 20);
$perPage = in_array($perPage, [20,50,100], true) ? $perPage : 20;
$currentPage = max(1, (int)($_GET['p'] ?? 1));

if ($ownerScope) {
    $branchFilter = (int)$ownerScope;
}

$brands = [];
$branches = [];
$products = [];
$rows = [];

try {
    $brands = Database::query('SELECT id,name FROM brands WHERE is_active=1 ORDER BY name')->fetchAll();
    $branches = Database::query('SELECT id,name FROM branches WHERE is_active=1 ORDER BY name')->fetchAll();
    $catalogDeleteReady = (bool)Database::query("SHOW COLUMNS FROM products LIKE 'catalog_deleted_at'")->fetch();

    $conditions = [
        'p.is_active=1',
        "(p.product_type='accessory' OR (br.is_active=1 AND pm.is_active=1))",
    ];
    $params = [];
    if ($catalogDeleteReady) $conditions[] = 'p.catalog_deleted_at IS NULL';
    if ($brand) {
        $conditions[] = 'p.brand_id=:brand';
        $params['brand'] = $brand;
    }
    if (in_array($type, ['phone','tablet','accessory'], true)) {
        $conditions[] = 'p.product_type=:type';
        $params['type'] = $type;
    }
    if ($search !== '') {
        $conditions[] = '(br.name LIKE :s1 OR pm.name LIKE :s2 OR p.product_name LIKE :s3 OR p.barcode LIKE :s4 OR EXISTS (
            SELECT 1 FROM inventory_units six
            WHERE six.product_id=p.id AND (six.imei LIKE :s5 OR six.imei2 LIKE :s6 OR six.serial_no LIKE :s7)
        ))';
        for ($i=1; $i<=7; $i++) $params['s'.$i] = '%'.$search.'%';
    }

    $productSql = "SELECT p.id,p.product_type,p.brand_id,p.model_id,p.product_name,p.ram,p.storage,p.color,p.connectivity,p.barcode,
                          br.name brand_name,pm.name model_name,c.name category_name
                   FROM products p
                   LEFT JOIN brands br ON br.id=p.brand_id
                   LEFT JOIN product_models pm ON pm.id=p.model_id
                   LEFT JOIN categories c ON c.id=p.category_id
                   WHERE ".implode(' AND ', $conditions)."
                   ORDER BY COALESCE(br.name,p.product_name),pm.name,p.ram,p.storage,p.connectivity,p.color
";
    $products = Database::query($productSql, $params)->fetchAll();

    if ($products) {
        $productById = [];
        $ids = [];
        foreach ($products as $product) {
            $pid = (int)$product['id'];
            $ids[] = $pid;
            $productById[$pid] = $product;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));

        // Serialized devices: the physical unit's branch_id is the authoritative stock location.
        $unitParams = $ids;
        $unitBranchFilter = '';
        if ($ownerScope) {
            $unitBranchFilter = ' AND iu.branch_id=?';
            $unitParams[] = (int)$ownerScope;
        }
        $deviceStocks = Database::query(
            "SELECT iu.product_id,iu.branch_id,b.name branch_name,
                    COUNT(*) available_units,
                    SUM(CASE WHEN iu.condition_type='preloved' THEN 1 ELSE 0 END) preloved_units
             FROM inventory_units iu
             JOIN branches b ON b.id=iu.branch_id AND b.is_active=1
             WHERE iu.product_id IN ($marks) AND iu.status='available'{$unitBranchFilter}
             GROUP BY iu.product_id,iu.branch_id,b.name",
            $unitParams
        )->fetchAll();

        foreach ($deviceStocks as $stock) {
            $pid = (int)$stock['product_id'];
            if (!isset($productById[$pid])) continue;
            $product = $productById[$pid];
            if (($product['product_type'] ?? '') === 'accessory') continue;
            $rows[] = array_merge($product, [
                'branch_id' => (int)$stock['branch_id'],
                'branch_name' => (string)$stock['branch_name'],
                'available_units' => (int)$stock['available_units'],
                'preloved_units' => (int)$stock['preloved_units'],
            ]);
        }

        // Quantity-based accessories: the balance row's branch_id is the authoritative stock location.
        $balanceParams = $ids;
        $balanceBranchFilter = '';
        if ($ownerScope) {
            $balanceBranchFilter = ' AND ib.branch_id=?';
            $balanceParams[] = (int)$ownerScope;
        }
        $accessoryStocks = Database::query(
            "SELECT ib.product_id,ib.branch_id,b.name branch_name,ib.quantity available_units
             FROM inventory_balances ib
             JOIN branches b ON b.id=ib.branch_id AND b.is_active=1
             WHERE ib.product_id IN ($marks) AND ib.quantity>0{$balanceBranchFilter}",
            $balanceParams
        )->fetchAll();

        foreach ($accessoryStocks as $stock) {
            $pid = (int)$stock['product_id'];
            if (!isset($productById[$pid])) continue;
            $product = $productById[$pid];
            if (($product['product_type'] ?? '') !== 'accessory') continue;
            $rows[] = array_merge($product, [
                'branch_id' => (int)$stock['branch_id'],
                'branch_name' => (string)$stock['branch_name'],
                'available_units' => (int)$stock['available_units'],
                'preloved_units' => 0,
            ]);
        }

        if ($branchFilter) {
            $rows = array_values(array_filter($rows, fn(array $row): bool => (int)$row['branch_id'] === (int)$branchFilter));
        }
        if ($statusFilter !== '') {
            $rows = array_values(array_filter($rows, function(array $row) use ($statusFilter): bool {
                $isLow = (int)$row['available_units'] <= 5;
                return $statusFilter === 'low' ? $isLow : !$isLow;
            }));
        }

        usort($rows, function(array $a, array $b) use ($userBranchId) {
            // Branch users see their branch first without changing the true stock location.
            if (!Auth::isOwner() && $userBranchId) {
                $aOwn = ((int)$a['branch_id'] === (int)$userBranchId) ? 0 : 1;
                $bOwn = ((int)$b['branch_id'] === (int)$userBranchId) ? 0 : 1;
                if ($aOwn !== $bOwn) return $aOwn <=> $bOwn;
            }
            $nameA = trim((string)($a['model_name'] ?: $a['product_name']).' '.(string)$a['brand_name'].' '.(string)$a['storage'].' '.(string)$a['color']);
            $nameB = trim((string)($b['model_name'] ?: $b['product_name']).' '.(string)$b['brand_name'].' '.(string)$b['storage'].' '.(string)$b['color']);
            $cmp = strcasecmp($nameA, $nameB);
            if ($cmp !== 0) return $cmp;
            return ((int)$a['branch_id']) <=> ((int)$b['branch_id']);
        });
    }
} catch (Throwable $e) {
    $rows = [];
}

function inventory_specs(array $row): string {
    if (($row['product_type'] ?? '') === 'accessory') return $row['category_name'] ?: 'Barcode / Quantity';
    $parts = [];
    foreach (['ram','storage','connectivity','color'] as $key) {
        if (!empty($row[$key])) $parts[] = $row[$key];
    }
    return $parts ? implode(' • ', $parts) : '—';
}
function inventory_type_label(string $type): string {
    return match($type){'tablet'=>'Tablet','accessory'=>'Accessory',default=>'Phone'};
}
function inventory_page_url(array $changes = []): string {
    global $search,$brand,$type,$branchFilter,$statusFilter,$perPage,$ownerScope;
    $params = ['page' => 'inventory'];
    if ($search !== '') $params['q'] = $search;
    if ($brand) $params['brand'] = $brand;
    if ($type !== '') $params['type'] = $type;
    if ($branchFilter) $params['stock_branch'] = $branchFilter;
    if ($statusFilter !== '') $params['status'] = $statusFilter;
    if ($perPage !== 20) $params['per_page'] = $perPage;
    if (Auth::isOwner() && $ownerScope) $params['branch'] = $ownerScope;
    foreach ($changes as $key => $value) {
        if ($value === null || $value === '' || $value === false) unset($params[$key]);
        else $params[$key] = $value;
    }
    return 'index.php?' . http_build_query($params);
}

$totalRows = count($rows);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $perPage;
$visibleRows = array_slice($rows, $offset, $perPage);
$startRow = $totalRows ? $offset + 1 : 0;
$endRow = min($offset + $perPage, $totalRows);

$receiveHref = 'index.php?page=stock-in';
if (Auth::isOwner() && $ownerScope) $receiveHref .= '&branch='.(int)$ownerScope;
$resetHref = Auth::isOwner() ? owner_branch_filter_url('inventory', $ownerScope) : 'index.php?page=inventory';
?>
<section class="page-heading inventory-page-heading">
    <div>
        <span class="eyebrow">STOCK OVERVIEW</span>
        <h1>Inventory</h1>
        <p><?= Auth::isOwner()
            ? ($ownerScope ? 'View stock currently assigned to the selected branch.' : 'View available stock across all branches.')
            : 'View available stock across all branches. Your branch is highlighted.' ?></p>
    </div>
    <div class="form-action-group"><button class="btn btn-outline" type="button" data-device-scan data-scan-target="#inventoryScanSearch" data-scan-mode="auto" data-scan-label="Scan Inventory Item" data-scan-submit>Scan Search</button><?php if($canReceiveStock): ?><a class="btn btn-primary" href="<?= e($receiveHref) ?>"><?= icon('stock') ?> Receive Stock</a><?php endif; ?></div>
</section>

<form class="filter-card inventory-filter-card" method="get">
    <input type="hidden" name="page" value="inventory">
    <?php if ($ownerScope): ?><input type="hidden" name="branch" value="<?= (int)$ownerScope ?>"><?php endif; ?>
    <label class="search-box inventory-search"><?= icon('search') ?><input id="inventoryScanSearch" type="search" name="q" value="<?= e($search) ?>" placeholder="Search product, model, IMEI, serial, barcode…"></label>
    <label><span>Brand</span><select name="brand"><option value="">All Brands</option><?php foreach ($brands as $brandRow): ?><option value="<?= (int)$brandRow['id'] ?>" <?= $brand===(int)$brandRow['id']?'selected':'' ?>><?= e($brandRow['name']) ?></option><?php endforeach; ?></select></label>
    <label><span>Item Type</span><select name="type"><option value="">All Types</option><option value="phone" <?= $type==='phone'?'selected':'' ?>>Phone</option><option value="tablet" <?= $type==='tablet'?'selected':'' ?>>Tablet</option><option value="accessory" <?= $type==='accessory'?'selected':'' ?>>Accessory</option></select></label>
    <label><span>Branch</span><select name="stock_branch" <?= $ownerScope ? 'disabled' : '' ?>><option value="">All Branches</option><?php foreach ($branches as $branchRow): ?><option value="<?= (int)$branchRow['id'] ?>" <?= $branchFilter===(int)$branchRow['id']?'selected':'' ?>><?= e($branchRow['name']) ?></option><?php endforeach; ?></select><?php if($ownerScope): ?><input type="hidden" name="stock_branch" value="<?= (int)$ownerScope ?>"><?php endif; ?></label>
    <label><span>Status</span><select name="status"><option value="">All Status</option><option value="available" <?= $statusFilter==='available'?'selected':'' ?>>In Stock</option><option value="low" <?= $statusFilter==='low'?'selected':'' ?>>Low Stock</option></select></label>
    <button class="btn btn-primary inventory-apply" type="submit">Apply</button>
    <a class="btn btn-ghost inventory-reset" href="<?= e($resetHref) ?>">Reset</a>
</form>

<section class="card table-card inventory-table-card">
<div class="table-wrap inventory-table-wrap"><table class="data-table inventory-table"><colgroup><col class="col-product"><col class="col-specs"><col class="col-stock"><col class="col-location"><col class="col-status"><col class="col-action"></colgroup><thead><tr><th>Product</th><th>Specs</th><th>Available Units</th><th>Stock Location</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php if(!$visibleRows): ?>
<tr><td colspan="6"><div class="empty-state"><div class="empty-icon"><?= icon('inventory') ?></div><strong>No available stock found</strong><span>Try changing the filters or use Receive Stock when physical items arrive.</span><?php if($canReceiveStock): ?><div class="empty-actions"><a class="btn btn-primary btn-sm" href="<?= e($receiveHref) ?>">Receive Stock</a></div><?php endif; ?></div></td></tr>
<?php else: foreach($visibleRows as $row):
    $mainName = $row['product_type']==='accessory' ? (string)$row['product_name'] : (string)$row['model_name'];
    $brandName = $row['product_type']==='accessory' ? ((string)$row['category_name'] ?: 'ACCESSORY') : (string)$row['brand_name'];
    $unitModalName = $row['product_type']==='accessory'
        ? $mainName
        : trim($brandName.' '.$mainName);
    $qty = (int)$row['available_units'];
    $low = $qty <= 5;
    $preloved = (int)$row['preloved_units'];
    $typeLabel = inventory_type_label($row['product_type']);
    $isOwnBranch = !$userBranchId ? false : ((int)$row['branch_id'] === (int)$userBranchId);
?>
<tr class="<?= $isOwnBranch ? 'inventory-own-branch-row' : '' ?>">
<td>
    <div class="inventory-product-cell">
        <?php if($row['product_type']==='accessory'): ?>
            <span class="brand-logo inventory-accessory-logo"><?= icon('accessory') ?></span>
        <?php else: ?>
            <?= brand_logo_html($brandName, 'inventory-brand-logo') ?>
        <?php endif; ?>
        <div class="inventory-product-copy">
            <strong><?= e($mainName) ?></strong>
            <span><?= e($brandName) ?><b aria-hidden="true">•</b><?= e($typeLabel) ?><?= $preloved ? ' • '.number_format($preloved).' Pre-Loved' : '' ?></span>
        </div>
    </div>
</td>
<td><span class="inventory-specs-text"><?= e(inventory_specs($row)) ?></span></td>
<td><div class="inventory-stock-count"><strong><?= number_format($qty) ?></strong></div></td>
<td><div class="inventory-location-cell"><strong><?= e($row['branch_name']) ?></strong><?php if($isOwnBranch): ?><span>Your Branch</span><?php endif; ?></div></td>
<td><span class="status-pill <?= $low?'low':'available' ?>"><?= $low?'Low Stock':'In Stock' ?></span></td>
<td><button type="button" class="btn btn-outline btn-sm inventory-view-btn" data-unit-modal data-product="<?= e($unitModalName.' • '.inventory_specs($row)) ?>" data-product-id="<?= (int)$row['id'] ?>" data-branch-id="<?= (int)$row['branch_id'] ?>"><?= icon('eye') ?> View Units</button></td>
</tr>
<?php endforeach; endif; ?>
</tbody></table></div>

<?php if($totalRows > 0): ?>
<div class="inventory-table-footer">
    <span>Showing <?= number_format($startRow) ?> to <?= number_format($endRow) ?> of <?= number_format($totalRows) ?> items</span>
    <div class="inventory-pagination-controls">
        <form method="get" class="inventory-page-size">
            <input type="hidden" name="page" value="inventory">
            <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= e($search) ?>"><?php endif; ?>
            <?php if ($brand): ?><input type="hidden" name="brand" value="<?= (int)$brand ?>"><?php endif; ?>
            <?php if ($type !== ''): ?><input type="hidden" name="type" value="<?= e($type) ?>"><?php endif; ?>
            <?php if ($branchFilter): ?><input type="hidden" name="stock_branch" value="<?= (int)$branchFilter ?>"><?php endif; ?>
            <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?= e($statusFilter) ?>"><?php endif; ?>
            <?php if (Auth::isOwner() && $ownerScope): ?><input type="hidden" name="branch" value="<?= (int)$ownerScope ?>"><?php endif; ?>
            <label>Rows per page:
                <select name="per_page" onchange="this.form.submit()">
                    <?php foreach([20,50,100] as $size): ?><option value="<?= $size ?>" <?= $perPage===$size?'selected':'' ?>><?= $size ?></option><?php endforeach; ?>
                </select>
            </label>
        </form>
        <nav class="inventory-pagination" aria-label="Inventory pages">
            <a class="inventory-page-btn <?= $currentPage<=1?'disabled':'' ?>" href="<?= $currentPage>1 ? e(inventory_page_url(['p'=>$currentPage-1])) : '#' ?>" aria-label="Previous page">‹</a>
            <?php
            $pageStart = max(1, $currentPage - 2);
            $pageEnd = min($totalPages, $currentPage + 2);
            if ($pageStart > 1): ?>
                <a class="inventory-page-btn" href="<?= e(inventory_page_url(['p'=>1])) ?>">1</a>
                <?php if($pageStart > 2): ?><span class="inventory-page-ellipsis">…</span><?php endif; ?>
            <?php endif; ?>
            <?php for($pageNo=$pageStart; $pageNo<=$pageEnd; $pageNo++): ?>
                <a class="inventory-page-btn <?= $pageNo===$currentPage?'active':'' ?>" href="<?= e(inventory_page_url(['p'=>$pageNo])) ?>"><?= $pageNo ?></a>
            <?php endfor; ?>
            <?php if ($pageEnd < $totalPages): ?>
                <?php if($pageEnd < $totalPages-1): ?><span class="inventory-page-ellipsis">…</span><?php endif; ?>
                <a class="inventory-page-btn" href="<?= e(inventory_page_url(['p'=>$totalPages])) ?>"><?= $totalPages ?></a>
            <?php endif; ?>
            <a class="inventory-page-btn <?= $currentPage>=$totalPages?'disabled':'' ?>" href="<?= $currentPage<$totalPages ? e(inventory_page_url(['p'=>$currentPage+1])) : '#' ?>" aria-label="Next page">›</a>
        </nav>
    </div>
</div>
<?php endif; ?>
</section>

<div class="modal" id="unitModal" hidden><div class="modal-backdrop" data-modal-close></div><div class="modal-dialog"><div class="modal-header"><div><span class="eyebrow">AVAILABLE UNITS</span><h2 data-modal-title>Product Units</h2></div><button type="button" class="icon-button" data-modal-close>×</button></div><div class="modal-body" data-modal-body><div class="loading-state">Select a product to view units.</div></div></div></div>
