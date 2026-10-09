<?php
$inventoryRole = Auth::user()['role'] ?? '';
$canReceiveStock = in_array($inventoryRole, ['owner','branch_manager','inventory'], true);
$canForwardStock = in_array($inventoryRole, ['branch_manager','inventory'], true) && (Auth::branchId() ?: 0) > 0;
$canSystemAdjustInventory = Auth::actorIsSystemAdmin();
$canBranchAdjustInventory = $inventoryRole === 'branch_manager' && (Auth::branchId() ?: 0) > 0;
$canAdjustInventory = $canSystemAdjustInventory || $canBranchAdjustInventory;
$canDeleteDeviceInventory = $canAdjustInventory;
$userBranchId = Auth::branchId() ?: null;
$ownerScope = Auth::isOwner() ? current_branch_scope() : null;

// Repair legacy duplicate product ids before building stock rows.
variant_repair_live_device_duplicates();

$inventoryView = (string)($_GET['view'] ?? 'devices');
$inventoryView = $inventoryView === 'accessories' ? 'accessories' : 'devices';
$brand = filter_input(INPUT_GET, 'brand', FILTER_VALIDATE_INT) ?: null;
$category = filter_input(INPUT_GET, 'category', FILTER_VALIDATE_INT) ?: null;
$type = (string)($_GET['type'] ?? '');
$type = $inventoryView === 'devices' && in_array($type, ['phone','tablet'], true) ? $type : '';
$search = trim((string)($_GET['q'] ?? ''));
$branchFilter = filter_input(INPUT_GET, 'stock_branch', FILTER_VALIDATE_INT) ?: null;
$statusFilter = (string)($_GET['status'] ?? '');
$statusFilter = in_array($statusFilter, ['available','low'], true) ? $statusFilter : '';
$dateFrom = inventory_valid_date_filter((string)($_GET['date_from'] ?? ''));
$dateTo = inventory_valid_date_filter((string)($_GET['date_to'] ?? ''));
$perPage = (int)($_GET['per_page'] ?? 20);
$perPage = in_array($perPage, [20,50,100], true) ? $perPage : 20;
$currentPage = max(1, (int)($_GET['p'] ?? 1));

if ($ownerScope) {
    $branchFilter = (int)$ownerScope;
}

$brands = [];
$categories = [];
$branches = [];
$products = [];
$rows = [];

try {
    $brands = Database::query('SELECT id,name FROM brands WHERE is_active=1 ORDER BY name')->fetchAll();
    $categories = Database::query('SELECT id,name FROM categories WHERE is_active=1 ORDER BY name')->fetchAll();
    $branches = Database::query('SELECT id,name FROM branches WHERE is_active=1 ORDER BY name')->fetchAll();
    $catalogDeleteReady = (bool)Database::query("SHOW COLUMNS FROM products LIKE 'catalog_deleted_at'")->fetch();

    $conditions = [
        'p.is_active=1',
        $inventoryView === 'accessories'
            ? "p.product_type='accessory'"
            : "p.product_type IN ('phone','tablet') AND br.is_active=1 AND pm.is_active=1",
    ];
    $params = [];
    if ($catalogDeleteReady) $conditions[] = 'p.catalog_deleted_at IS NULL';
    if ($brand) {
        $conditions[] = 'p.brand_id=:brand';
        $params['brand'] = $brand;
    }
    if ($inventoryView === 'devices' && in_array($type, ['phone','tablet'], true)) {
        $conditions[] = 'p.product_type=:type';
        $params['type'] = $type;
    }
    if ($inventoryView === 'accessories' && $category) {
        $conditions[] = 'p.category_id=:category';
        $params['category'] = $category;
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
                    SUM(CASE WHEN iu.condition_type='preloved' THEN 1 ELSE 0 END) preloved_units,
                    MAX(COALESCE((
                        SELECT MAX(sm_entry.created_at)
                        FROM stock_movements sm_entry
                        WHERE sm_entry.unit_id=iu.id
                          AND sm_entry.branch_id=iu.branch_id
                          AND sm_entry.movement_type IN ('stock_in','transfer_in')
                          AND sm_entry.quantity>0
                    ), iu.created_at)) last_stock_in
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
                'last_stock_in' => $stock['last_stock_in'] ?: null,
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
            "SELECT ib.product_id,ib.branch_id,b.name branch_name,ib.quantity available_units,
                    (SELECT MAX(sm_entry.created_at)
                     FROM stock_movements sm_entry
                     WHERE sm_entry.product_id=ib.product_id
                       AND sm_entry.branch_id=ib.branch_id
                       AND sm_entry.unit_id IS NULL
                       AND sm_entry.movement_type IN ('stock_in','transfer_in')
                       AND sm_entry.quantity>0) last_stock_in
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
                'last_stock_in' => $stock['last_stock_in'] ?: null,
            ]);
        }

        if ($branchFilter) {
            $rows = array_values(array_filter($rows, fn(array $row): bool => (int)$row['branch_id'] === (int)$branchFilter));
        }
        if ($dateFrom !== '' || $dateTo !== '') {
            $rows = array_values(array_filter($rows, function(array $row) use ($dateFrom, $dateTo): bool {
                $stockedAt = trim((string)($row['last_stock_in'] ?? ''));
                if ($stockedAt === '') return false;
                $stockDate = substr($stockedAt, 0, 10);
                if ($dateFrom !== '' && $stockDate < $dateFrom) return false;
                if ($dateTo !== '' && $stockDate > $dateTo) return false;
                return true;
            }));
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

function inventory_valid_date_filter(string $value): string {
    $value = trim($value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return '';
    [$year,$month,$day] = array_map('intval', explode('-', $value));
    return checkdate($month, $day, $year) ? $value : '';
}
function inventory_stock_date_parts(?string $value): array {
    $value = trim((string)$value);
    if ($value === '') return ['date'=>'—','time'=>'No stock-in record'];
    $timestamp = strtotime($value);
    if ($timestamp === false) return ['date'=>'—','time'=>'No stock-in record'];
    return ['date'=>date('M d, Y', $timestamp), 'time'=>date('h:i A', $timestamp)];
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
    global $inventoryView,$search,$brand,$category,$type,$branchFilter,$statusFilter,$dateFrom,$dateTo,$perPage,$ownerScope;
    $params = ['page' => 'inventory', 'view' => $inventoryView];
    if ($search !== '') $params['q'] = $search;
    if ($brand) $params['brand'] = $brand;
    if ($inventoryView === 'accessories' && $category) $params['category'] = $category;
    if ($inventoryView === 'devices' && $type !== '') $params['type'] = $type;
    if ($branchFilter) $params['stock_branch'] = $branchFilter;
    if ($statusFilter !== '') $params['status'] = $statusFilter;
    if ($dateFrom !== '') $params['date_from'] = $dateFrom;
    if ($dateTo !== '') $params['date_to'] = $dateTo;
    if ($perPage !== 20) $params['per_page'] = $perPage;
    if (Auth::isOwner() && $ownerScope) $params['branch'] = $ownerScope;
    foreach ($changes as $key => $value) {
        if ($value === null || $value === '' || $value === false) unset($params[$key]);
        else $params[$key] = $value;
    }
    unset($params['page']);
    return app_url('inventory', $params);
}

$totalRows = count($rows);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $perPage;
$visibleRows = array_slice($rows, $offset, $perPage);
$startRow = $totalRows ? $offset + 1 : 0;
$endRow = min($offset + $perPage, $totalRows);

$receiveHref = app_url('stock-in', Auth::isOwner() && $ownerScope ? ['branch' => (int)$ownerScope] : []);
$resetParams = ['view' => $inventoryView];
if (Auth::isOwner() && $ownerScope) $resetParams['branch'] = (int)$ownerScope;
$resetHref = app_url('inventory', $resetParams);
?>
<section class="page-heading inventory-page-heading">
    <div>
        <span class="eyebrow">STOCK OVERVIEW</span>
        <h1>Inventory</h1>
        <p><?= Auth::isOwner()
            ? ($ownerScope ? 'View stock currently assigned to the selected branch.' : 'View available stock across all branches.')
            : 'View available stock and stock-in dates across all branches. Your branch is highlighted.' ?></p>
    </div>
    <div class="form-action-group"><?php if($canForwardStock): ?><button class="btn btn-outline" type="button" data-device-scan data-scan-target="#forwardInventoryScanValue" data-scan-mode="auto" data-scan-label="Scan IMEI / Serial to Forward">Scan to Forward</button><input id="forwardInventoryScanValue" type="hidden"><span id="forwardInventoryScanStatus" role="status" aria-live="polite"></span><?php endif; ?><button class="btn btn-outline" type="button" data-device-scan data-scan-target="#inventoryScanSearch" data-scan-mode="auto" data-scan-label="Scan Inventory Item" data-scan-submit>Scan Search</button><?php if($canReceiveStock): ?><a class="btn btn-primary" href="<?= e($receiveHref) ?>"><?= icon('stock') ?> Receive Stock</a><?php endif; ?></div>
</section>

<nav class="inventory-view-tabs" aria-label="Inventory view">
    <a class="inventory-view-tab <?= $inventoryView==='devices'?'active':'' ?>" href="<?= e(inventory_page_url(['view'=>'devices','type'=>null,'category'=>null,'p'=>1])) ?>">Devices</a>
    <a class="inventory-view-tab <?= $inventoryView==='accessories'?'active':'' ?>" href="<?= e(inventory_page_url(['view'=>'accessories','type'=>null,'category'=>null,'p'=>1])) ?>">Accessories</a>
</nav>

<form class="filter-card inventory-filter-card" method="get">
    <input type="hidden" name="view" value="<?= e($inventoryView) ?>">
    <?php if ($ownerScope): ?><input type="hidden" name="branch" value="<?= (int)$ownerScope ?>"><?php endif; ?>
    <label class="search-box inventory-search"><?= icon('search') ?><input id="inventoryScanSearch" type="search" name="q" value="<?= e($search) ?>" placeholder="Search product, model, IMEI, serial, barcode…"></label>
    <label><span>Brand</span><select name="brand"><option value="">All Brands</option><?php foreach ($brands as $brandRow): ?><option value="<?= (int)$brandRow['id'] ?>" <?= $brand===(int)$brandRow['id']?'selected':'' ?>><?= e($brandRow['name']) ?></option><?php endforeach; ?></select></label>
    <?php if($inventoryView==='accessories'): ?>
        <label><span>Category</span><select name="category"><option value="">All Categories</option><?php foreach ($categories as $categoryRow): ?><option value="<?= (int)$categoryRow['id'] ?>" <?= $category===(int)$categoryRow['id']?'selected':'' ?>><?= e($categoryRow['name']) ?></option><?php endforeach; ?></select></label>
    <?php else: ?>
        <label><span>Item Type</span><select name="type"><option value="">All Types</option><option value="phone" <?= $type==='phone'?'selected':'' ?>>Phone</option><option value="tablet" <?= $type==='tablet'?'selected':'' ?>>Tablet</option></select></label>
    <?php endif; ?>
    <label><span>Branch</span><select name="stock_branch" <?= $ownerScope ? 'disabled' : '' ?>><option value="">All Branches</option><?php foreach ($branches as $branchRow): ?><option value="<?= (int)$branchRow['id'] ?>" <?= $branchFilter===(int)$branchRow['id']?'selected':'' ?>><?= e($branchRow['name']) ?></option><?php endforeach; ?></select><?php if($ownerScope): ?><input type="hidden" name="stock_branch" value="<?= (int)$ownerScope ?>"><?php endif; ?></label>
    <label><span>Status</span><select name="status"><option value="">All Status</option><option value="available" <?= $statusFilter==='available'?'selected':'' ?>>In Stock</option><option value="low" <?= $statusFilter==='low'?'selected':'' ?>>Low Stock</option></select></label>
    <label class="inventory-date-filter"><span>Date From</span><input type="date" name="date_from" value="<?= e($dateFrom) ?>" max="<?= e($dateTo ?: date('Y-m-d')) ?>"></label>
    <label class="inventory-date-filter"><span>Date To</span><input type="date" name="date_to" value="<?= e($dateTo) ?>" min="<?= e($dateFrom) ?>" max="<?= e(date('Y-m-d')) ?>"></label>
    <button class="btn btn-primary inventory-apply" type="submit">Apply</button>
    <a class="btn btn-ghost inventory-reset" href="<?= e($resetHref) ?>">Reset</a>
</form>

<section class="card table-card inventory-table-card">
<div class="table-wrap inventory-table-wrap">
<table class="data-table inventory-table <?= $inventoryView==='accessories'?'inventory-table-accessories':'inventory-table-devices' ?>">
<?php if($inventoryView==='accessories'): ?>
    <colgroup><col class="col-product"><col class="col-category"><col class="col-identifier"><col class="col-stock"><col class="col-location"><col class="col-date"><col class="col-status"><col class="col-action"></colgroup>
    <thead><tr><th>Product</th><th>Category</th><th>Barcode / Serial</th><th>Available Qty</th><th>Stock Location</th><th>Last Stock In</th><th>Status</th><th>Action</th></tr></thead>
<?php else: ?>
    <colgroup><col class="col-product"><col class="col-specs"><col class="col-stock"><col class="col-location"><col class="col-date"><col class="col-status"><col class="col-action"></colgroup>
    <thead><tr><th>Product</th><th>Specs</th><th>Available Units</th><th>Stock Location</th><th>Last Stock In</th><th>Status</th><th>Action</th></tr></thead>
<?php endif; ?>
<tbody>
<?php if(!$visibleRows): ?>
<tr><td colspan="<?= $inventoryView==='accessories'?8:7 ?>"><div class="empty-state"><div class="empty-icon"><?= icon('inventory') ?></div><strong>No available <?= $inventoryView==='accessories'?'accessory':'device' ?> stock found</strong><span>Try changing the filters or use Receive Stock when physical items arrive.</span><?php if($canReceiveStock): ?><div class="empty-actions"><a class="btn btn-primary btn-sm" href="<?= e($receiveHref) ?>">Receive Stock</a></div><?php endif; ?></div></td></tr>
<?php else: foreach($visibleRows as $row):
    $mainName = $row['product_type']==='accessory' ? (string)$row['product_name'] : (string)$row['model_name'];
    $brandName = $row['product_type']==='accessory' ? ((string)$row['brand_name'] ?: ((string)$row['category_name'] ?: 'ACCESSORY')) : (string)$row['brand_name'];
    $unitModalName = $row['product_type']==='accessory' ? $mainName : trim($brandName.' '.$mainName);
    $qty = (int)$row['available_units'];
    $low = $qty <= 5;
    $preloved = (int)$row['preloved_units'];
    $typeLabel = inventory_type_label($row['product_type']);
    $isOwnBranch = !$userBranchId ? false : ((int)$row['branch_id'] === (int)$userBranchId);
    $canAdjustThisRow = $canAdjustInventory && (Auth::isSystemAdmin() || $isOwnBranch) && in_array((string)$row['product_type'], ['phone','tablet','accessory'], true);
    $canDeleteThisRow = $canDeleteDeviceInventory && (Auth::isSystemAdmin() || $isOwnBranch) && in_array((string)$row['product_type'], ['phone','tablet'], true);
    $stockDateParts = inventory_stock_date_parts($row['last_stock_in'] ?? null);
    $statusLabel = $low ? 'Low Stock' : 'In Stock';
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
<?php if($inventoryView==='accessories'): ?>
    <td><span class="inventory-specs-text"><?= e((string)($row['category_name'] ?: 'Accessory')) ?></span></td>
    <td><span class="inventory-identifier-text"><?= e(trim((string)($row['barcode'] ?? '')) ?: '—') ?></span></td>
<?php else: ?>
    <td><span class="inventory-specs-text"><?= e(inventory_specs($row)) ?></span></td>
<?php endif; ?>
<td><div class="inventory-stock-count"><strong><?= number_format($qty) ?></strong></div></td>
<td><div class="inventory-location-cell"><strong><?= e($row['branch_name']) ?></strong><?php if($isOwnBranch): ?><span>Your Branch</span><?php endif; ?></div></td>
<td><div class="inventory-date-cell"><strong><?= e($stockDateParts['date']) ?></strong><span><?= e($stockDateParts['time']) ?></span></div></td>
<td><span class="status-pill <?= $low?'low':'available' ?>"><?= e($statusLabel) ?></span></td>
<td>
    <div class="inventory-row-actions <?= $row['product_type']==='accessory'?'inventory-row-actions-accessory':'inventory-row-actions-device' ?>">
        <?php if(Auth::isOwner() || $isOwnBranch): ?>
            <?php if($row['product_type']==='accessory'): ?>
                <button type="button" class="btn btn-outline btn-sm inventory-view-btn" data-accessory-stock-modal
                    data-product="<?= e($mainName) ?>"
                    data-category="<?= e((string)($row['category_name'] ?: 'Accessory')) ?>"
                    data-barcode="<?= e(trim((string)($row['barcode'] ?? '')) ?: '—') ?>"
                    data-available="<?= (int)$qty ?>"
                    data-branch="<?= e($row['branch_name']) ?>"
                    data-last-stock-date="<?= e($stockDateParts['date']) ?>"
                    data-last-stock-time="<?= e($stockDateParts['time']) ?>"
                    data-status="<?= e($statusLabel) ?>"><?= icon('eye') ?> View Stock</button>
            <?php else: ?>
                <button type="button" class="btn btn-outline btn-sm inventory-view-btn" data-unit-modal data-product="<?= e($unitModalName.' • '.inventory_specs($row)) ?>" data-product-id="<?= (int)$row['id'] ?>" data-branch-id="<?= (int)$row['branch_id'] ?>"><?= icon('eye') ?> View Units</button>
            <?php endif; ?>
        <?php else: ?><span class="table-subtext">Summary only</span><?php endif; ?>
        <?php if($canAdjustThisRow): ?><button type="button" class="btn btn-outline btn-sm inventory-adjust-btn" data-system-stock-adjust data-product="<?= e($unitModalName.' • '.inventory_specs($row)) ?>" data-product-id="<?= (int)$row['id'] ?>" data-product-type="<?= e((string)$row['product_type']) ?>" data-branch-id="<?= (int)$row['branch_id'] ?>" data-branch="<?= e($row['branch_name']) ?>" data-available="<?= (int)$qty ?>">Adjust</button><?php endif; ?>
        <?php if($canDeleteThisRow): ?><button type="button" class="btn btn-danger-outline btn-sm inventory-delete-btn" data-delete-device-inventory data-product="<?= e($unitModalName.' • '.inventory_specs($row)) ?>" data-product-id="<?= (int)$row['id'] ?>" data-branch-id="<?= (int)$row['branch_id'] ?>" data-branch="<?= e($row['branch_name']) ?>" data-available="<?= (int)$qty ?>">Delete</button><?php endif; ?>
        <?php if($canDeleteDeviceInventory && (Auth::isSystemAdmin() || $isOwnBranch) && $row['product_type']==='accessory' && $qty>0): ?><button type="button" class="btn btn-danger-outline btn-sm inventory-delete-btn" data-delete-accessory-inventory data-product="<?= e($unitModalName) ?>" data-product-id="<?= (int)$row['id'] ?>" data-branch-id="<?= (int)$row['branch_id'] ?>" data-branch="<?= e($row['branch_name']) ?>" data-available="<?= (int)$qty ?>">Delete</button><?php endif; ?>
        <?php if($canForwardStock && $isOwnBranch): ?><button type="button" class="btn btn-primary btn-sm inventory-forward-btn" data-forward-inventory data-product="<?= e($unitModalName.' • '.inventory_specs($row)) ?>" data-product-name="<?= e($mainName) ?>" data-brand="<?= e($brandName) ?>" data-model="<?= e($row['product_type']==='accessory' ? $mainName : (string)$row['model_name']) ?>" data-specs="<?= e(inventory_specs($row)) ?>" data-product-id="<?= (int)$row['id'] ?>" data-product-type="<?= e($row['product_type']) ?>" data-source-branch-id="<?= (int)$row['branch_id'] ?>" data-source-branch="<?= e($row['branch_name']) ?>" data-available="<?= (int)$qty ?>">Forward</button><?php endif; ?>
    </div>
</td>
</tr>
<?php endforeach; endif; ?>
</tbody></table></div>

<?php if($totalRows > 0): ?>
<div class="inventory-table-footer">
    <span>Showing <?= number_format($startRow) ?> to <?= number_format($endRow) ?> of <?= number_format($totalRows) ?> items</span>
    <div class="inventory-pagination-controls">
        <form method="get" class="inventory-page-size">
            <input type="hidden" name="view" value="<?= e($inventoryView) ?>">
            <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= e($search) ?>"><?php endif; ?>
            <?php if ($brand): ?><input type="hidden" name="brand" value="<?= (int)$brand ?>"><?php endif; ?>
            <?php if ($inventoryView==='accessories' && $category): ?><input type="hidden" name="category" value="<?= (int)$category ?>"><?php endif; ?>
            <?php if ($inventoryView==='devices' && $type !== ''): ?><input type="hidden" name="type" value="<?= e($type) ?>"><?php endif; ?>
            <?php if ($branchFilter): ?><input type="hidden" name="stock_branch" value="<?= (int)$branchFilter ?>"><?php endif; ?>
            <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?= e($statusFilter) ?>"><?php endif; ?>
            <?php if ($dateFrom !== ''): ?><input type="hidden" name="date_from" value="<?= e($dateFrom) ?>"><?php endif; ?>
            <?php if ($dateTo !== ''): ?><input type="hidden" name="date_to" value="<?= e($dateTo) ?>"><?php endif; ?>
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



<?php if($canForwardStock): ?>
<div class="modal forward-inventory-modal" id="forwardInventoryModal" hidden>
    <div class="modal-backdrop" data-forward-close></div>
    <div class="modal-dialog forward-inventory-dialog" role="dialog" aria-modal="true" aria-labelledby="forwardInventoryTitle">
        <form id="forwardInventoryForm" novalidate>
            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="product_id" value="">

            <div class="modal-header forward-modal-header">
                <div>
                    <span class="eyebrow">BRANCH TRANSFER</span>
                    <h2 id="forwardInventoryTitle">Forward Inventory</h2>
                </div>
                <button type="button" class="icon-button forward-close-button" data-forward-close aria-label="Close">×</button>
            </div>

            <div class="modal-body forward-modal-body">
                <div class="forward-product-summary">
                    <div class="forward-product-summary-main">
                        <strong data-forward-product-name>—</strong>
                        <span data-forward-product-specs>—</span>
                    </div>
                    <div class="forward-product-source">Current Location: <b data-forward-source>—</b></div>
                </div>

                <div class="forward-grid">
                    <label class="field forward-field" data-forward-destination-field>
                        <span>Forward To <b>*</b></span>
                        <select name="destination_branch_id" required>
                            <option value="">Select destination branch</option>
                            <?php foreach($branches as $branchRow): if((int)$branchRow['id']===(int)$userBranchId) continue; ?>
                                <option value="<?= (int)$branchRow['id'] ?>"><?= e($branchRow['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="forward-field-error" data-forward-destination-error hidden></small>
                    </label>

                    <label class="field forward-field" data-forward-quantity-field>
                        <span>Quantity <b>*</b></span>
                        <input type="number" name="quantity" min="1" value="1" inputmode="numeric">
                        <small class="forward-helper" data-forward-available></small>
                        <small class="forward-field-error" data-forward-quantity-error hidden></small>
                    </label>
                </div>

                <div class="forward-units-section" data-forward-units-wrap hidden>
                    <div class="forward-scan-tools">
                        <label class="field"><span>Find by IMEI / Serial Number</span><input id="forwardIdentifierSearch" type="text" maxlength="120" autocomplete="off" placeholder="Scan or enter IMEI 1, IMEI 2 or serial"></label>
                        <div class="forward-scan-buttons"><button type="button" class="btn btn-outline" data-device-scan data-scan-target="#forwardIdentifierSearch" data-scan-mode="auto" data-scan-label="Scan Unit to Forward">Scan IMEI / Serial</button><button type="button" class="btn btn-outline" data-forward-find>Find Unit</button><button type="button" class="btn btn-ghost" data-forward-show-all>Show All Units</button></div>
                        <small data-forward-scan-status role="status" aria-live="polite">Scan to find the matching variant and select its exact unit.</small>
                    </div>
                    <div class="forward-unit-heading">
                        <div>
                            <strong>Select Unit(s) <b>*</b></strong>
                            <span>Choose the exact IMEI/serial units to move.</span>
                        </div>
                        <button type="button" class="btn btn-ghost btn-sm forward-select-all" data-forward-select-all>Select All</button>
                    </div>
                    <div class="forward-unit-list" data-forward-units>
                        <div class="loading-state">Loading available units…</div>
                    </div>
                    <small class="forward-field-error forward-unit-error" data-forward-units-error hidden></small>
                </div>

                <label class="field forward-notes">
                    <span>Notes <small>(Optional)</small></span>
                    <textarea name="notes" maxlength="180" rows="2" placeholder="Example: Initial allocation for Branch 2"></textarea>
                </label>

                <div class="alert alert-error forward-general-error" data-forward-error hidden></div>
            </div>

            <div class="modal-actions forward-modal-actions">
                <button type="button" class="btn btn-secondary" data-forward-close>Cancel</button>
                <button type="submit" class="btn btn-primary" data-forward-submit>Forward Inventory</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<style>
.forward-scan-tools{display:grid;gap:9px;padding:12px;margin-bottom:14px;background:#f7faff;border:1px solid #dce8fa;border-radius:12px}
.forward-scan-tools .field{margin:0;min-width:0}.forward-scan-tools input{width:100%;min-width:0}
.forward-scan-buttons{display:flex;gap:8px;flex-wrap:wrap}.forward-scan-tools small{color:#667085;overflow-wrap:anywhere}
.forward-scan-tools small.is-error,#forwardInventoryScanStatus.is-error{color:#b42318}
#forwardInventoryScanStatus{font-size:12px;max-width:320px;overflow-wrap:anywhere}
.forward-unit-option.forward-scan-match{outline:2px solid #1670ea;background:#eff6ff}
.forward-unit-option[hidden]{display:none!important}
.shared-device-scanner{z-index:1700}
@media(max-width:600px){.forward-scan-buttons .btn{flex:1 1 130px;white-space:normal;min-height:44px}}
</style>

<?php if($inventoryView==='accessories'): ?>
<div class="modal" id="accessoryStockModal" hidden>
    <div class="modal-backdrop" data-accessory-stock-close></div>
    <div class="modal-dialog accessory-stock-dialog" role="dialog" aria-modal="true" aria-labelledby="accessoryStockTitle">
        <div class="modal-header">
            <div><span class="eyebrow">ACCESSORY STOCK</span><h2 id="accessoryStockTitle" data-accessory-stock-title>Accessory</h2><p class="modal-subtitle">Current quantity and product identifier for this branch.</p></div>
            <button type="button" class="icon-button" data-accessory-stock-close aria-label="Close">×</button>
        </div>
        <div class="modal-body">
            <div class="accessory-stock-summary-grid">
                <div><span>Category</span><strong data-accessory-stock-category>—</strong></div>
                <div><span>Barcode / Serial</span><strong data-accessory-stock-barcode>—</strong></div>
                <div><span>Available Quantity</span><strong data-accessory-stock-available>0</strong></div>
                <div><span>Stock Location</span><strong data-accessory-stock-branch>—</strong></div>
                <div><span>Last Stock In</span><strong data-accessory-stock-last>—</strong></div>
                <div><span>Status</span><strong data-accessory-stock-status>—</strong></div>
            </div>
        </div>
        <div class="modal-actions"><button class="btn btn-secondary" type="button" data-accessory-stock-close>Close</button></div>
    </div>
</div>
<script>
(() => {
  const modal=document.getElementById('accessoryStockModal');
  if(!modal)return;
  const q=(selector)=>modal.querySelector(selector);
  const open=(button)=>{
    q('[data-accessory-stock-title]').textContent=button.dataset.product||'Accessory';
    q('[data-accessory-stock-category]').textContent=button.dataset.category||'Accessory';
    q('[data-accessory-stock-barcode]').textContent=button.dataset.barcode||'—';
    q('[data-accessory-stock-available]').textContent=Number(button.dataset.available||0).toLocaleString();
    q('[data-accessory-stock-branch]').textContent=button.dataset.branch||'—';
    q('[data-accessory-stock-last]').textContent=[button.dataset.lastStockDate,button.dataset.lastStockTime].filter(Boolean).join(' • ')||'—';
    q('[data-accessory-stock-status]').textContent=button.dataset.status||'—';
    modal.hidden=false;
    document.body.classList.add('modal-open');
  };
  const close=()=>{modal.hidden=true;document.body.classList.remove('modal-open');};
  document.querySelectorAll('[data-accessory-stock-modal]').forEach(button=>button.addEventListener('click',()=>open(button)));
  modal.querySelectorAll('[data-accessory-stock-close]').forEach(button=>button.addEventListener('click',close));
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!modal.hidden)close();});
})();
</script>
<?php endif; ?>

<div class="modal" id="unitModal" hidden><div class="modal-backdrop" data-modal-close></div><div class="modal-dialog inventory-unit-modal-dialog"><div class="modal-header"><div><span class="eyebrow">UNIT DETAILS</span><h2 data-modal-title>Product Units</h2></div><button type="button" class="icon-button" data-modal-close>×</button></div><div class="modal-body" data-modal-body><div class="loading-state">Select a product to view units.</div></div></div></div>

<?php if ($canAdjustInventory): ?>
<div class="modal" id="systemStockAdjustModal" hidden>
    <div class="modal-backdrop" data-system-adjust-close></div>
    <div class="modal-dialog stock-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="systemAdjustTitle">
        <div class="modal-header">
            <div><span class="eyebrow">STOCK CONTROL</span><h2 id="systemAdjustTitle">Stock Adjustment</h2><p class="modal-subtitle" data-system-adjust-subtitle>Correct available stock only after reviewing the change.</p></div>
            <button type="button" class="icon-button" data-system-adjust-close aria-label="Close">×</button>
        </div>
        <div class="modal-body">
            <section data-system-adjust-editor>
                <div class="confirm-summary-grid">
                    <div><span>Product</span><strong data-system-adjust-product>—</strong></div>
                    <div><span>Branch</span><strong data-system-adjust-branch>—</strong></div>
                    <div><span>Current Available</span><strong data-system-adjust-current>0</strong></div>
                </div>

                <div data-system-adjust-accessory hidden>
                    <div class="stock-adjust-accessory-grid">
                        <label class="field"><span>Adjustment Type <b>*</b></span><select data-system-adjust-direction>
                            <option value="decrease">Decrease Stock</option>
                            <option value="increase">Increase Stock</option>
                        </select><small>Use Increase only to correct a missing quantity. Use Receive Stock for normal deliveries.</small></label>
                        <label class="field"><span data-system-adjust-quantity-label>Quantity to Remove <b>*</b></span><input type="number" min="1" value="1" inputmode="numeric" data-system-adjust-quantity><small data-system-adjust-quantity-help>Enter only the incorrect excess quantity.</small></label>
                    </div>
                </div>

                <div data-system-adjust-devices hidden>
                    <div class="stock-adjust-device-mode-grid">
                        <label class="field"><span>Adjustment Type <b>*</b></span><select data-system-adjust-device-mode>
                            <option value="remove_units">Remove Incorrectly Received Unit</option>
                            <option value="edit_serial">Edit Serial Number</option>
                            <option value="correct_identifier">Correct IMEI / Serial</option>
                        </select><small>Remove only the exact wrong unit, or correct its identifier without changing stock quantity.</small></label>
                    </div>

                    <div data-system-device-remove>
                        <div class="variant-unit-toolbar">
                            <span>Select the exact available unit(s) that were entered incorrectly.</span>
                            <strong data-system-adjust-selected-count>0 selected</strong>
                        </div>
                        <div class="variant-adjust-units" data-system-adjust-units>
                            <div class="loading-state">Loading available units…</div>
                        </div>
                    </div>

                    <div data-system-device-correct hidden>
                        <div class="variant-unit-toolbar">
                            <span>Select one available unit to correct.</span>
                            <strong data-system-correct-selected-label>No unit selected</strong>
                        </div>
                        <div class="variant-adjust-units system-correct-unit-list" data-system-correct-unit-list>
                            <div class="loading-state">Loading available units…</div>
                        </div>
                        <div data-system-correct-fields hidden>
                            <div class="device-serial-correction" data-system-serial-correction hidden>
                                <div class="device-serial-grid">
                                    <label class="field"><span>Current Serial Number</span><input type="text" data-system-current-serial readonly></label>
                                    <label class="field"><span>New Serial Number <b>*</b></span><input type="text" maxlength="120" autocomplete="off" data-system-new-serial placeholder="Enter corrected Serial Number"></label>
                                </div>
                                <div class="serial-correction-info"><strong>Serial correction only</strong><span>This updates the selected device unit only. Available stock quantity will not change. Duplicate Serial Numbers are blocked and the correction is recorded in the audit trail.</span></div>
                            </div>
                            <div class="device-identifier-correction" data-system-identifier-correction hidden>
                                <div class="device-identifier-current">
                                    <span>Selected Unit</span>
                                    <strong data-system-correct-current-label>—</strong>
                                </div>
                                <div class="device-identifier-grid">
                                    <label class="field"><span>IMEI 1</span><input type="text" maxlength="80" autocomplete="off" data-system-correct-imei placeholder="IMEI 1"></label>
                                    <label class="field"><span>IMEI 2</span><input type="text" maxlength="80" autocomplete="off" data-system-correct-imei2 placeholder="IMEI 2 (optional)"></label>
                                    <label class="field"><span>Serial Number</span><input type="text" maxlength="120" autocomplete="off" data-system-correct-serial placeholder="Serial Number"></label>
                                </div>
                                <small class="device-identifier-help">Stock quantity will not change. The corrected identifier will stay attached to the same inventory unit and history.</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="variant-adjust-reason" data-system-adjust-reason-block>
                    <label class="field"><span>Reason <b>*</b></span><select data-system-adjust-reason>
                        <option value="">Select reason</option>
                        <option value="stock_correction">Stock Correction</option>
                        <option value="damaged">Damaged</option>
                        <option value="missing">Missing</option>
                        <option value="return_supplier">Return to Supplier</option>
                        <option value="other">Other</option>
                    </select></label>
                    <label class="field"><span>Note <small>(required for Other)</small></span><input type="text" maxlength="180" data-system-adjust-note placeholder="Short explanation or reference"></label>
                </div>
                <div class="restore-unit-info"><strong>Controlled adjustment</strong><span>Branch Managers can correct stock only for their own branch. Device decreases require exact IMEI/Serial selection. Every adjustment is recorded in the audit trail.</span></div>
                <div class="alert alert-error" data-system-adjust-error hidden></div>
            </section>

            <section data-system-adjust-review hidden>
                <div class="confirm-summary-grid">
                    <div><span>Product</span><strong data-system-review-product>—</strong></div>
                    <div><span>Branch</span><strong data-system-review-branch>—</strong></div>
                    <div><span>Current Stock</span><strong data-system-review-current>0</strong></div>
                    <div><span data-system-review-change-label>Decrease</span><strong data-system-review-remove>0</strong></div>
                    <div><span>Stock After Adjustment</span><strong data-system-review-after>0</strong></div>
                    <div><span>Reason</span><strong data-system-review-reason>—</strong></div>
                </div>
                <div class="confirm-imeis hidden" data-system-review-units></div>
                <label class="stock-confirm-acknowledgement"><input type="checkbox" data-system-adjust-ack><span><strong>I checked this stock correction.</strong><small data-system-adjust-confirm-help>This will update available inventory and create an adjustment history record.</small></span></label>
                <div class="alert alert-error" data-system-review-error hidden></div>
            </section>
        </div>
        <div class="modal-actions" data-system-adjust-editor-actions>
            <button class="btn btn-secondary" type="button" data-system-adjust-close>Cancel</button>
            <button class="btn btn-primary" type="button" data-system-adjust-review-button>Review Adjustment</button>
        </div>
        <div class="modal-actions" data-system-adjust-review-actions hidden>
            <button class="btn btn-secondary" type="button" data-system-adjust-back>Back &amp; Edit</button>
            <button class="btn btn-primary" type="button" data-system-adjust-confirm disabled>Confirm Stock Adjustment</button>
        </div>
    </div>
</div>

<script>
(() => {
  const modal=document.getElementById('systemStockAdjustModal');
  if(!modal)return;
  const q=(selector,root=modal)=>root.querySelector(selector);
  const qa=(selector,root=modal)=>[...root.querySelectorAll(selector)];
  const esc=(value='')=>String(value).replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const csrf=<?= json_encode(Csrf::token(), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
  let state=null;

  function normalizeIdentifier(value=''){
    return String(value).trim().replace(/\s+/g,'').toUpperCase();
  }
  function setError(message='',review=false){
    const box=q(review?'[data-system-review-error]':'[data-system-adjust-error]');
    if(!box)return;
    box.textContent=message;
    box.hidden=!message;
  }
  function selectedUnits(){
    return qa('[data-system-adjust-unit]:checked').map(input=>({id:Number(input.value),identifier:input.dataset.identifier||('Unit #'+input.value)})).filter(unit=>unit.id>0);
  }
  function selectedCorrectionUnit(){
    const input=q('[data-system-correct-unit]:checked');
    if(!input||!state)return null;
    const id=Number(input.value||0);
    return (state.units||[]).find(unit=>Number(unit.unit_id)===id)||null;
  }
  function deviceMode(){
    const value=q('[data-system-adjust-device-mode]')?.value||'remove_units';
    return ['remove_units','edit_serial','correct_identifier'].includes(value)?value:'remove_units';
  }
  function adjustmentCount(){
    if(!state)return 0;
    if(state.type==='accessory')return Math.max(0,Number(q('[data-system-adjust-quantity]').value)||0);
    if(deviceMode()==='remove_units')return selectedUnits().length;
    return 0;
  }
  function adjustmentDirection(){
    if(!state||state.type!=='accessory')return 'decrease';
    return q('[data-system-adjust-direction]')?.value==='increase'?'increase':'decrease';
  }
  function syncAccessoryDirection(){
    if(!state||state.type!=='accessory')return;
    const direction=adjustmentDirection();
    const label=q('[data-system-adjust-quantity-label]');
    const help=q('[data-system-adjust-quantity-help]');
    if(label)label.innerHTML=(direction==='increase'?'Quantity to Add':'Quantity to Remove')+' <b>*</b>';
    if(help)help.textContent=direction==='increase'?'Enter the missing quantity that should be added back.':'Enter only the incorrect excess quantity.';
  }
  function syncSelection(){
    const count=selectedUnits().length;
    const counter=q('[data-system-adjust-selected-count]');
    if(counter)counter.textContent=`${count} selected`;
  }
  function syncDeviceMode(){
    if(!state||state.type==='accessory')return;
    const mode=deviceMode();
    const correction=mode!=='remove_units';
    const serialOnly=mode==='edit_serial';
    q('[data-system-device-remove]').hidden=correction;
    q('[data-system-device-correct]').hidden=!correction;
    q('[data-system-serial-correction]').hidden=!serialOnly;
    q('[data-system-identifier-correction]').hidden=serialOnly||!correction;
    q('[data-system-adjust-reason-block]').hidden=serialOnly;
    q('#systemAdjustTitle').textContent=serialOnly?'Device Serial Correction':'Device Stock Adjustment';
    q('[data-system-adjust-subtitle]').textContent=serialOnly
      ?'Correct the exact Serial Number entered incorrectly without changing stock quantity.'
      :(mode==='correct_identifier'
        ?'Select one available unit, then correct its IMEI or Serial Number. Stock quantity will stay the same.'
        :'Select the exact IMEI/Serial units that were entered incorrectly.');
    q('[data-system-adjust-review-button]').textContent=serialOnly?'Review Serial Correction':'Review Adjustment';
    q('[data-system-adjust-confirm]').textContent=serialOnly?'Confirm Serial Correction':'Confirm Stock Adjustment';
    setError('',false);
  }
  function populateCorrectionFields(){
    const unit=selectedCorrectionUnit();
    const fields=q('[data-system-correct-fields]');
    const label=q('[data-system-correct-selected-label]');
    if(!unit){
      if(fields)fields.hidden=true;
      if(label)label.textContent='No unit selected';
      return;
    }
    const currentLabel=unit.serial_no||unit.imei||unit.imei2||('Unit #'+unit.unit_id);
    if(label)label.textContent=currentLabel;
    q('[data-system-correct-current-label]').textContent=currentLabel;
    q('[data-system-current-serial]').value=unit.serial_no||'—';
    q('[data-system-new-serial]').value=unit.serial_no||'';
    q('[data-system-correct-imei]').value=unit.imei||'';
    q('[data-system-correct-imei2]').value=unit.imei2||'';
    q('[data-system-correct-serial]').value=unit.serial_no||'';
    fields.hidden=false;
    syncDeviceMode();
  }
  function renderDeviceUnits(rows){
    state.units=rows||[];
    const removeList=q('[data-system-adjust-units]');
    const correctList=q('[data-system-correct-unit-list]');
    if(!rows.length){
      const empty='<div class="empty-state small"><strong>No available units</strong><span>Refresh Inventory and try again.</span></div>';
      removeList.innerHTML=empty;
      correctList.innerHTML=empty;
      return;
    }
    const unitCopy=row=>{
      const identifier=row.identifier||row.serial_no||row.imei||row.imei2||('Unit #'+row.unit_id);
      const type=row.serial_no?'SN':'IMEI';
      const details=[];
      if(row.imei)details.push(`IMEI 1: ${esc(row.imei)}`);
      if(row.imei2)details.push(`IMEI 2: ${esc(row.imei2)}`);
      if(row.serial_no)details.push(`Serial: ${esc(row.serial_no)}`);
      return {identifier,type,details:details.join(' • ')||'Available'};
    };
    removeList.innerHTML=rows.map(row=>{
      const copy=unitCopy(row);
      return `<label class="variant-adjust-unit"><input type="checkbox" value="${Number(row.unit_id)}" data-system-adjust-unit data-identifier="${esc(copy.identifier)}"><span class="variant-adjust-unit-index">${copy.type}</span><span class="variant-adjust-unit-copy"><strong>${esc(copy.identifier)}</strong><small>${copy.details}</small></span></label>`;
    }).join('');
    correctList.innerHTML=rows.map(row=>{
      const copy=unitCopy(row);
      return `<label class="variant-adjust-unit"><input type="radio" name="system_correct_unit" value="${Number(row.unit_id)}" data-system-correct-unit><span class="variant-adjust-unit-index">${copy.type}</span><span class="variant-adjust-unit-copy"><strong>${esc(copy.identifier)}</strong><small>${copy.details}</small></span></label>`;
    }).join('');
    qa('[data-system-adjust-unit]').forEach(input=>input.addEventListener('change',syncSelection));
    qa('[data-system-correct-unit]').forEach(input=>input.addEventListener('change',populateCorrectionFields));
    syncSelection();
    populateCorrectionFields();
  }
  async function loadDeviceUnits(){
    const removeList=q('[data-system-adjust-units]');
    const correctList=q('[data-system-correct-unit-list]');
    removeList.innerHTML='<div class="loading-state">Loading available units…</div>';
    correctList.innerHTML='<div class="loading-state">Loading available units…</div>';
    try{
      const params=new URLSearchParams({product_id:String(state.productId),branch_id:String(state.branchId)});
      const response=await fetch('actions/product_units.php?'+params.toString(),{headers:{'Accept':'application/json'}});
      const data=await response.json();
      if(!response.ok)throw new Error(data.error||'Unable to load units.');
      renderDeviceUnits(data.rows||[]);
    }catch(error){
      const html=`<div class="alert alert-error">${esc(error.message||'Unable to load units.')}</div>`;
      removeList.innerHTML=html;
      correctList.innerHTML=html;
    }
  }
  function showEditor(){
    q('[data-system-adjust-editor]').hidden=false;
    q('[data-system-adjust-review]').hidden=true;
    q('[data-system-adjust-editor-actions]').hidden=false;
    q('[data-system-adjust-review-actions]').hidden=true;
    q('[data-system-adjust-ack]').checked=false;
    q('[data-system-adjust-confirm]').disabled=true;
    q('[data-system-adjust-confirm]').textContent='Confirm Stock Adjustment';
    q('[data-system-adjust-review-button]').textContent='Review Adjustment';
    if(state&&state.type!=='accessory')syncDeviceMode();
    setError('',false);setError('',true);
  }
  function close(){
    modal.hidden=true;
    document.body.classList.remove('modal-open');
    state=null;
  }
  async function open(button){
    state={
      productId:Number(button.dataset.productId||0),
      branchId:Number(button.dataset.branchId||0),
      product:button.dataset.product||'Product',
      branch:button.dataset.branch||'Branch',
      available:Number(button.dataset.available||0),
      type:button.dataset.productType||'',
      units:[],
      pendingCorrection:null
    };
    q('[data-system-adjust-product]').textContent=state.product;
    q('[data-system-adjust-branch]').textContent=state.branch;
    q('[data-system-adjust-current]').textContent=state.available.toLocaleString();
    q('[data-system-adjust-reason]').value='';
    q('[data-system-adjust-note]').value='';
    const accessory=state.type==='accessory';
    q('[data-system-adjust-accessory]').hidden=!accessory;
    q('[data-system-adjust-devices]').hidden=accessory;
    q('#systemAdjustTitle').textContent=accessory?'Accessory Stock Adjustment':'Device Stock Adjustment';
    q('[data-system-adjust-subtitle]').textContent=accessory?'Increase or decrease an incorrect accessory quantity after review.':'Select the exact IMEI/Serial units that were entered incorrectly.';
    const direction=q('[data-system-adjust-direction]');
    if(direction)direction.value='decrease';
    const deviceModeSelect=q('[data-system-adjust-device-mode]');
    if(deviceModeSelect)deviceModeSelect.value='remove_units';
    const qty=q('[data-system-adjust-quantity]');
    qty.value='1';qty.max=String(Math.max(1,state.available));
    q('[data-system-correct-fields]').hidden=true;
    q('[data-system-correct-selected-label]').textContent='No unit selected';
    q('[data-system-current-serial]').value='';
    q('[data-system-new-serial]').value='';
    syncAccessoryDirection();
    syncDeviceMode();
    showEditor();
    modal.hidden=false;
    document.body.classList.add('modal-open');
    if(!accessory)await loadDeviceUnits();
  }

  qa('[data-system-stock-adjust]',document).forEach(button=>button.addEventListener('click',()=>open(button)));
  qa('[data-system-adjust-close]').forEach(button=>button.addEventListener('click',close));
  q('[data-system-adjust-quantity]').addEventListener('input',event=>{
    let value=Math.max(1,Number(event.target.value)||1);
    if(adjustmentDirection()==='decrease')value=Math.min(value,state?.available||1);
    value=Math.min(value,100000);
    event.target.value=String(value);
  });
  q('[data-system-adjust-direction]')?.addEventListener('change',()=>{
    syncAccessoryDirection();
    const qty=q('[data-system-adjust-quantity]');
    if(adjustmentDirection()==='decrease'&&Number(qty.value)>state.available)qty.value=String(Math.max(1,state.available));
  });
  q('[data-system-adjust-device-mode]')?.addEventListener('change',syncDeviceMode);

  q('[data-system-adjust-review-button]').addEventListener('click',()=>{
    if(!state)return;
    setError('',false);
    state.pendingCorrection=null;
    const mode=state.type==='accessory'?'quantity_adjustment':deviceMode();
    const serialOnly=mode==='edit_serial';
    const reason=q('[data-system-adjust-reason]').value;
    const note=q('[data-system-adjust-note]').value.trim();
    if(!serialOnly&&!reason){q('[data-system-adjust-reason]').focus();setError('Select a reason before reviewing the adjustment.');return;}
    if(!serialOnly&&reason==='other'&&!note){q('[data-system-adjust-note]').focus();setError('Add a short note when using Other.');return;}

    const reasonSelect=q('[data-system-adjust-reason]');
    q('[data-system-review-product]').textContent=state.product;
    q('[data-system-review-branch]').textContent=state.branch;
    q('[data-system-review-current]').textContent=state.available.toLocaleString();
    q('[data-system-review-reason]').textContent=serialOnly?'Serial Correction':(reasonSelect.selectedOptions[0]?.textContent||reason);
    const unitsBox=q('[data-system-review-units]');

    if(state.type==='accessory'){
      const remove=adjustmentCount();
      const direction=adjustmentDirection();
      if(remove<1){setError('Enter a valid adjustment quantity.');return;}
      if(direction==='decrease'&&remove>state.available){setError('The adjustment is higher than the current available stock.');return;}
      if(direction==='increase'&&!['stock_correction','other'].includes(reason)){setError('Use Stock Correction or Other when increasing accessory stock.');return;}
      q('[data-system-review-change-label]').textContent=direction==='increase'?'Increase':'Decrease';
      q('[data-system-review-remove]').textContent=(direction==='increase'?'+':'−')+remove.toLocaleString();
      q('[data-system-review-after]').textContent=(direction==='increase'?state.available+remove:state.available-remove).toLocaleString();
      q('[data-system-adjust-confirm-help]').textContent=direction==='increase'?'This will add the reviewed quantity and create an Adjustment IN history record.':'This will reduce available inventory and create an Adjustment OUT history record.';
      unitsBox.classList.add('hidden');
      unitsBox.innerHTML='';
    }else if(mode==='remove_units'){
      const units=selectedUnits();
      if(!units.length){setError('Select at least one exact IMEI/Serial unit.');return;}
      if(units.length>state.available){setError('The selected units are higher than current available stock.');return;}
      q('[data-system-review-change-label]').textContent='Decrease';
      q('[data-system-review-remove]').textContent='−'+units.length.toLocaleString();
      q('[data-system-review-after]').textContent=(state.available-units.length).toLocaleString();
      q('[data-system-adjust-confirm-help]').textContent='This will remove the selected available unit(s) and create an Adjustment OUT history record.';
      unitsBox.classList.remove('hidden');
      unitsBox.innerHTML=`<strong>Units to Remove</strong><span>${units.map(unit=>esc(unit.identifier)).join(' • ')}</span>`;
    }else if(mode==='edit_serial'){
      const unit=selectedCorrectionUnit();
      if(!unit){setError('Select one exact unit to correct.');return;}
      const oldSerial=normalizeIdentifier(unit.serial_no||'');
      const newSerial=normalizeIdentifier(q('[data-system-new-serial]').value);
      const imei=normalizeIdentifier(unit.imei||'');
      const imei2=normalizeIdentifier(unit.imei2||'');
      if(!newSerial){q('[data-system-new-serial]').focus();setError('Enter the corrected Serial Number.');return;}
      if(newSerial===oldSerial){q('[data-system-new-serial]').focus();setError('Enter a different Serial Number before reviewing.');return;}
      if(newSerial===imei||newSerial===imei2){q('[data-system-new-serial]').focus();setError('Serial Number must not duplicate this unit’s IMEI.');return;}
      state.pendingCorrection={mode:'edit_serial',unitId:Number(unit.unit_id),serial:newSerial,oldSerial};
      q('[data-system-review-change-label]').textContent='Quantity Change';
      q('[data-system-review-remove]').textContent='0';
      q('[data-system-review-after]').textContent=state.available.toLocaleString();
      q('[data-system-adjust-confirm-help]').textContent='This will update the selected Serial Number only. Available stock quantity will not change.';
      q('[data-system-adjust-confirm]').textContent='Confirm Serial Correction';
      unitsBox.classList.remove('hidden');
      unitsBox.innerHTML=`<strong>Serial Number Correction</strong><span>Current: ${esc(oldSerial||'—')}<br>New: ${esc(newSerial)}</span>`;
    }else{
      const unit=selectedCorrectionUnit();
      if(!unit){setError('Select one exact unit to correct.');return;}
      const imei=normalizeIdentifier(q('[data-system-correct-imei]').value);
      const imei2=normalizeIdentifier(q('[data-system-correct-imei2]').value);
      const serial=normalizeIdentifier(q('[data-system-correct-serial]').value);
      const values=[imei,imei2,serial].filter(Boolean);
      if(!values.length){setError('Enter at least one IMEI or Serial Number for the selected unit.');return;}
      if(new Set(values).size!==values.length){setError('IMEI 1, IMEI 2, and Serial Number must not duplicate each other.');return;}
      const old={imei:normalizeIdentifier(unit.imei||''),imei2:normalizeIdentifier(unit.imei2||''),serial:normalizeIdentifier(unit.serial_no||'')};
      if(imei===old.imei&&imei2===old.imei2&&serial===old.serial){setError('No identifier change was detected. Edit the IMEI or Serial Number first.');return;}
      state.pendingCorrection={mode:'correct_identifier',unitId:Number(unit.unit_id),imei,imei2,serial,old};
      const changes=[];
      if(imei!==old.imei)changes.push(`IMEI 1: ${esc(old.imei||'—')} → ${esc(imei||'—')}`);
      if(imei2!==old.imei2)changes.push(`IMEI 2: ${esc(old.imei2||'—')} → ${esc(imei2||'—')}`);
      if(serial!==old.serial)changes.push(`Serial: ${esc(old.serial||'—')} → ${esc(serial||'—')}`);
      q('[data-system-review-change-label]').textContent='Quantity Change';
      q('[data-system-review-remove]').textContent='0';
      q('[data-system-review-after]').textContent=state.available.toLocaleString();
      q('[data-system-adjust-confirm-help]').textContent='This will correct the selected unit identifier only. Available stock quantity will not change.';
      unitsBox.classList.remove('hidden');
      unitsBox.innerHTML=`<strong>Identifier Correction</strong><span>${changes.join('<br>')}</span>`;
    }

    q('[data-system-adjust-editor]').hidden=true;
    q('[data-system-adjust-review]').hidden=false;
    q('[data-system-adjust-editor-actions]').hidden=true;
    q('[data-system-adjust-review-actions]').hidden=false;
    q('[data-system-adjust-ack]').checked=false;
    q('[data-system-adjust-confirm]').disabled=true;
  });
  q('[data-system-adjust-back]').addEventListener('click',showEditor);
  q('[data-system-adjust-ack]').addEventListener('change',event=>{q('[data-system-adjust-confirm]').disabled=!event.target.checked;});
  q('[data-system-adjust-confirm]').addEventListener('click',async()=>{
    if(!state||!q('[data-system-adjust-ack]').checked)return;
    const button=q('[data-system-adjust-confirm]');
    const units=selectedUnits();
    const mode=state.type==='accessory'?'quantity_adjustment':deviceMode();
    const correction=mode==='edit_serial'||mode==='correct_identifier'?state.pendingCorrection:null;
    const payload={
      _csrf:csrf,
      confirmed:true,
      product_id:state.productId,
      branch_id:state.branchId,
      action_mode:mode,
      unit_ids:mode==='remove_units'?units.map(unit=>unit.id):[],
      unit_id:correction?correction.unitId:0,
      imei:mode==='correct_identifier'&&correction?correction.imei:'',
      imei2:mode==='correct_identifier'&&correction?correction.imei2:'',
      serial_no:correction?correction.serial:'',
      quantity:state.type==='accessory'?adjustmentCount():(mode==='remove_units'?units.length:0),
      direction:adjustmentDirection(),
      reason:mode==='edit_serial'?'':q('[data-system-adjust-reason]').value,
      notes:mode==='edit_serial'?'':q('[data-system-adjust-note]').value.trim()
    };
    button.disabled=true;button.textContent='Saving…';setError('',true);
    try{
      const response=await fetch('actions/adjust_inventory.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(payload)});
      const data=await response.json();
      if(!response.ok)throw new Error(data.error||'Unable to save stock adjustment.');
      window.location.reload();
    }catch(error){
      button.disabled=false;button.textContent='Confirm Stock Adjustment';
      setError(error.message||'Unable to save stock adjustment.',true);
    }
  });
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!modal.hidden)close();});
})();
</script>
<?php endif; ?>

<?php if ($canDeleteDeviceInventory): ?>
<div class="modal" id="deleteDeviceInventoryModal" hidden>
    <div class="modal-backdrop" data-delete-device-close></div>
    <div class="modal-dialog inventory-delete-dialog" role="dialog" aria-modal="true" aria-labelledby="deleteDeviceInventoryTitle">
        <div class="modal-header">
            <div>
                <span class="eyebrow delete-eyebrow">INVENTORY CONTROL</span>
                <h2 id="deleteDeviceInventoryTitle">Delete Inventory Unit</h2>
                <p class="modal-subtitle">Permanently remove an incorrectly received available device unit.</p>
            </div>
            <button type="button" class="icon-button" data-delete-device-close aria-label="Close">×</button>
        </div>

        <div class="modal-body inventory-delete-body" data-delete-device-editor>
            <div class="delete-summary-grid">
                <div><span>Product</span><strong data-delete-device-product>—</strong></div>
                <div><span>Branch</span><strong data-delete-device-branch>—</strong></div>
                <div><span>Current Available</span><strong data-delete-device-current>0</strong></div>
            </div>

            <div class="delete-warning-panel">
                <div class="delete-warning-icon">!</div>
                <div>
                    <strong>Permanent inventory deletion</strong>
                    <span>Use this only for a unit entered under the wrong variant or with an incorrect inventory record. Sold, transferred, reserved, returned, or otherwise historical units are blocked.</span>
                </div>
            </div>

            <div class="delete-unit-section">
                <div class="delete-unit-toolbar">
                    <div><strong>Select exact unit(s)</strong><span>Only currently available units are shown.</span></div>
                    <b data-delete-device-selected-count>0 selected</b>
                </div>
                <div class="variant-adjust-units delete-unit-list" data-delete-device-units>
                    <div class="loading-state">Loading available units…</div>
                </div>
            </div>

            <div class="delete-impact-card">
                <div><span>Current Stock</span><strong data-delete-device-impact-current>0</strong></div>
                <div class="delete-impact-danger"><span>Selected to Delete</span><strong data-delete-device-impact-remove>0</strong></div>
                <div class="delete-impact-safe"><span>Stock After Delete</span><strong data-delete-device-impact-after>0</strong></div>
            </div>
            <div class="alert alert-error hidden" data-delete-device-error></div>
        </div>

        <div class="modal-body inventory-delete-body" data-delete-device-review hidden>
            <div class="delete-review-heading">
                <span class="eyebrow delete-eyebrow">FINAL REVIEW</span>
                <h3>Confirm permanent deletion</h3>
                <p>Review the exact unit identifiers before deleting them from inventory.</p>
            </div>
            <div class="delete-summary-grid">
                <div><span>Product</span><strong data-delete-review-product>—</strong></div>
                <div><span>Branch</span><strong data-delete-review-branch>—</strong></div>
                <div><span>Stock After Delete</span><strong data-delete-review-after>0</strong></div>
            </div>
            <div class="delete-review-units"><strong>Units to Delete</strong><div data-delete-review-units>—</div></div>
            <label class="stock-confirm-acknowledgement delete-confirm-ack">
                <input type="checkbox" data-delete-device-ack>
                <span><strong>I confirm these are incorrect inventory entries.</strong><small>The selected available inventory unit(s) and their erroneous stock-in/correction movement records will be permanently removed. This cannot be undone.</small></span>
            </label>
            <div class="alert alert-error hidden" data-delete-device-review-error></div>
        </div>

        <div class="modal-actions delete-device-editor-actions" data-delete-device-editor-actions>
            <button class="btn btn-secondary" type="button" data-delete-device-close>Cancel</button>
            <button class="btn btn-danger" type="button" data-delete-device-review-button disabled>Review Delete</button>
        </div>
        <div class="modal-actions delete-device-review-actions" data-delete-device-review-actions hidden>
            <button class="btn btn-secondary" type="button" data-delete-device-back>Back</button>
            <button class="btn btn-danger" type="button" data-delete-device-confirm disabled>Delete Inventory Unit</button>
        </div>
    </div>
</div>
<script>
(()=>{
  const modal=document.getElementById('deleteDeviceInventoryModal');
  if(!modal)return;
  const csrf=<?= json_encode(Csrf::token(), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
  const q=(sel,root=modal)=>root.querySelector(sel);
  const qa=(sel,root=modal)=>Array.from(root.querySelectorAll(sel));
  const esc=value=>String(value??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  let state=null;

  function selectedUnits(){
    if(!state)return[];
    const selected=new Set(qa('[data-delete-device-unit]:checked').map(input=>Number(input.value)));
    return state.units.filter(unit=>selected.has(Number(unit.unit_id)));
  }
  function identifierFor(unit){return unit.serial_no||unit.imei||unit.imei2||unit.identifier||('Unit #'+unit.unit_id);}
  function detailsFor(unit){
    const parts=[];
    if(unit.imei)parts.push('IMEI 1: '+unit.imei);
    if(unit.imei2)parts.push('IMEI 2: '+unit.imei2);
    if(unit.serial_no)parts.push('Serial: '+unit.serial_no);
    return parts.join(' • ')||'Available unit';
  }
  function setError(message,review=false){
    const box=q(review?'[data-delete-device-review-error]':'[data-delete-device-error]');
    box.textContent=message||'';box.classList.toggle('hidden',!message);
  }
  function syncSelection(){
    const count=selectedUnits().length;
    q('[data-delete-device-selected-count]').textContent=count+' selected';
    q('[data-delete-device-impact-current]').textContent=state.available.toLocaleString();
    q('[data-delete-device-impact-remove]').textContent=count.toLocaleString();
    q('[data-delete-device-impact-after]').textContent=Math.max(0,state.available-count).toLocaleString();
    q('[data-delete-device-review-button]').disabled=count<1;
    setError('');
  }
  function renderUnits(rows){
    state.units=rows||[];
    const list=q('[data-delete-device-units]');
    if(!state.units.length){
      list.innerHTML='<div class="empty-state small"><strong>No deletable available units</strong><span>This item may already have history that prevents deletion.</span></div>';
      syncSelection();return;
    }
    list.innerHTML=state.units.map(unit=>{
      const identifier=identifierFor(unit);const type=unit.serial_no?'SN':'IMEI';
      return `<label class="variant-adjust-unit delete-unit-option"><input type="checkbox" value="${Number(unit.unit_id)}" data-delete-device-unit><span class="variant-adjust-unit-index">${type}</span><span class="variant-adjust-unit-copy"><strong>${esc(identifier)}</strong><small>${esc(detailsFor(unit))}</small></span></label>`;
    }).join('');
    qa('[data-delete-device-unit]').forEach(input=>input.addEventListener('change',syncSelection));
    syncSelection();
  }
  async function loadUnits(){
    q('[data-delete-device-units]').innerHTML='<div class="loading-state">Loading available units…</div>';
    try{
      const params=new URLSearchParams({product_id:String(state.productId),branch_id:String(state.branchId)});
      const response=await fetch('actions/product_units.php?'+params.toString(),{headers:{'Accept':'application/json'}});
      const data=await response.json();
      if(!response.ok)throw new Error(data.error||'Unable to load available units.');
      renderUnits(data.rows||[]);
    }catch(error){q('[data-delete-device-units]').innerHTML=`<div class="alert alert-error">${esc(error.message||'Unable to load units.')}</div>`;}
  }
  function showEditor(){
    q('[data-delete-device-editor]').hidden=false;q('[data-delete-device-review]').hidden=true;
    q('[data-delete-device-editor-actions]').hidden=false;q('[data-delete-device-review-actions]').hidden=true;
    q('[data-delete-device-ack]').checked=false;q('[data-delete-device-confirm]').disabled=true;
    setError('');setError('',true);
  }
  function close(){modal.hidden=true;document.body.classList.remove('modal-open');state=null;}
  async function open(button){
    state={productId:Number(button.dataset.productId||0),branchId:Number(button.dataset.branchId||0),product:button.dataset.product||'Product',branch:button.dataset.branch||'Branch',available:Number(button.dataset.available||0),units:[]};
    q('[data-delete-device-product]').textContent=state.product;q('[data-delete-device-branch]').textContent=state.branch;q('[data-delete-device-current]').textContent=state.available.toLocaleString();
    q('[data-delete-device-impact-current]').textContent=state.available.toLocaleString();q('[data-delete-device-impact-remove]').textContent='0';q('[data-delete-device-impact-after]').textContent=state.available.toLocaleString();
    showEditor();modal.hidden=false;document.body.classList.add('modal-open');await loadUnits();
  }

  document.querySelectorAll('[data-delete-device-inventory]').forEach(button=>button.addEventListener('click',()=>open(button)));
  qa('[data-delete-device-close]').forEach(button=>button.addEventListener('click',close));
  q('[data-delete-device-review-button]').addEventListener('click',()=>{
    const units=selectedUnits();if(!units.length){setError('Select at least one exact available unit to delete.');return;}
    q('[data-delete-review-product]').textContent=state.product;q('[data-delete-review-branch]').textContent=state.branch;q('[data-delete-review-after]').textContent=Math.max(0,state.available-units.length).toLocaleString();
    q('[data-delete-review-units]').innerHTML=units.map(unit=>`<div class="delete-review-unit"><b>${esc(identifierFor(unit))}</b><span>${esc(detailsFor(unit))}</span></div>`).join('');
    q('[data-delete-device-editor]').hidden=true;q('[data-delete-device-review]').hidden=false;q('[data-delete-device-editor-actions]').hidden=true;q('[data-delete-device-review-actions]').hidden=false;
    q('[data-delete-device-ack]').checked=false;q('[data-delete-device-confirm]').disabled=true;setError('',true);
  });
  q('[data-delete-device-back]').addEventListener('click',showEditor);
  q('[data-delete-device-ack]').addEventListener('change',event=>{q('[data-delete-device-confirm]').disabled=!event.target.checked;});
  q('[data-delete-device-confirm]').addEventListener('click',async()=>{
    const units=selectedUnits();if(!state||!units.length||!q('[data-delete-device-ack]').checked)return;
    const button=q('[data-delete-device-confirm]');button.disabled=true;button.textContent='Deleting…';setError('',true);
    try{
      const response=await fetch('actions/delete_inventory_unit.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({_csrf:csrf,confirmed:true,product_id:state.productId,branch_id:state.branchId,unit_ids:units.map(unit=>Number(unit.unit_id))})});
      const data=await response.json();if(!response.ok)throw new Error(data.error||'Unable to delete inventory unit.');window.location.reload();
    }catch(error){button.disabled=false;button.textContent=units.length>1?'Delete Inventory Units':'Delete Inventory Unit';setError(error.message||'Unable to delete inventory unit.',true);}
  });
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!modal.hidden)close();});
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../partials/inventory-accessory-delete.php'; ?>
