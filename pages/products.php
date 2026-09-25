<?php
$role = Auth::user()['role'] ?? '';
$isOwner = Auth::isOwner();
$canAddMaster = in_array($role, ['owner', 'branch_manager', 'inventory'], true);
$canQuickReceive = $canAddMaster;
$quickSetupAfterCreate = (($_GET['setup'] ?? '') === '1');
$quickStockDone = (($_GET['stock_done'] ?? '') === '1');
$quickCompletedVariantId = filter_input(INPUT_GET, 'variant', FILTER_VALIDATE_INT) ?: 0;
$quickCompletedVariant = null;
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
$archiveMode = $isOwner && in_array(($_GET['status'] ?? ''), ['archived','all'], true);
$showArchived = $archiveMode;
$activeView = (($_GET['view'] ?? 'devices') === 'accessories') ? 'accessories' : 'devices';
$schemaReady = false;
$catalogDeleteReady = false;
$brands = $models = $categories = $configurations = [];
$archivedBrands = $archivedModels = $archivedVariants = $archivedCategories = [];
$selectedModel = null;

try {
    $schemaReady = (bool)Database::query("SHOW COLUMNS FROM product_models LIKE 'device_type'")->fetch();
    $catalogDeleteReady = (bool)Database::query("SHOW COLUMNS FROM products LIKE 'catalog_deleted_at'")->fetch();

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

    $catalogProductFilter = $catalogDeleteReady ? " AND p.catalog_deleted_at IS NULL" : "";
    $modelSql = "SELECT pm.id,pm.brand_id,pm.name,pm.is_active,b.name AS brand_name,b.is_active AS brand_active,
                        " . ($schemaReady ? "pm.device_type" : "'phone' AS device_type") . ",
                        (SELECT COUNT(*) FROM products p WHERE p.model_id=pm.id AND p.product_type IN ('phone','tablet'){$catalogProductFilter}) AS product_count,
                        (SELECT COUNT(*) FROM products p WHERE p.model_id=pm.id AND p.product_type IN ('phone','tablet') AND p.is_active=1{$catalogProductFilter}) AS active_variant_count,
                        (SELECT COUNT(*) FROM products p WHERE p.model_id=pm.id AND p.product_type IN ('phone','tablet') AND p.is_active=0{$catalogProductFilter}) AS archived_variant_count
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
        $selectedModelSql = "SELECT pm.id,pm.brand_id,pm.name,pm.is_active,COALESCE(pm.device_type,'phone') device_type,b.name brand_name,b.is_active brand_active
             FROM product_models pm JOIN brands b ON b.id=pm.brand_id WHERE pm.id=?";
        if (!$showArchived) $selectedModelSql .= " AND pm.is_active=1 AND b.is_active=1";
        $selectedModelSql .= " LIMIT 1";
        $selectedModel = Database::query($selectedModelSql, [$selectedModelId])->fetch();
        if (!$selectedModel && !$showArchived) {
            // An archived model must not keep rendering a stale Variants panel in the active catalog.
            $selectedModelId = null;
        }
        if ($selectedModel) {
            $selectedBrandId = (int)$selectedModel['brand_id'];
            $configWhere = ($isOwner && $showArchived) ? 'p.is_active=0' : 'p.is_active=1';
            if ($catalogDeleteReady) $configWhere .= ' AND p.catalog_deleted_at IS NULL';
            $configurations = Database::query(
                "SELECT p.id,p.product_type,p.ram,p.storage,p.color,p.connectivity,p.cost_price,p.selling_price,p.is_active,
                        (SELECT COUNT(*) FROM inventory_units iu WHERE iu.product_id=p.id) unit_count,
                        (SELECT COUNT(*) FROM inventory_units ia WHERE ia.product_id=p.id AND ia.status='available') available_count,
                        (SELECT COUNT(*) FROM inventory_units il WHERE il.product_id=p.id AND il.status IN ('available','reserved','sold','transferred')) locked_unit_count,
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

if ($quickStockDone && $quickCompletedVariantId && $selectedModel) {
    foreach ($configurations as $configuration) {
        if ((int)$configuration['id'] === (int)$quickCompletedVariantId) {
            $quickCompletedVariant = $configuration;
            break;
        }
    }
}

if ($archiveMode) {
    try {
        $archivedBrands = Database::query(
            "SELECT b.id,b.name,
                    (SELECT COUNT(*) FROM product_models pm WHERE pm.brand_id=b.id) model_count,
                    (SELECT COUNT(*) FROM products p WHERE p.brand_id=b.id) product_count
             FROM brands b
             WHERE b.is_active=0
             ORDER BY b.name"
        )->fetchAll();

        $archivedModels = Database::query(
            "SELECT pm.id,pm.name,pm.device_type,b.name brand_name,b.is_active brand_active,
                    (SELECT COUNT(*) FROM products p WHERE p.model_id=pm.id) product_count
             FROM product_models pm
             JOIN brands b ON b.id=pm.brand_id
             WHERE pm.is_active=0
             ORDER BY b.name,pm.name"
        )->fetchAll();

        $deletedFilter = $catalogDeleteReady ? ' AND p.catalog_deleted_at IS NULL' : '';
        $archivedVariants = Database::query(
            "SELECT p.id,p.product_type,p.ram,p.storage,p.connectivity,p.color,
                    b.name brand_name,pm.name model_name,
                    (SELECT COUNT(*) FROM inventory_units iu WHERE iu.product_id=p.id) tracked_units,
                    (SELECT COUNT(*) FROM inventory_units iu WHERE iu.product_id=p.id AND iu.status IN ('available','reserved')) open_units,
                    (SELECT COUNT(*) FROM stock_movements sm WHERE sm.product_id=p.id) movement_count
             FROM products p
             JOIN brands b ON b.id=p.brand_id
             JOIN product_models pm ON pm.id=p.model_id
             WHERE p.product_type IN ('phone','tablet') AND p.is_active=0{$deletedFilter}
             ORDER BY b.name,pm.name,p.ram,p.storage,p.connectivity,p.color"
        )->fetchAll();

        $archivedCategories = Database::query(
            "SELECT c.id,c.name,(SELECT COUNT(*) FROM products p WHERE p.category_id=c.id) product_count
             FROM categories c
             WHERE c.is_active=0
             ORDER BY c.name"
        )->fetchAll();
    } catch (Throwable $e) {
        flash('error', 'Unable to load archived products.');
    }
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
        <p>Manage device brands, models, variants and accessories. Start with Add Model when setting up new deliveries.</p>
    </div>
</section>

<?php if ($isOwner): ?>
<nav class="product-state-tabs" aria-label="Product status">
    <a class="product-state-tab <?= !$archiveMode ? 'active' : '' ?>" href="index.php?page=products">
        <span class="product-state-tab-icon"><?= icon('products') ?></span>
        <span><strong>Active Products</strong><small>Current brands, models and variants</small></span>
    </a>
    <a class="product-state-tab <?= $archiveMode ? 'active archived' : '' ?>" href="index.php?page=products&status=archived">
        <span class="product-state-tab-icon"><?= icon('archive') ?></span>
        <span><strong>Archived</strong><small>Restore or delete old product records</small></span>
    </a>
</nav>
<?php endif; ?>

<?php if ($archiveMode): ?>
<section class="archive-overview-card card">
    <div class="archive-overview-copy">
        <span class="section-kicker">ARCHIVE</span>
        <h2>Archived Products</h2>
        <p>Items here are hidden from normal product setup and inventory. Restore them anytime, or delete records that are safe to remove.</p>
    </div>
    <div class="archive-summary-grid">
        <div><strong><?= count($archivedVariants) ?></strong><span>Variants</span></div>
        <div><strong><?= count($archivedModels) ?></strong><span>Models</span></div>
        <div><strong><?= count($archivedBrands) ?></strong><span>Brands</span></div>
        <div><strong><?= count($archivedCategories) ?></strong><span>Accessory Categories</span></div>
    </div>
</section>

<div class="archive-sections">
    <section class="card archive-section-card">
        <div class="card-header master-card-header">
            <div><span class="section-kicker">VARIANTS</span><h2>Archived Variants</h2><p>Restore a variant or remove it from Product Setup. Historical stock and sales stay protected.</p></div>
            <span class="count-badge"><?= count($archivedVariants) ?></span>
        </div>
        <div class="table-wrap master-table-wrap">
            <table class="data-table compact-table modern-master-table archive-table">
                <thead><tr><th>Product</th><th>Variant</th><th>History</th><th class="action-col">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($archivedVariants as $item):
                    $parts=[]; foreach(['ram','storage','connectivity','color'] as $key) if(!empty($item[$key])) $parts[]=$item[$key];
                    $specs=$parts?implode(' • ',$parts):'Standard';
                    $canDeleteVariant=(int)$item['open_units']===0;
                ?>
                <tr>
                    <td><strong><?= e($item['brand_name'].' '.$item['model_name']) ?></strong><span class="table-subtext"><?= e(ucfirst($item['product_type'])) ?></span></td>
                    <td><strong><?= e($specs) ?></strong></td>
                    <td><strong><?= number_format((int)$item['tracked_units']) ?> tracked</strong><span class="table-subtext"><?= number_format((int)$item['movement_count']) ?> stock movement<?= (int)$item['movement_count']===1?'':'s' ?></span></td>
                    <td><div class="master-table-actions archive-actions">
                        <form method="post" action="actions/product_master.php">
                            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="entity" value="configuration"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="return_archive" value="1">
                            <button class="table-action-btn success" type="submit"><?= icon('restore') ?><span>Restore</span></button>
                        </form>
                        <form method="post" action="actions/product_master.php" data-confirm="Delete this archived variant from Product Setup? Past stock and sales history will still be kept for audit.">
                            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="entity" value="configuration"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="return_archive" value="1">
                            <button class="table-action-btn danger" type="submit" <?= $canDeleteVariant?'':'disabled title="This variant still has available or reserved units"' ?>><?= icon('trash') ?><span>Delete</span></button>
                        </form>
                    </div></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$archivedVariants): ?><tr><td colspan="4"><div class="empty-state small"><strong>No archived variants</strong><span>Archived variants will appear here.</span></div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="archive-secondary-grid">
        <section class="card archive-section-card">
            <div class="card-header master-card-header"><div><span class="section-kicker">MODELS</span><h2>Archived Models</h2></div><span class="count-badge"><?= count($archivedModels) ?></span></div>
            <div class="archive-list">
                <?php foreach($archivedModels as $item): ?>
                <div class="archive-list-row">
                    <div><strong><?= e($item['brand_name'].' '.$item['name']) ?></strong><span><?= e(ucfirst($item['device_type'])) ?> • <?= (int)$item['product_count'] ?> variant<?= (int)$item['product_count']===1?'':'s' ?></span></div>
                    <div class="master-table-actions">
                        <form method="post" action="actions/product_master.php"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="entity" value="model"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="return_archive" value="1"><button class="table-action-btn success" type="submit"><?= icon('restore') ?><span>Restore</span></button></form>
                        <?php if ((int)$item['product_count']===0): ?><form method="post" action="actions/product_master.php" data-confirm="Delete this unused model permanently?"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="entity" value="model"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="return_archive" value="1"><button class="table-action-btn danger" type="submit"><?= icon('trash') ?><span>Delete</span></button></form><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if(!$archivedModels): ?><div class="empty-state small"><strong>No archived models</strong></div><?php endif; ?>
            </div>
        </section>

        <section class="card archive-section-card">
            <div class="card-header master-card-header"><div><span class="section-kicker">BRANDS</span><h2>Archived Brands</h2></div><span class="count-badge"><?= count($archivedBrands) ?></span></div>
            <div class="archive-list">
                <?php foreach($archivedBrands as $item): ?>
                <div class="archive-list-row">
                    <div><strong><?= e($item['name']) ?></strong><span><?= (int)$item['model_count'] ?> model<?= (int)$item['model_count']===1?'':'s' ?></span></div>
                    <div class="master-table-actions">
                        <form method="post" action="actions/product_master.php"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="entity" value="brand"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="return_archive" value="1"><button class="table-action-btn success" type="submit"><?= icon('restore') ?><span>Restore</span></button></form>
                        <?php if ((int)$item['model_count']===0 && (int)$item['product_count']===0): ?><form method="post" action="actions/product_master.php" data-confirm="Delete this unused brand permanently?"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="entity" value="brand"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="return_archive" value="1"><button class="table-action-btn danger" type="submit"><?= icon('trash') ?><span>Delete</span></button></form><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if(!$archivedBrands): ?><div class="empty-state small"><strong>No archived brands</strong></div><?php endif; ?>
            </div>
        </section>
    </div>

    <section class="card archive-section-card">
        <div class="card-header master-card-header"><div><span class="section-kicker">ACCESSORIES</span><h2>Archived Accessory Categories</h2></div><span class="count-badge"><?= count($archivedCategories) ?></span></div>
        <div class="archive-category-grid">
            <?php foreach($archivedCategories as $item): ?>
            <div class="archive-category-item"><div><strong><?= e($item['name']) ?></strong><span><?= (int)$item['product_count'] ?> product<?= (int)$item['product_count']===1?'':'s' ?></span></div><div class="master-table-actions"><form method="post" action="actions/product_master.php"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="entity" value="category"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="return_archive" value="1"><button class="table-action-btn success" type="submit"><?= icon('restore') ?><span>Restore</span></button></form><?php if((int)$item['product_count']===0): ?><form method="post" action="actions/product_master.php" data-confirm="Delete this unused category permanently?"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="entity" value="category"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="return_archive" value="1"><button class="table-action-btn danger" type="submit"><?= icon('trash') ?><span>Delete</span></button></form><?php endif; ?></div></div>
            <?php endforeach; ?>
            <?php if(!$archivedCategories): ?><div class="empty-state small"><strong>No archived accessory categories</strong></div><?php endif; ?>
        </div>
    </section>
</div>

<?php else: ?>

<?php if (!$schemaReady): ?>
    <div class="alert alert-info"><strong>P1-006 migration required.</strong> Run <code>database/P1_006_product_master.sql</code> in phpMyAdmin before adding or editing models.</div>
<?php endif; ?>
<?php if ($isOwner && $showArchived && !$catalogDeleteReady): ?>
    <div class="alert alert-info"><strong>Archive cleanup update required.</strong> Run <code>database/P2_009_archive_delete_cleanup.sql</code> before deleting archived variants that already have history.</div>
<?php endif; ?>

<section class="card product-quick-start" aria-labelledby="productQuickStartTitle">
    <div class="product-quick-start-head">
        <div>
            <span class="section-kicker">QUICK START</span>
            <h2 id="productQuickStartTitle"><?= $activeView === 'devices' ? 'Set up products faster' : 'Manage accessories faster' ?></h2>
            <p><?= $activeView === 'devices' ? 'Use the common actions below, then browse brands or models only when you need them.' : 'Add accessory products or categories, or return to the device catalog.' ?></p>
        </div>
        <span class="product-view-context"><?= $activeView === 'devices' ? 'Device Catalog' : 'Accessories' ?></span>
    </div>

    <div class="product-quick-actions">
        <?php if ($activeView === 'devices'): ?>
            <?php if ($canAddMaster): ?>
                <button class="product-quick-action product-quick-primary" type="button" data-master-open="model" data-mode="add"<?= $selectedBrandId ? ' data-brand-id="'.(int)$selectedBrandId.'"' : '' ?>>
                    <span class="product-quick-icon"><?= icon('plus') ?></span>
                    <span class="product-quick-copy"><strong>Add Model</strong><small>Add a new phone or tablet model</small></span>
                    <span class="product-quick-arrow">›</span>
                </button>
                <button class="product-quick-action" type="button" data-master-open="brand" data-mode="add">
                    <span class="product-quick-icon"><?= icon('tag') ?></span>
                    <span class="product-quick-copy"><strong>Add Brand</strong><small>Create a reusable brand</small></span>
                    <span class="product-quick-arrow">›</span>
                </button>
            <?php endif; ?>
            <a class="product-quick-action" href="#models">
                <span class="product-quick-icon"><?= icon('inventory') ?></span>
                <span class="product-quick-copy"><strong>View Variants</strong><small>Open a model to manage variants</small></span>
                <span class="product-quick-arrow">›</span>
            </a>
            <a class="product-quick-action" href="<?= e(product_master_url(['view' => 'accessories', 'brand' => null, 'model' => null, 'q' => null])) ?>">
                <span class="product-quick-icon accessory"><?= icon('accessory') ?></span>
                <span class="product-quick-copy"><strong>Accessories</strong><small><?= count($categories) ?> categor<?= count($categories) === 1 ? 'y' : 'ies' ?></small></span>
                <span class="product-quick-arrow">›</span>
            </a>
        <?php else: ?>
            <?php if ($canAddMaster): ?>
                <a class="product-quick-action product-quick-primary" href="index.php?page=add-item&type=accessory">
                    <span class="product-quick-icon"><?= icon('plus') ?></span>
                    <span class="product-quick-copy"><strong>Add Accessory</strong><small>Create a new accessory product</small></span>
                    <span class="product-quick-arrow">›</span>
                </a>
                <button class="product-quick-action" type="button" data-master-open="category" data-mode="add">
                    <span class="product-quick-icon accessory"><?= icon('accessory') ?></span>
                    <span class="product-quick-copy"><strong>Add Category</strong><small>Create a reusable category</small></span>
                    <span class="product-quick-arrow">›</span>
                </button>
            <?php endif; ?>
            <a class="product-quick-action" href="<?= e(product_master_url(['view' => 'devices', 'brand' => null, 'model' => null, 'q' => null])) ?>">
                <span class="product-quick-icon"><?= icon('phone') ?></span>
                <span class="product-quick-copy"><strong>Device Catalog</strong><small>Browse brands and device models</small></span>
                <span class="product-quick-arrow">›</span>
            </a>
            <?php if ($canAddMaster): ?>
                <button class="product-quick-action" type="button" data-master-open="model" data-mode="add">
                    <span class="product-quick-icon"><?= icon('plus') ?></span>
                    <span class="product-quick-copy"><strong>Add Model</strong><small>Add a new phone or tablet model</small></span>
                    <span class="product-quick-arrow">›</span>
                </button>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php if ($activeView === 'devices'): ?>
<div class="two-column product-master-grid product-master-devices">
    <section class="card master-surface-card product-brands-card" id="brands">
        <div class="card-header master-card-header product-section-header">
            <div>
                <span class="section-kicker">BRANDS</span>
                <h2>Device Brands</h2>
                <p>Choose a brand to see its models.</p>
            </div>

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
                        <?= brand_logo_html($brand['name'], 'product-master-brand-logo') ?>
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
                <a href="<?= e(product_master_url(['status' => $showArchived ? null : 'archived'])) ?>"><?= $showArchived ? 'Back to Active' : 'View Archived' ?></a>
            </div>
        <?php endif; ?>
    </section>

    <section class="card master-surface-card models-card" id="models">
        <div class="card-header master-card-header product-section-header">
            <div>
                <span class="section-kicker">MODELS</span>
                <h2><?= $selectedBrandId ? e($selectedBrandName) . ' Models' : 'All Device Models' ?></h2>
                <p>Phone and tablet models available in the Product Master.</p>
            </div>
            <div class="master-card-title-actions">
                <span class="count-badge"><?= count($models) ?></span>
                <?php if ($canAddMaster): ?>
                    <button class="btn btn-primary btn-sm product-model-header-add" type="button" data-master-open="model" data-mode="add"<?= $selectedBrandId ? ' data-brand-id="'.(int)$selectedBrandId.'"' : '' ?>><?= icon('plus') ?> Add Model</button>
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
                        <td class="model-brand-cell"><span class="table-brand-chip product-model-brand-cell"><?= brand_logo_html($model['brand_name'], 'product-model-brand-logo') ?><span class="product-model-brand-name"><?= e($model['brand_name']) ?></span></span></td>
                        <td class="model-name-cell">
                            <span class="model-mobile-brand"><?= brand_logo_html($model['brand_name'], 'product-model-brand-logo') ?><span><?= e($model['brand_name']) ?></span></span>
                            <strong><?= e($model['name']) ?></strong>
                            <span class="table-subtext"><?php if ($showArchived && (int)$model['archived_variant_count'] > 0): ?><?= (int)$model['active_variant_count'] ?> active • <?= (int)$model['archived_variant_count'] ?> archived<?php else: ?><?= (int)$model['active_variant_count'] ?> active variant<?= (int)$model['active_variant_count'] === 1 ? '' : 's' ?><?php endif; ?></span>
                            <span class="model-mobile-badges">
                                <span class="device-type-chip <?= e($model['device_type']) ?>"><?= icon($model['device_type'] === 'tablet' ? 'tablet' : 'phone') ?> <?= e(ucfirst($model['device_type'])) ?></span>
                                <span class="status-pill <?= (int)$model['is_active'] ? 'available' : 'low' ?>"><?= (int)$model['is_active'] ? 'Active' : 'Archived' ?></span>
                            </span>
                        </td>
                        <td class="model-type-cell"><span class="device-type-chip <?= e($model['device_type']) ?>"><?= icon($model['device_type'] === 'tablet' ? 'tablet' : 'phone') ?> <?= e(ucfirst($model['device_type'])) ?></span></td>
                        <td class="model-status-cell"><span class="status-pill <?= (int)$model['is_active'] ? 'available' : 'low' ?>"><?= (int)$model['is_active'] ? 'Active' : 'Archived' ?></span></td>
                        <td>
                            <div class="master-table-actions">
                                <a class="table-action-btn primary-lite model-variants-action" href="<?= e(product_master_url(['brand'=>(int)$model['brand_id'],'model'=>(int)$model['id'],'q'=>null])) ?>#variants"><?= icon('inventory') ?><span>Variants</span></a>
                                <?php if ($canEditModel): ?>
                                    <button class="table-action-btn model-edit-action" type="button" data-master-open="model" data-mode="edit" data-id="<?= (int)$model['id'] ?>" data-brand-id="<?= (int)$model['brand_id'] ?>" data-name="<?= e($model['name']) ?>" data-device-type="<?= e($model['device_type']) ?>" data-used="<?= (int)$model['product_count'] ?>"><?= icon('edit') ?><span>Edit</span></button>
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

<?php if ($selectedModel): ?>
<section class="card master-surface-card configuration-master-card" id="variants">
    <div class="card-header master-card-header configuration-master-header">
        <div>
            <span class="section-kicker">VARIANTS</span>
            <h2><?= $selectedModel ? e($selectedModel['brand_name'].' '.$selectedModel['name']) : 'Select a Model' ?></h2>
            <p><?= $selectedModel ? 'Available options for this model. Serial Numbers / IMEIs are added only when physical stock arrives.' : 'Choose Variants on a model above to view its storage, RAM, color or connectivity options.' ?></p>
        </div>
        <?php if ($selectedModel && $canAddMaster && (int)$selectedModel['is_active'] && (int)$selectedModel['brand_active']): ?>
            <button class="btn btn-primary btn-sm" type="button" data-quick-variant-open><?= icon('plus') ?> Add Variant</button>
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
                    <tr class="<?= !(int)$config['is_active'] ? 'archived-row' : '' ?>" data-variant-row="<?= (int)$config['id'] ?>">
                        <td><strong><?= e($specs) ?></strong></td>
                        <td><?php if($shownPrice!==null): ?><strong><?= peso($shownPrice) ?></strong><span class="table-subtext"><?= e($priceNote ?: 'All branches') ?></span><?php else: ?><strong>Varies by branch</strong><span class="table-subtext">Select a branch above to view its price</span><?php endif; ?></td>
                        <td><strong><span data-variant-stock="<?= (int)$config['id'] ?>"><?= number_format((int)$config['available_count']) ?></span> available</strong></td>
                        <td><span class="status-pill <?= (int)$config['is_active']?'available':'low' ?>"><?= (int)$config['is_active']?'Active':'Archived' ?></span></td>
                        <td><div class="master-table-actions">
                            <?php if ($canEditVariantPrice): ?>
                                <button class="table-action-btn" type="button"
                                    data-master-open="configuration"
                                    data-mode="edit"
                                    data-id="<?= (int)$config['id'] ?>"
                                    data-ram="<?= e((string)($config['ram'] ?? '')) ?>"
                                    data-storage="<?= e((string)($config['storage'] ?? '')) ?>"
                                    data-color="<?= e((string)($config['color'] ?? '')) ?>"
                                    data-connectivity="<?= e((string)($config['connectivity'] ?? '')) ?>"
                                    data-specs-locked="<?= ((int)($config['locked_unit_count'] ?? 0) > 0) ? '1' : '0' ?>"
                                    data-cost-price="<?= e((string)$config['cost_price']) ?>"
                                    data-selling-price="<?= e((string)($priceBranchId ? ($branchPricesForVariant[(int)$priceBranchId] ?? $config['selling_price']) : $config['selling_price'])) ?>"
                                    data-branch-prices='<?= e(json_encode($branchPricesForVariant, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>'
                                    data-config-label="<?= e($selectedModel['brand_name'].' '.$selectedModel['name'].' • '.$specs) ?>"><?= icon('edit') ?><span>Edit</span></button>
                            <?php endif; ?>
                            <?php if ($isOwner): ?>
                                <form method="post" action="actions/product_master.php" data-confirm="<?= (int)$config['is_active']?'Archive this variant? Existing stock and history will remain.':'Restore this variant?' ?>">
                                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="entity" value="configuration"><input type="hidden" name="return_view" value="devices"><input type="hidden" name="return_model" value="<?= (int)$selectedModel['id'] ?>"><input type="hidden" name="id" value="<?= (int)$config['id'] ?>"><input type="hidden" name="action" value="<?= (int)$config['is_active']?'archive':'restore' ?>">
                                    <button class="table-action-btn <?= (int)$config['is_active']?'warn':'success' ?>" type="submit"><?= icon((int)$config['is_active']?'archive':'restore') ?><span><?= (int)$config['is_active']?'Archive':'Restore' ?></span></button>
                                </form>
                                <?php if (!(int)$config['is_active'] && $showArchived): ?>
                                <form method="post" action="actions/product_master.php" data-confirm="Delete this archived variant from Product Setup? Past stock and sales history will still be kept for audit.">
                                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="entity" value="configuration"><input type="hidden" name="return_view" value="devices"><input type="hidden" name="return_model" value="<?= (int)$selectedModel['id'] ?>"><input type="hidden" name="id" value="<?= (int)$config['id'] ?>"><input type="hidden" name="action" value="delete">
                                    <button class="table-action-btn danger" type="submit"><?= icon('trash') ?><span>Delete</span></button>
                                </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                <?php if(!$configurations): ?>
                    <tr><td colspan="5"><div class="empty-state small">
                        <strong>No active variants</strong>
                        <span><?= $isOwner ? 'Archived variants can be restored or deleted from Show Archived. Past transaction history remains protected.' : 'Add the first storage/spec option for this model.' ?></span>
                        <div class="master-card-title-actions">
                            <?php if($canAddMaster): ?><button class="btn btn-primary btn-sm" type="button" data-quick-variant-open><?= icon('plus') ?> Add First Variant</button><?php endif; ?>
                            <?php if($isOwner && !$showArchived): ?><a class="btn btn-secondary btn-sm" href="<?= e(product_master_url(['status'=>'archived','model'=>(int)$selectedModel['id'],'brand'=>(int)$selectedModel['brand_id']])) ?>#variants">Show Archived</a><?php endif; ?>
                        </div>
                    </div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>
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
        <div class="master-card-footer"><a href="<?= e(product_master_url(['status' => $showArchived ? null : 'archived'])) ?>"><?= $showArchived ? 'Back to Active' : 'View Archived' ?></a></div>
    <?php endif; ?>
</section>
<?php endif; ?>

<div class="info-strip product-master-info"><?= icon('shield') ?><div><strong>Products are shared; stock stays by branch.</strong><span>Branches reuse the same brands, models and variants without sharing physical stock.</span></div></div>

<?php endif; // archiveMode ?>

<?php if ($canAddMaster && !$archiveMode): ?>
<div class="modal master-modal product-setup-modal" id="masterBrandModal" hidden>
    <div class="modal-backdrop" data-master-close></div>
    <div class="modal-dialog master-modal-dialog product-setup-dialog brand-setup-dialog">
        <div class="modal-header product-setup-header">
            <div>
                <span class="eyebrow" data-master-eyebrow>ADD BRAND</span>
                <h2 data-master-title>Create New Brand</h2>
                <p class="modal-subtitle" data-master-subtitle>Add a reusable device brand for Product Setup.</p>
            </div>
            <button type="button" class="icon-button" data-master-close aria-label="Close">×</button>
        </div>
        <form method="post" action="actions/product_master.php" class="master-modal-form product-setup-form" data-product-setup-form="brand" novalidate>
            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="entity" value="brand">
            <input type="hidden" name="return_view" value="devices">
            <input type="hidden" name="action" value="add" data-action-field>
            <input type="hidden" name="id" value="" data-id-field>

            <div class="product-setup-body brand-setup-body">
                <section class="brand-live-preview" aria-label="Brand preview">
                    <div class="brand-live-mark" data-brand-preview-initials>BR</div>
                    <div>
                        <strong data-brand-preview-name>New Brand</strong>
                        <span>Used across device models in every branch.</span>
                    </div>
                </section>

                <label class="field product-setup-field">
                    <span>Brand Name <b>*</b></span>
                    <input name="name" data-name-field data-uppercase maxlength="100" placeholder="e.g. SAMSUNG" autocomplete="off" required>
                    <small>Use the official brand name. Duplicate brands are blocked automatically.</small>
                    <em class="field-inline-error" data-field-error="name" hidden></em>
                </label>

                <div class="field product-setup-field">
                    <span>Status</span>
                    <div class="product-status-control" aria-label="Brand status">
                        <span class="status-dot"></span>
                        <strong>Active</strong>
                        <small>New brands are available immediately after creation.</small>
                    </div>
                </div>
            </div>

            <div class="master-modal-actions product-setup-footer">
                <button type="button" class="btn btn-secondary" data-master-close>Cancel</button>
                <button type="submit" class="btn btn-primary" data-submit-label>Create Brand</button>
            </div>
        </form>
    </div>
</div>

<div class="modal master-modal product-setup-modal" id="masterModelModal" hidden>
    <div class="modal-backdrop" data-master-close></div>
    <div class="modal-dialog master-modal-dialog product-setup-dialog model-setup-dialog">
        <div class="modal-header product-setup-header">
            <div>
                <span class="eyebrow" data-master-eyebrow>ADD MODEL</span>
                <h2 data-master-title>Create New Model</h2>
                <p class="modal-subtitle" data-master-subtitle>Add the basic model first, then manage its variants.</p>
            </div>
            <button type="button" class="icon-button" data-master-close aria-label="Close">×</button>
        </div>

        <form method="post" action="actions/product_master.php" class="master-modal-form product-setup-form model-setup-form" data-product-setup-form="model" novalidate>
            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="entity" value="model">
            <input type="hidden" name="return_view" value="devices">
            <input type="hidden" name="action" value="add" data-action-field>
            <input type="hidden" name="id" value="" data-id-field>

            <div class="model-stepper" data-model-stepper aria-label="Add model progress">
                <div class="model-step active" data-model-step-indicator="1">
                    <span>1</span><strong>Model Details</strong>
                </div>
                <div class="model-step-line"></div>
                <div class="model-step" data-model-step-indicator="2">
                    <span>2</span><strong>Confirmation</strong>
                </div>
            </div>

            <section class="model-step-panel" data-model-step-panel="1">
                <div class="product-setup-body">
                    <label class="field product-setup-field">
                        <span>Brand <b>*</b></span>
                        <select name="brand_id" data-brand-field required>
                            <option value="">Select brand</option>
                            <?php foreach ($brands as $brand): if (!(int)$brand['is_active']) continue; ?>
                                <option value="<?= (int)$brand['id'] ?>"><?= e($brand['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small>Select the brand this model belongs to.</small>
                        <em class="field-inline-error" data-field-error="brand_id" hidden></em>
                    </label>

                    <label class="field product-setup-field">
                        <span>Model Name <b>*</b></span>
                        <input name="name" data-name-field data-uppercase maxlength="150" placeholder="e.g. IPHONE 17" autocomplete="off" required>
                        <small data-model-edit-note>Use the official model name. You can add storage, RAM, color and connectivity as variants next.</small>
                        <em class="field-inline-error" data-field-error="name" hidden></em>
                    </label>

                    <div class="field product-setup-field">
                        <span>Device Type <b>*</b></span>
                        <select name="device_type" data-type-field required hidden>
                            <option value="phone">Phone</option>
                            <option value="tablet">Tablet</option>
                        </select>
                        <div class="device-type-selector" role="group" aria-label="Device type">
                            <button type="button" class="device-type-option active" data-model-type="phone" aria-pressed="true">
                                <?= icon('phone') ?><span><strong>Phone</strong><small>Smartphone or mobile device</small></span>
                            </button>
                            <button type="button" class="device-type-option" data-model-type="tablet" aria-pressed="false">
                                <?= icon('tablet') ?><span><strong>Tablet</strong><small>Tablet or large-screen device</small></span>
                            </button>
                        </div>
                    </div>

                    <div class="field product-setup-field">
                        <span>Status</span>
                        <div class="product-status-control" aria-label="Model status">
                            <span class="status-dot"></span>
                            <strong>Active</strong>
                            <small>New models are available immediately after creation.</small>
                        </div>
                    </div>
                </div>

                <div class="master-modal-actions product-setup-footer model-step-one-actions">
                    <button type="button" class="btn btn-secondary" data-master-close>Cancel</button>
                    <button type="button" class="btn btn-primary" data-model-next>Next <?= icon('chevron', 'icon-chevron-right') ?></button>
                    <button type="submit" class="btn btn-primary" data-model-edit-submit hidden>Save Changes</button>
                </div>
            </section>

            <section class="model-step-panel" data-model-step-panel="2" hidden>
                <div class="product-setup-body model-review-body">
                    <div class="model-review-summary">
                        <div class="model-review-mark" data-review-mark>PH</div>
                        <div class="model-review-summary-copy">
                            <strong data-review-brand>Brand</strong>
                            <span>Model: <b data-review-name>—</b></span>
                        </div>
                        <span class="device-type-chip phone" data-review-type-chip><?= icon('phone') ?><span data-review-type>Phone</span></span>
                    </div>

                    <dl class="model-review-list">
                        <div><dt>Brand</dt><dd data-review-brand-row>—</dd></div>
                        <div><dt>Model Name</dt><dd data-review-name-row>—</dd></div>
                        <div><dt>Device Type</dt><dd data-review-type-row>Phone</dd></div>
                        <div><dt>Status</dt><dd><span class="review-active-status"><span class="status-dot"></span>Active</span></dd></div>
                    </dl>

                    <div class="model-review-info">
                        <?= icon('shield') ?>
                        <div><strong>Next: add model variants</strong><span>After creating the model, open Variants to add storage, RAM, color and connectivity options.</span></div>
                    </div>
                </div>

                <div class="master-modal-actions product-setup-footer model-review-actions">
                    <button type="button" class="btn btn-secondary" data-model-back><?= icon('chevron', 'icon-chevron-left') ?> Back</button>
                    <button type="submit" class="btn btn-primary" data-model-create><?= icon('save') ?> Create Model</button>
                </div>
            </section>
        </form>
    </div>
</div>

<div class="modal master-modal" id="masterCategoryModal" hidden>
    <div class="modal-backdrop" data-master-close></div>
    <div class="modal-dialog master-modal-dialog">
        <div class="modal-header"><div><span class="eyebrow">ACCESSORIES</span><h2 data-master-title>Add Category</h2><p class="modal-subtitle">Create a clean reusable group for accessory items.</p></div><button type="button" class="icon-button" data-master-close aria-label="Close">×</button></div>
        <form method="post" action="actions/product_master.php" class="master-modal-form">
            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="entity" value="category"><input type="hidden" name="return_view" value="accessories"><input type="hidden" name="action" value="add" data-action-field><input type="hidden" name="id" value="" data-id-field>
            <label class="field"><span>Category Name <b>*</b></span><input name="name" data-name-field data-uppercase maxlength="100" placeholder="E.G. CHARGERS" required><small>Keep category names short and reusable.</small></label>
            <div class="master-modal-actions"><button type="button" class="btn btn-secondary" data-master-close>Cancel</button><button type="submit" class="btn btn-primary">Save Category</button></div>
        </form>
    </div>
</div>


<?php if ($canEditVariantPrice): ?>
<div class="modal master-modal" id="masterConfigurationModal" hidden>
    <div class="modal-backdrop" data-master-close></div>
    <div class="modal-dialog master-modal-dialog variant-price-dialog <?= $isOwner ? 'variant-owner-dialog' : '' ?>">
        <div class="modal-header">
            <div>
                <span class="eyebrow">VARIANT</span>
                <h2 data-master-title>Edit Variant</h2>
                <p class="modal-subtitle" data-config-modal-label>Update this variant.</p>
            </div>
            <button type="button" class="icon-button" data-master-close aria-label="Close">×</button>
        </div>

        <?php if ($isOwner): ?>
            <div class="variant-edit-tabs" role="tablist" aria-label="Variant management">
                <button type="button" class="variant-edit-tab active" data-variant-tab="pricing" role="tab" aria-selected="true">Pricing</button>
                <button type="button" class="variant-edit-tab" data-variant-tab="inventory" role="tab" aria-selected="false">Inventory</button>
            </div>
        <?php endif; ?>

        <div data-variant-panel="pricing">
            <form method="post" action="actions/product_master.php" class="master-modal-form">
                <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
                <input type="hidden" name="entity" value="configuration">
                <input type="hidden" name="return_view" value="devices">
                <input type="hidden" name="return_model" value="<?= (int)($selectedModel['id'] ?? 0) ?>">
                <input type="hidden" name="action" value="edit" data-action-field>
                <input type="hidden" name="id" value="" data-id-field>

                <?php
                    $variantEditType = $selectedModel['device_type'] ?? 'phone';
                    $variantEditIsApple = $selectedModel && strcasecmp(trim((string)($selectedModel['brand_name'] ?? '')), 'APPLE') === 0;
                ?>
                <section class="variant-spec-section">
                    <div class="variant-price-heading">
                        <strong>Variant Details</strong>
                        <span>Correct the specs before stock or history is attached to this variant.</span>
                    </div>
                    <div class="variant-spec-grid">
                        <?php if (!$variantEditIsApple): ?>
                            <label class="field">
                                <span>RAM <b>*</b></span>
                                <select name="ram" data-variant-ram-field required>
                                    <option value="">Select RAM</option>
                                    <option>4GB</option><option>6GB</option><option>8GB</option><option>12GB</option><option>16GB</option><option>24GB</option>
                                </select>
                            </label>
                        <?php endif; ?>
                        <label class="field">
                            <span>Storage <b>*</b></span>
                            <select name="storage" data-variant-storage-field required>
                                <option value="">Select storage</option>
                                <option>64GB</option><option>128GB</option><option>256GB</option><option>512GB</option><option>1TB</option><option>2TB</option>
                            </select>
                        </label>
                        <label class="field">
                            <span>Color <b>*</b></span>
                            <input type="text" name="color" maxlength="80" data-uppercase data-variant-color-field placeholder="E.G. DEEP BLUE" required>
                        </label>
                        <?php if ($variantEditType === 'tablet'): ?>
                            <label class="field">
                                <span>Connectivity <b>*</b></span>
                                <select name="connectivity" data-variant-connectivity-field required>
                                    <option value="">Select connectivity</option>
                                    <option value="Wi-Fi">Wi-Fi</option>
                                    <option value="Wi-Fi + Cellular">Wi-Fi + Cellular</option>
                                </select>
                            </label>
                        <?php endif; ?>
                    </div>
                    <div class="variant-spec-lock-note" data-variant-spec-note>Specs can be edited while this variant has no stock or history.</div>
                </section>

                <?php if ($isOwner): ?>
                    <label class="field">
                        <span>Cost Price / Unit <b>*</b></span>
                        <div class="money-input"><span>₱</span><input type="number" step="0.01" min="0" name="cost_price" data-cost-field required></div>
                        <small>Owner-only cost used for newly received units.</small>
                    </label>
                    <div class="variant-price-section">
                        <div class="variant-price-heading"><strong>Selling Price by Branch</strong><span>Each branch can use its own POS price.</span></div>
                        <div class="variant-branch-price-list">
                            <?php foreach ($priceBranches as $pb): ?>
                                <label class="variant-branch-price-row">
                                    <span><?= e($pb['name']) ?></span>
                                    <div class="money-input compact"><span>₱</span><input type="number" step="0.01" min="0.01" name="branch_prices[<?= (int)$pb['id'] ?>]" data-branch-price="<?= (int)$pb['id'] ?>" required></div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <label class="field">
                        <span>Selling Price — <?= e(Auth::user()['branch_name'] ?? 'Your Branch') ?> <b>*</b></span>
                        <div class="money-input"><span>₱</span><input type="number" step="0.01" min="0.01" name="selling_price" data-selling-field required></div>
                        <small>This is the price your branch will use in POS.</small>
                    </label>
                <?php endif; ?>
                <div class="master-modal-actions"><button type="button" class="btn btn-secondary" data-master-close>Cancel</button><button type="submit" class="btn btn-primary">Save Changes</button></div>
            </form>
        </div>

        <?php if ($isOwner): ?>
            <div class="variant-inventory-panel" data-variant-panel="inventory" hidden>
                <div class="variant-inventory-loading" data-variant-inventory-body>
                    <div class="loading-state">Loading inventory…</div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($selectedModel && $canQuickReceive && (int)$selectedModel['is_active'] && (int)$selectedModel['brand_active']):
    $quickIsApple = strcasecmp(trim((string)$selectedModel['brand_name']), 'APPLE') === 0;
    $quickType = in_array($selectedModel['device_type'], ['phone','tablet'], true) ? $selectedModel['device_type'] : 'phone';
    $quickBranchId = Auth::isOwner() ? 0 : (Auth::branchId() ?: 0);
    $quickBranchName = Auth::user()['branch_name'] ?? 'Your Branch';
?>
<div class="modal product-flow-modal" id="modelCreatedModal" <?= $quickSetupAfterCreate ? 'data-auto-open="1"' : '' ?> hidden>
    <div class="modal-backdrop" data-model-created-close></div>
    <div class="modal-dialog product-flow-success-dialog" role="dialog" aria-modal="true" aria-labelledby="modelCreatedTitle">
        <button type="button" class="icon-button product-flow-close" data-model-created-close aria-label="Close">×</button>
        <div class="product-flow-success-icon" aria-hidden="true">✓</div>
        <span class="eyebrow">MODEL CREATED</span>
        <h2 id="modelCreatedTitle">Model Created Successfully</h2>
        <p><strong><?= e($selectedModel['brand_name'].' '.$selectedModel['name']) ?></strong> is ready for its first variant and physical stock.</p>
        <div class="product-flow-next-card">
            <?= icon('products') ?>
            <div><strong>Next step</strong><span>Add the storage/spec details, quantity and scan the units that arrived.</span></div>
        </div>
        <div class="product-flow-success-actions">
            <button type="button" class="btn btn-primary" data-quick-variant-open data-model-created-close><?= icon('plus') ?> Add First Variant</button>
            <button type="button" class="btn btn-secondary" data-model-created-close>Maybe Later</button>
        </div>
    </div>
</div>

<div class="modal product-flow-modal quick-variant-modal" id="quickVariantStockModal" hidden
     data-model-id="<?= (int)$selectedModel['id'] ?>"
     data-model-label="<?= e($selectedModel['brand_name'].' '.$selectedModel['name']) ?>"
     data-product-type="<?= e($quickType) ?>"
     data-is-apple="<?= $quickIsApple ? '1' : '0' ?>"
     data-default-branch-id="<?= (int)$quickBranchId ?>">
    <div class="modal-backdrop" data-quick-variant-close></div>
    <div class="modal-dialog quick-variant-dialog" role="dialog" aria-modal="true" aria-labelledby="quickVariantTitle">
        <div class="modal-header quick-variant-header">
            <div>
                <span class="eyebrow">ADD VARIANT & STOCK</span>
                <h2 id="quickVariantTitle">Add Variant</h2>
                <p class="modal-subtitle"><?= e($selectedModel['brand_name'].' '.$selectedModel['name']) ?> · <?= e(ucfirst($quickType)) ?></p>
            </div>
            <button type="button" class="icon-button" data-quick-variant-close aria-label="Close">×</button>
        </div>

        <form id="quickVariantStockForm" class="quick-variant-form" novalidate>
            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="model_id" value="<?= (int)$selectedModel['id'] ?>">
            <input type="hidden" name="product_id" value="" data-quick-product-id>

            <div class="quick-variant-scroll">
                <section class="quick-variant-section">
                    <div class="quick-variant-section-heading"><span>1</span><div><strong>Variant Details</strong><small>Set the specs once, then add the units that arrived.</small></div></div>
                    <div class="quick-variant-grid">
                        <?php if (!$quickIsApple): ?>
                        <label class="field"><span>RAM <b>*</b></span><select name="ram" required><option value="">Select RAM</option><option>4GB</option><option>6GB</option><option>8GB</option><option>12GB</option><option>16GB</option><option>24GB</option></select></label>
                        <?php endif; ?>
                        <label class="field"><span>Storage <b>*</b></span><select name="storage" required><option value="">Select storage</option><option>64GB</option><option>128GB</option><option>256GB</option><option>512GB</option><option>1TB</option><option>2TB</option></select></label>
                        <label class="field"><span>Color <b>*</b></span><input type="text" name="color" data-uppercase maxlength="80" placeholder="E.G. DEEP BLUE" required></label>
                        <?php if ($quickType === 'tablet'): ?>
                        <label class="field"><span>Connectivity <b>*</b></span><select name="connectivity" data-quick-connectivity required><option value="">Select connectivity</option><option value="Wi-Fi">Wi-Fi</option><option value="Wi-Fi + Cellular">Wi-Fi + Cellular</option></select></label>
                        <?php endif; ?>
                        <label class="field"><span>Selling Price <b>*</b></span><div class="money-input"><span>₱</span><input type="number" name="selling_price" min="0.01" step="0.01" inputmode="decimal" placeholder="0.00" required></div><small>Price used by <?= e(Auth::isOwner() ? 'the selected branch' : $quickBranchName) ?>.</small></label>
                        <label class="field quick-quantity-field"><span>Quantity <b>*</b></span><div class="quick-quantity-control"><button type="button" data-qty-step="-1" aria-label="Decrease quantity">−</button><input type="number" name="quantity" value="1" min="1" max="100" inputmode="numeric" data-quick-quantity required><button type="button" data-qty-step="1" aria-label="Increase quantity">+</button></div><small>One identifier slot is created for each physical unit.</small></label>
                        <?php if (Auth::isOwner()): ?>
                        <label class="field"><span>Stock Location <b>*</b></span><select name="branch_id" data-quick-branch required><option value="">Select branch</option><?php foreach ($priceBranches as $pb): ?><option value="<?= (int)$pb['id'] ?>"><?= e($pb['name']) ?></option><?php endforeach; ?></select></label>
                        <?php else: ?>
                        <input type="hidden" name="branch_id" value="<?= (int)$quickBranchId ?>">
                        <?php endif; ?>
                    </div>
                </section>

                <section class="quick-variant-section quick-identifiers-section">
                    <div class="quick-variant-section-heading"><span>2</span><div><strong>Scan Physical Units</strong><small><?= $quickIsApple ? 'Scan the Apple Serial Number (S/N) for each unit.' : 'Scan IMEI by default, or switch to Serial / Barcode when the device has no IMEI.' ?></small></div></div>
                    <?php if (!$quickIsApple): ?>
                    <div class="quick-identifier-mode" role="group" aria-label="Identifier type">
                        <button type="button" class="active" data-quick-id-mode="imei">IMEI</button>
                        <button type="button" data-quick-id-mode="barcode">Serial / Barcode</button>
                    </div>
                    <?php endif; ?>
                    <div class="quick-scan-progress"><strong data-scan-progress>0 / 1 scanned</strong><span>Quantity and scanned units must match.</span></div>
                    <div class="quick-unit-list" data-quick-unit-list></div>
                </section>

                <section class="quick-variant-section quick-notes-section">
                    <label class="field"><span>Notes <small>(Optional)</small></span><textarea name="notes" rows="2" maxlength="180" placeholder="Example: Initial delivery / allocation"></textarea></label>
                </section>

                <div class="quick-variant-error" data-quick-error role="alert" hidden></div>
            </div>

            <div class="quick-variant-footer">
                <button type="button" class="btn btn-secondary" data-quick-variant-close>Cancel</button>
                <button type="submit" class="btn btn-primary" data-quick-submit><?= icon('stock') ?> Save Variant & Add Stock</button>
            </div>
        </form>
    </div>
</div>

<?php if ($quickStockDone && $quickCompletedVariant):
    $quickSpecs=[]; foreach (['ram','storage','connectivity','color'] as $key) if (!empty($quickCompletedVariant[$key])) $quickSpecs[]=$quickCompletedVariant[$key];
?>
<div class="modal product-flow-modal" id="quickStockSuccessModal" data-auto-open="1" hidden>
    <div class="modal-backdrop" data-quick-success-close></div>
    <div class="modal-dialog product-flow-success-dialog" role="dialog" aria-modal="true" aria-labelledby="quickStockSuccessTitle">
        <button type="button" class="icon-button product-flow-close" data-quick-success-close aria-label="Close">×</button>
        <div class="product-flow-success-icon" aria-hidden="true">✓</div>
        <span class="eyebrow">STOCK ADDED</span>
        <h2 id="quickStockSuccessTitle">Variant & Stock Added</h2>
        <p><strong><?= e($selectedModel['brand_name'].' '.$selectedModel['name']) ?></strong> is now ready in inventory.</p>
        <div class="quick-success-summary">
            <div><span>Variant</span><strong><?= e($quickSpecs ? implode(' • ', $quickSpecs) : 'Standard') ?></strong></div>
            <div><span>Available Stock</span><strong><?= number_format((int)$quickCompletedVariant['available_count']) ?> unit<?= (int)$quickCompletedVariant['available_count']===1?'':'s' ?></strong></div>
        </div>
        <div class="product-flow-success-actions">
            <button type="button" class="btn btn-outline" data-quick-variant-open data-quick-success-close><?= icon('plus') ?> Add Another Variant</button>
            <a class="btn btn-primary" href="index.php?page=products&model=<?= (int)$selectedModel['id'] ?>#variants">Done — Back to Products</a>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="assets/vendor/zxing-wasm/reader.js"></script>
<script src="assets/js/imei-reader.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/imei-reader.js') ?>"></script>
<script src="assets/vendor/legacy-scanner/tesseract.min.js"></script>
<script src="assets/js/serial-label-reader.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/serial-label-reader.js') ?>"></script>
<script src="assets/js/product-quick-setup.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/product-quick-setup.js') ?>"></script>
<script src="assets/js/device-scanner.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/device-scanner.js') ?>"></script>
<?php endif; ?>
<?php endif; ?>
