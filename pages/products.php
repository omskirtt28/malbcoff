<?php
$role = Auth::user()['role'] ?? '';
$isOwner = Auth::isOwner();
$canAddMaster = in_array($role, ['owner', 'branch_manager', 'inventory'], true);
$canEditModel = $canAddMaster;
$canEditBrand = $isOwner;
$canEditCategory = $canAddMaster;
$canEditVariantPrice = in_array($role, ['owner','branch_manager'], true);
$priceBranchId = $isOwner ? current_branch_scope() : (Auth::branchId() ?: null);
$priceBranches = [];
$priceMap = [];
$pricingReady = false;

$selectedBrandId = filter_input(INPUT_GET, 'brand', FILTER_VALIDATE_INT) ?: null;
$selectedModelId = filter_input(INPUT_GET, 'model', FILTER_VALIDATE_INT) ?: null;
$search = trim((string)($_GET['q'] ?? ''));
$showArchived = $isOwner && (($_GET['status'] ?? '') === 'all');
$activeView = (($_GET['view'] ?? 'devices') === 'accessories') ? 'accessories' : 'devices';
$schemaReady = false;
$brands = $models = $categories = $configurations = [];
$selectedModel = null;

try {
    $schemaReady = (bool)Database::query("SHOW COLUMNS FROM product_models LIKE 'device_type'")->fetch();

    $brandWhere = $isOwner ? ($showArchived ? '1=1' : 'b.is_active=1') : 'b.is_active=1';
    $brands = Database::query(
        "SELECT b.id,b.name,b.is_active,
                COUNT(pm.id) AS model_count,
                SUM(CASE WHEN pm.is_active=1 THEN 1 ELSE 0 END) AS active_model_count,
                (SELECT COUNT(*) FROM products p WHERE p.brand_id=b.id) AS product_count
         FROM brands b
         LEFT JOIN product_models pm ON pm.brand_id=b.id
         WHERE {$brandWhere}
         GROUP BY b.id,b.name,b.is_active
         ORDER BY b.is_active DESC,b.name"
    )->fetchAll();

    $modelSql = "SELECT pm.id,pm.brand_id,pm.name,pm.is_active,b.name AS brand_name,b.is_active AS brand_active,
                        " . ($schemaReady ? "pm.device_type" : "'phone' AS device_type") . ",
                        (SELECT COUNT(*) FROM products p WHERE p.model_id=pm.id) AS product_count
                 FROM product_models pm
                 JOIN brands b ON b.id=pm.brand_id
                 WHERE 1=1";
    $params = [];
    if (!$isOwner || !$showArchived) $modelSql .= ' AND pm.is_active=1 AND b.is_active=1';
    if ($selectedBrandId) { $modelSql .= ' AND pm.brand_id=?'; $params[] = $selectedBrandId; }
    if ($search !== '') { $modelSql .= ' AND (pm.name LIKE ? OR b.name LIKE ?)'; $params[] = '%'.$search.'%'; $params[] = '%'.$search.'%'; }
    $modelSql .= ' ORDER BY b.name,pm.name LIMIT 200';
    $models = Database::query($modelSql, $params)->fetchAll();

    if ($selectedModelId) {
        $selectedModel = Database::query(
            "SELECT pm.id,pm.brand_id,pm.name,pm.is_active,COALESCE(pm.device_type,'phone') device_type,b.name brand_name,b.is_active brand_active
             FROM product_models pm JOIN brands b ON b.id=pm.brand_id WHERE pm.id=? LIMIT 1", [$selectedModelId]
        )->fetch();
        if ($selectedModel) {
            $selectedBrandId = (int)$selectedModel['brand_id'];
            $configWhere = ($isOwner && $showArchived) ? '1=1' : 'p.is_active=1';
            $configurations = Database::query(
                "SELECT p.id,p.product_type,p.ram,p.storage,p.color,p.connectivity,p.cost_price,p.selling_price,p.is_active,
                        (SELECT COUNT(*) FROM inventory_units iu WHERE iu.product_id=p.id) unit_count,
                        (SELECT COUNT(*) FROM inventory_units ia WHERE ia.product_id=p.id AND ia.status='available') available_count,
                        (SELECT COUNT(*) FROM stock_movements sm WHERE sm.product_id=p.id) movement_count
                 FROM products p WHERE p.model_id=? AND p.product_type IN ('phone','tablet') AND {$configWhere}
                 ORDER BY p.is_active DESC,p.ram,p.storage,p.connectivity,p.color", [$selectedModelId]
            )->fetchAll();
        }
    }

    $categoryWhere = $isOwner ? ($showArchived ? '1=1' : 'c.is_active=1') : 'c.is_active=1';
    $categories = Database::query(
        "SELECT c.id,c.name,c.is_active,(SELECT COUNT(*) FROM products p WHERE p.category_id=c.id) AS product_count
         FROM categories c WHERE {$categoryWhere} ORDER BY c.is_active DESC,c.name"
    )->fetchAll();
    $pricingReady = branch_pricing_ready();
    $priceBranches = Database::query('SELECT id,name FROM branches WHERE is_active=1 ORDER BY id')->fetchAll();
    if ($pricingReady && $configurations) {
        $ids = array_map(fn($row)=>(int)$row['id'], $configurations);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $priceRows = Database::query("SELECT product_id,branch_id,selling_price FROM branch_product_prices WHERE product_id IN ($marks)", $ids)->fetchAll();
        foreach ($priceRows as $row) $priceMap[(int)$row['product_id']][(int)$row['branch_id']] = (float)$row['selling_price'];
    }
} catch (Throwable $e) {
    flash('error', 'Unable to load Product Master. Please apply the P1-006 database migration first.');
}

$selectedBrandName = 'All Brands';
foreach ($brands as $b) {
    if ((int)$b['id'] === (int)$selectedBrandId) {
        $selectedBrandName = $b['name'];
        break;
    }
}

function product_master_url(array $overrides = []): string
{
    $query = [
        'page' => 'products',
        'view' => $_GET['view'] ?? 'devices',
    ];
    if (!empty($_GET['brand'])) $query['brand'] = (int)$_GET['brand'];
    if (!empty($_GET['model'])) $query['model'] = (int)$_GET['model'];
    if (!empty($_GET['q'])) $query['q'] = (string)$_GET['q'];
    if (!empty($_GET['status'])) $query['status'] = (string)$_GET['status'];
    if (!empty($_GET['branch'])) $query['branch'] = (int)$_GET['branch'];
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') unset($query[$key]);
        else $query[$key] = $value;
    }
    return 'index.php?' . http_build_query($query);
}
?>

<section class="page-heading product-master-heading">
    <div>
        <span class="eyebrow">PRODUCT SETUP</span>
        <h1>Products</h1>
        <p>Set up brands, models, variants and accessory categories. Stock stays separate for each branch.</p>
    </div>
</section>

<?php if (!$schemaReady): ?>
    <div class="alert alert-info"><strong>P1-006 migration required.</strong> Run <code>database/P1_006_product_master.sql</code> in phpMyAdmin before adding or editing models.</div>
<?php endif; ?>

<nav class="catalog-switcher" aria-label="Product master sections">
    <a class="catalog-switcher-item <?= $activeView === 'devices' ? 'active' : '' ?>" href="<?= e(product_master_url(['view' => 'devices', 'brand' => null, 'q' => null])) ?>">
        <span class="catalog-switcher-icon"><?= icon('phone') ?></span>
        <span><strong>Device Catalog</strong><small>Brands and phone/tablet models</small></span>
        <span class="catalog-switcher-count"><?= count($brands) ?></span>
    </a>
    <a class="catalog-switcher-item <?= $activeView === 'accessories' ? 'active' : '' ?>" href="<?= e(product_master_url(['view' => 'accessories', 'brand' => null, 'q' => null])) ?>">
        <span class="catalog-switcher-icon accessory"><?= icon('accessory') ?></span>
        <span><strong>Accessories</strong><small>Reusable accessory categories</small></span>
        <span class="catalog-switcher-count"><?= count($categories) ?></span>
    </a>
</nav>

<?php if ($activeView === 'devices'): ?>
<div class="two-column product-master-grid product-master-devices">
    <section class="card master-surface-card">
        <div class="card-header master-card-header">
            <div>
                <span class="section-kicker">BRANDS</span>
                <h2>Device Brands</h2>
                <p>Choose a brand to see its models.</p>
            </div>
            <?php if ($canAddMaster): ?>
                <button class="btn btn-soft-primary btn-sm" type="button" data-master-open="brand" data-mode="add"><?= icon('plus') ?> Add Brand</button>
            <?php endif; ?>
        </div>

        <div class="brand-master-grid">
            <a class="brand-master-card <?= !$selectedBrandId ? 'selected' : '' ?>" href="<?= e(product_master_url(['brand' => null, 'model' => null, 'q' => null])) ?>">
                <span class="brand-master-avatar all">ALL</span>
                <span class="brand-master-copy"><strong>All Brands</strong><small>View every model</small></span>
                <span class="brand-master-arrow">›</span>
            </a>

            <?php foreach ($brands as $brand): ?>
                <div class="brand-master-card <?= $selectedBrandId === (int)$brand['id'] ? 'selected' : '' ?> <?= !(int)$brand['is_active'] ? 'archived' : '' ?>">
                    <a class="brand-master-main" href="<?= e(product_master_url(['brand' => (int)$brand['id'], 'model' => null, 'q' => null])) ?>">
                        <span class="brand-master-avatar"><?= e(strtoupper(substr($brand['name'], 0, 2))) ?></span>
                        <span class="brand-master-copy">
                            <strong><?= e($brand['name']) ?></strong>
                            <small><?= (int)$brand['active_model_count'] ?> active model<?= (int)$brand['active_model_count'] === 1 ? '' : 's' ?><?= !(int)$brand['is_active'] ? ' • Archived' : '' ?></small>
                        </span>
                    </a>
                    <?php if ($canEditBrand): ?>
                        <div class="brand-card-actions">
                            <button class="catalog-icon-btn" type="button" title="Edit <?= e($brand['name']) ?>" aria-label="Edit <?= e($brand['name']) ?>" data-master-open="brand" data-mode="edit" data-id="<?= (int)$brand['id'] ?>" data-name="<?= e($brand['name']) ?>"><?= icon('edit') ?></button>
                            <form method="post" action="actions/product_master.php" data-confirm="<?= (int)$brand['is_active'] ? 'Archive this brand? Existing inventory and history will remain.' : 'Restore this brand?' ?>">
                                <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="entity" value="brand"><input type="hidden" name="return_view" value="devices"><input type="hidden" name="id" value="<?= (int)$brand['id'] ?>"><input type="hidden" name="action" value="<?= (int)$brand['is_active'] ? 'archive' : 'restore' ?>">
                                <button class="catalog-icon-btn <?= (int)$brand['is_active'] ? 'warn' : 'success' ?>" type="submit" title="<?= (int)$brand['is_active'] ? 'Archive' : 'Restore' ?> <?= e($brand['name']) ?>" aria-label="<?= (int)$brand['is_active'] ? 'Archive' : 'Restore' ?> <?= e($brand['name']) ?>"><?= icon((int)$brand['is_active'] ? 'archive' : 'restore') ?></button>
                            </form>
                            <?php if (!(int)$brand['is_active'] && (int)$brand['model_count'] === 0 && (int)$brand['product_count'] === 0): ?>
                                <form method="post" action="actions/product_master.php" data-confirm="Delete this unused brand permanently?">
                                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="entity" value="brand"><input type="hidden" name="return_view" value="devices"><input type="hidden" name="id" value="<?= (int)$brand['id'] ?>"><input type="hidden" name="action" value="delete">
                                    <button class="catalog-icon-btn danger" type="submit" title="Delete <?= e($brand['name']) ?>" aria-label="Delete <?= e($brand['name']) ?>"><?= icon('trash') ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <span class="brand-master-arrow">›</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if (!$brands): ?>
                <div class="empty-state small brand-master-empty"><strong>No brands yet</strong><span>Add the first brand to start your catalog.</span></div>
            <?php endif; ?>
        </div>

        <?php if ($isOwner): ?>
            <div class="master-card-footer">
                <a href="<?= e(product_master_url(['status' => $showArchived ? null : 'all'])) ?>"><?= $showArchived ? 'Hide archived records' : 'Show archived records' ?></a>
            </div>
        <?php endif; ?>
    </section>

    <section class="card master-surface-card models-card">
        <div class="card-header master-card-header">
            <div>
                <span class="section-kicker">MODELS</span>
                <h2><?= $selectedBrandId ? e($selectedBrandName) . ' Models' : 'All Device Models' ?></h2>
                <p>Phone and tablet models available in the Product Master.</p>
            </div>
            <div class="master-card-title-actions">
                <span class="count-badge"><?= count($models) ?></span>
                <?php if ($canAddMaster): ?>
                    <button class="btn btn-primary btn-sm" type="button" data-master-open="model" data-mode="add"<?= $selectedBrandId ? ' data-brand-id="'.(int)$selectedBrandId.'"' : '' ?>><?= icon('plus') ?> Add Model</button>
                <?php endif; ?>
            </div>
        </div>

        <form class="master-filter modern-filter" method="get" action="index.php">
            <input type="hidden" name="page" value="products">
            <input type="hidden" name="view" value="devices">
            <?php if ($selectedBrandId): ?><input type="hidden" name="brand" value="<?= (int)$selectedBrandId ?>"><?php endif; ?>
            <?php if ($showArchived): ?><input type="hidden" name="status" value="all"><?php endif; ?>
            <div class="search-box"><?= icon('search') ?><input name="q" value="<?= e($search) ?>" placeholder="Search model or brand..."></div>
            <button class="btn btn-secondary" type="submit">Search</button>
            <?php if ($search !== ''): ?><a class="btn btn-ghost" href="<?= e(product_master_url(['q' => null])) ?>">Reset</a><?php endif; ?>
        </form>

        <div class="table-wrap master-table-wrap">
            <table class="data-table compact-table master-model-table modern-master-table">
                <thead><tr><th>Brand</th><th>Model</th><th>Device Type</th><th>Status</th><th class="action-col">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($models as $model): ?>
                    <tr class="<?= !(int)$model['is_active'] ? 'archived-row' : '' ?>">
                        <td><span class="table-brand-chip"><span><?= e(strtoupper(substr($model['brand_name'], 0, 2))) ?></span><?= e($model['brand_name']) ?></span></td>
                        <td><strong><?= e($model['name']) ?></strong><span class="table-subtext"><?= (int)$model['product_count'] ?> variant<?= (int)$model['product_count'] === 1 ? '' : 's' ?></span></td>
                        <td><span class="device-type-chip <?= e($model['device_type']) ?>"><?= icon($model['device_type'] === 'tablet' ? 'tablet' : 'phone') ?> <?= e(ucfirst($model['device_type'])) ?></span></td>
                        <td><span class="status-pill <?= (int)$model['is_active'] ? 'available' : 'low' ?>"><?= (int)$model['is_active'] ? 'Active' : 'Archived' ?></span></td>
                        <td>
                            <div class="master-table-actions">
                                <a class="table-action-btn primary-lite" href="<?= e(product_master_url(['brand'=>(int)$model['brand_id'],'model'=>(int)$model['id'],'q'=>null])) ?>#configurations"><?= icon('inventory') ?><span>Variants</span></a>
                                <?php if ($canEditModel): ?>
                                    <button class="table-action-btn" type="button" data-master-open="model" data-mode="edit" data-id="<?= (int)$model['id'] ?>" data-brand-id="<?= (int)$model['brand_id'] ?>" data-name="<?= e($model['name']) ?>" data-device-type="<?= e($model['device_type']) ?>" data-used="<?= (int)$model['product_count'] ?>"><?= icon('edit') ?><span>Edit</span></button>
                                <?php endif; ?>
                                <?php if ($isOwner): ?>
                                    <form method="post" action="actions/product_master.php" data-confirm="<?= (int)$model['is_active'] ? 'Archive this model? Existing inventory and history will remain.' : 'Restore this model?' ?>">
                                        <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="entity" value="model"><input type="hidden" name="return_view" value="devices"><input type="hidden" name="id" value="<?= (int)$model['id'] ?>"><input type="hidden" name="action" value="<?= (int)$model['is_active'] ? 'archive' : 'restore' ?>">
                                        <button class="table-action-btn <?= (int)$model['is_active'] ? 'warn' : 'success' ?>" type="submit"><?= icon((int)$model['is_active'] ? 'archive' : 'restore') ?><span><?= (int)$model['is_active'] ? 'Archive' : 'Restore' ?></span></button>
                                    </form>
                                    <?php if (!(int)$model['is_active'] && (int)$model['product_count'] === 0): ?>
                                        <form method="post" action="actions/product_master.php" data-confirm="Delete this unused model permanently?">
                                            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="entity" value="model"><input type="hidden" name="return_view" value="devices"><input type="hidden" name="id" value="<?= (int)$model['id'] ?>"><input type="hidden" name="action" value="delete">
                                            <button class="table-action-btn danger" type="submit"><?= icon('trash') ?><span>Delete</span></button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$models): ?>
                    <tr><td colspan="5"><div class="empty-state small"><strong>No models found</strong><span>Add a model or change the current filter.</span></div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<section class="card master-surface-card configuration-master-card" id="variants">
    <div class="card-header master-card-header configuration-master-header">
        <div>
            <span class="section-kicker">VARIANTS</span>
            <h2><?= $selectedModel ? e($selectedModel['brand_name'].' '.$selectedModel['name']) : 'Select a Model' ?></h2>
            <p><?= $selectedModel ? 'Available options for this model. Serial Numbers / IMEIs are added only when physical stock arrives.' : 'Choose Variants on a model above to view its storage, RAM, color or connectivity options.' ?></p>
        </div>
        <?php if ($selectedModel && $canAddMaster && (int)$selectedModel['is_active'] && (int)$selectedModel['brand_active']): ?>
            <a class="btn btn-primary btn-sm" href="index.php?page=add-item&model_id=<?= (int)$selectedModel['id'] ?>"><?= icon('plus') ?> Add Variant</a>
        <?php endif; ?>
    </div>

    <?php if (!$selectedModel): ?>
        <div class="empty-state configuration-empty"><div class="empty-icon"><?= icon('products') ?></div><strong>No model selected</strong><span>Select a model's Variants action to view its available options.</span></div>
    <?php else: ?>
        <?php if (!$pricingReady): ?><div class="alert alert-info variant-migration-note"><strong>Pricing setup required.</strong> Run <code>database/P2_004_pricing_variant_serial_ux.sql</code> before editing branch prices.</div><?php endif; ?>
        <div class="table-wrap master-table-wrap">
            <table class="data-table compact-table configuration-table">
                <thead><tr><th>Variant</th><th>Selling Price</th><th>Stock</th><th>Status</th><th class="action-col">Actions</th></tr></thead>
                <tbody>
                <?php foreach($configurations as $config):
                    $parts=[]; foreach(['ram','storage','connectivity','color'] as $key) if(!empty($config[$key])) $parts[]=$config[$key];
                    $specs=$parts?implode(' • ',$parts):'Standard';
                    $branchPricesForVariant = $priceMap[(int)$config['id']] ?? [];
                    $shownPrice = null; $priceNote = '';
                    if ($priceBranchId) {
                        $shownPrice = $branchPricesForVariant[(int)$priceBranchId] ?? (float)$config['selling_price'];
                        foreach ($priceBranches as $pb) if ((int)$pb['id']===(int)$priceBranchId) { $priceNote=$pb['name']; break; }
                    } else {
                        $allPrices=[]; foreach($priceBranches as $pb) $allPrices[]=(float)($branchPricesForVariant[(int)$pb['id']] ?? $config['selling_price']);
                        $unique=array_values(array_unique(array_map(fn($v)=>number_format($v,2,'.',''),$allPrices)));
                        if(count($unique)===1 && $allPrices) $shownPrice=(float)$allPrices[0]; else $priceNote='Varies by branch';
                    }
                ?>
                    <tr class="<?= !(int)$config['is_active'] ? 'archived-row' : '' ?>">
                        <td><strong><?= e($specs) ?></strong></td>
                        <td><?php if($shownPrice!==null): ?><strong><?= peso($shownPrice) ?></strong><span class="table-subtext"><?= e($priceNote ?: 'All branches') ?></span><?php else: ?><strong>Varies by branch</strong><span class="table-subtext">Select a branch above to view its price</span><?php endif; ?></td>
                        <td><strong><?= number_format((int)$config['available_count']) ?> available</strong><span class="table-subtext"><?= number_format((int)$config['unit_count']) ?> tracked</span></td>
                        <td><span class="status-pill <?= (int)$config['is_active']?'available':'low' ?>"><?= (int)$config['is_active']?'Active':'Archived' ?></span></td>
                        <td><div class="master-table-actions">
                            <?php if ($canEditVariantPrice): ?>
                                <button class="table-action-btn" type="button" data-master-open="configuration" data-mode="edit" data-id="<?= (int)$config['id'] ?>" data-cost-price="<?= e((string)$config['cost_price']) ?>" data-selling-price="<?= e((string)($priceBranchId ? ($branchPricesForVariant[(int)$priceBranchId] ?? $config['selling_price']) : $config['selling_price'])) ?>" data-branch-prices='<?= e(json_encode($branchPricesForVariant, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>' data-config-label="<?= e($selectedModel['brand_name'].' '.$selectedModel['name'].' • '.$specs) ?>"><?= icon('edit') ?><span>Edit</span></button>
                            <?php endif; ?>
                            <?php if ($isOwner): ?>
                                <form method="post" action="actions/product_master.php" data-confirm="<?= (int)$config['is_active']?'Archive this variant? Existing stock and history will remain.':'Restore this variant?' ?>">
                                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="entity" value="configuration"><input type="hidden" name="return_view" value="devices"><input type="hidden" name="return_model" value="<?= (int)$selectedModel['id'] ?>"><input type="hidden" name="id" value="<?= (int)$config['id'] ?>"><input type="hidden" name="action" value="<?= (int)$config['is_active']?'archive':'restore' ?>">
                                    <button class="table-action-btn <?= (int)$config['is_active']?'warn':'success' ?>" type="submit"><?= icon((int)$config['is_active']?'archive':'restore') ?><span><?= (int)$config['is_active']?'Archive':'Restore' ?></span></button>
                                </form>
                                <?php if (!(int)$config['is_active'] && (int)$config['unit_count']===0 && (int)$config['movement_count']===0): ?>
                                <form method="post" action="actions/product_master.php" data-confirm="Delete this unused variant permanently?">
                                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="entity" value="configuration"><input type="hidden" name="return_view" value="devices"><input type="hidden" name="return_model" value="<?= (int)$selectedModel['id'] ?>"><input type="hidden" name="id" value="<?= (int)$config['id'] ?>"><input type="hidden" name="action" value="delete">
                                    <button class="table-action-btn danger" type="submit"><?= icon('trash') ?><span>Delete</span></button>
                                </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                <?php if(!$configurations): ?><tr><td colspan="5"><div class="empty-state small"><strong>No variants yet</strong><span>Add the first storage/spec option for this model.</span><?php if($canAddMaster): ?><a class="btn btn-primary btn-sm" href="index.php?page=add-item&model_id=<?= (int)$selectedModel['id'] ?>">Add Variant</a><?php endif; ?></div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php else: ?>
<section class="card master-surface-card accessory-catalog-card">
    <div class="card-header master-card-header accessory-master-header">
        <div>
            <span class="section-kicker">ACCESSORIES</span>
            <h2>Accessory Categories</h2>
            <p>Keep reusable accessory groups clean and easy to find when adding stock.</p>
        </div>
        <div class="master-card-title-actions">
            <span class="count-badge"><?= count($categories) ?></span>
            <?php if ($canAddMaster): ?>
                <a class="btn btn-soft-primary btn-sm" href="index.php?page=add-item&type=accessory"><?= icon('plus') ?> Add Accessory Product</a>
                <button class="btn btn-primary btn-sm" type="button" data-master-open="category" data-mode="add"><?= icon('plus') ?> Add Category</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="accessory-category-grid">
        <?php foreach ($categories as $category): ?>
            <article class="accessory-category-card <?= !(int)$category['is_active'] ? 'archived' : '' ?>">
                <div class="accessory-category-icon"><?= icon('accessory') ?></div>
                <div class="accessory-category-copy">
                    <strong><?= e($category['name']) ?></strong>
                    <span><?= (int)$category['product_count'] ?> product<?= (int)$category['product_count'] === 1 ? '' : 's' ?></span>
                </div>
                <span class="status-pill <?= (int)$category['is_active'] ? 'available' : 'low' ?>"><?= (int)$category['is_active'] ? 'Active' : 'Archived' ?></span>
                <div class="accessory-category-actions">
                    <?php if ($canEditCategory): ?>
                        <button class="table-action-btn" type="button" data-master-open="category" data-mode="edit" data-id="<?= (int)$category['id'] ?>" data-name="<?= e($category['name']) ?>"><?= icon('edit') ?><span>Edit</span></button>
                    <?php endif; ?>
                    <?php if ($isOwner): ?>
                        <form method="post" action="actions/product_master.php" data-confirm="<?= (int)$category['is_active'] ? 'Archive this category? Existing accessory records will remain.' : 'Restore this category?' ?>">
                            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="entity" value="category"><input type="hidden" name="return_view" value="accessories"><input type="hidden" name="id" value="<?= (int)$category['id'] ?>"><input type="hidden" name="action" value="<?= (int)$category['is_active'] ? 'archive' : 'restore' ?>">
                            <button class="table-action-btn <?= (int)$category['is_active'] ? 'warn' : 'success' ?>" type="submit"><?= icon((int)$category['is_active'] ? 'archive' : 'restore') ?><span><?= (int)$category['is_active'] ? 'Archive' : 'Restore' ?></span></button>
                        </form>
                        <?php if (!(int)$category['is_active'] && (int)$category['product_count'] === 0): ?>
                            <form method="post" action="actions/product_master.php" data-confirm="Delete this unused category permanently?">
                                <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="entity" value="category"><input type="hidden" name="return_view" value="accessories"><input type="hidden" name="id" value="<?= (int)$category['id'] ?>"><input type="hidden" name="action" value="delete">
                                <button class="table-action-btn danger" type="submit"><?= icon('trash') ?><span>Delete</span></button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$categories): ?>
            <div class="empty-state accessory-empty"><div class="empty-icon"><?= icon('accessory') ?></div><strong>No accessory categories yet</strong><span>Add categories such as Chargers, Cables, Cases or Earphones.</span></div>
        <?php endif; ?>
    </div>

    <?php if ($isOwner): ?>
        <div class="master-card-footer"><a href="<?= e(product_master_url(['status' => $showArchived ? null : 'all'])) ?>"><?= $showArchived ? 'Hide archived records' : 'Show archived records' ?></a></div>
    <?php endif; ?>
</section>
<?php endif; ?>

<div class="info-strip product-master-info"><?= icon('shield') ?><div><strong>Products are shared; stock stays by branch.</strong><span>Branches reuse the same brands, models and variants without sharing physical stock.</span></div></div>

<?php if ($canAddMaster): ?>
<div class="modal master-modal" id="masterBrandModal" hidden>
    <div class="modal-backdrop" data-master-close></div>
    <div class="modal-dialog master-modal-dialog">
        <div class="modal-header"><div><span class="eyebrow">PRODUCT MASTER</span><h2 data-master-title>Add Brand</h2><p class="modal-subtitle">Create a reusable device brand for all branches.</p></div><button type="button" class="icon-button" data-master-close aria-label="Close">×</button></div>
        <form method="post" action="actions/product_master.php" class="master-modal-form">
            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="entity" value="brand"><input type="hidden" name="return_view" value="devices"><input type="hidden" name="action" value="add" data-action-field><input type="hidden" name="id" value="" data-id-field>
            <label class="field"><span>Brand Name <b>*</b></span><input name="name" data-name-field maxlength="100" placeholder="e.g. Apple" required><small>Brand names are shared across all branches.</small></label>
            <div class="master-modal-actions"><button type="button" class="btn btn-secondary" data-master-close>Cancel</button><button type="submit" class="btn btn-primary">Save Brand</button></div>
        </form>
    </div>
</div>

<div class="modal master-modal" id="masterModelModal" hidden>
    <div class="modal-backdrop" data-master-close></div>
    <div class="modal-dialog master-modal-dialog">
        <div class="modal-header"><div><span class="eyebrow">PRODUCT MASTER</span><h2 data-master-title>Add Model</h2><p class="modal-subtitle">Assign the model to a brand and device type.</p></div><button type="button" class="icon-button" data-master-close aria-label="Close">×</button></div>
        <form method="post" action="actions/product_master.php" class="master-modal-form">
            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="entity" value="model"><input type="hidden" name="return_view" value="devices"><input type="hidden" name="action" value="add" data-action-field><input type="hidden" name="id" value="" data-id-field>
            <div class="form-grid two">
                <label class="field"><span>Brand <b>*</b></span><select name="brand_id" data-brand-field required><option value="">Select brand</option><?php foreach ($brands as $brand): if (!(int)$brand['is_active']) continue; ?><option value="<?= (int)$brand['id'] ?>"><?= e($brand['name']) ?></option><?php endforeach; ?></select></label>
                <label class="field"><span>Device Type <b>*</b></span><select name="device_type" data-type-field required><option value="phone">Phone</option><option value="tablet">Tablet</option></select></label>
                <label class="field span-2"><span>Model Name <b>*</b></span><input name="name" data-name-field maxlength="150" placeholder="e.g. iPhone 17 Pro" required><small data-model-edit-note>Choose whether the model is a Phone or Tablet.</small></label>
            </div>
            <div class="master-modal-actions"><button type="button" class="btn btn-secondary" data-master-close>Cancel</button><button type="submit" class="btn btn-primary">Save Model</button></div>
        </form>
    </div>
</div>

<div class="modal master-modal" id="masterCategoryModal" hidden>
    <div class="modal-backdrop" data-master-close></div>
    <div class="modal-dialog master-modal-dialog">
        <div class="modal-header"><div><span class="eyebrow">ACCESSORIES</span><h2 data-master-title>Add Category</h2><p class="modal-subtitle">Create a clean reusable group for accessory items.</p></div><button type="button" class="icon-button" data-master-close aria-label="Close">×</button></div>
        <form method="post" action="actions/product_master.php" class="master-modal-form">
            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="entity" value="category"><input type="hidden" name="return_view" value="accessories"><input type="hidden" name="action" value="add" data-action-field><input type="hidden" name="id" value="" data-id-field>
            <label class="field"><span>Category Name <b>*</b></span><input name="name" data-name-field maxlength="100" placeholder="e.g. Chargers" required><small>Keep category names short and reusable.</small></label>
            <div class="master-modal-actions"><button type="button" class="btn btn-secondary" data-master-close>Cancel</button><button type="submit" class="btn btn-primary">Save Category</button></div>
        </form>
    </div>
</div>


<?php if ($canEditVariantPrice): ?>
<div class="modal master-modal" id="masterConfigurationModal" hidden>
    <div class="modal-backdrop" data-master-close></div>
    <div class="modal-dialog master-modal-dialog variant-price-dialog">
        <div class="modal-header"><div><span class="eyebrow">VARIANT</span><h2 data-master-title>Edit Variant</h2><p class="modal-subtitle" data-config-modal-label>Update pricing for this variant.</p></div><button type="button" class="icon-button" data-master-close aria-label="Close">×</button></div>
        <form method="post" action="actions/product_master.php" class="master-modal-form">
            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="entity" value="configuration"><input type="hidden" name="return_view" value="devices"><input type="hidden" name="return_model" value="<?= (int)($selectedModel['id'] ?? 0) ?>"><input type="hidden" name="action" value="edit" data-action-field><input type="hidden" name="id" value="" data-id-field>
            <?php if ($isOwner): ?>
                <label class="field"><span>Cost Price / Unit <b>*</b></span><div class="money-input"><span>₱</span><input type="number" step="0.01" min="0" name="cost_price" data-cost-field required></div><small>This is protected from branch accounts and used for newly received units.</small></label>
                <div class="variant-price-section"><div class="variant-price-heading"><strong>Selling Price by Branch</strong><span>Each branch can use its own POS price.</span></div><div class="variant-branch-price-list">
                <?php foreach ($priceBranches as $pb): ?><label class="variant-branch-price-row"><span><?= e($pb['name']) ?></span><div class="money-input compact"><span>₱</span><input type="number" step="0.01" min="0.01" name="branch_prices[<?= (int)$pb['id'] ?>]" data-branch-price="<?= (int)$pb['id'] ?>" required></div></label><?php endforeach; ?>
                </div></div>
            <?php else: ?>
                <label class="field"><span>Selling Price — <?= e(Auth::user()['branch_name'] ?? 'Your Branch') ?> <b>*</b></span><div class="money-input"><span>₱</span><input type="number" step="0.01" min="0.01" name="selling_price" data-selling-field required></div><small>This is the price your branch will use in POS.</small></label>
            <?php endif; ?>
            <div class="master-modal-actions"><button type="button" class="btn btn-secondary" data-master-close>Cancel</button><button type="submit" class="btn btn-primary">Save Changes</button></div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>
