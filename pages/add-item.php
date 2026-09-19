<?php
$role = Auth::user()['role'] ?? '';
$canManage = in_array($role, ['owner','branch_manager','inventory'], true);
if (!$canManage) {
    echo '<section class="card callout-card"><div>'.icon('shield').'</div><div><h2>Product setup is not available for this account.</h2><p>Ask the Owner or an authorized inventory user to manage Products.</p></div></section>';
    return;
}

$models = $categories = [];
$presetModelId = filter_input(INPUT_GET, 'model_id', FILTER_VALIDATE_INT) ?: 0;
$requestedType = strtolower(trim((string)($_GET['type'] ?? '')));
$presetModel = null;

try {
    $models = Database::query("SELECT pm.id,pm.brand_id,pm.name,COALESCE(pm.device_type,'phone') device_type,b.name brand_name FROM product_models pm JOIN brands b ON b.id=pm.brand_id WHERE pm.is_active=1 AND b.is_active=1 ORDER BY b.name,pm.name")->fetchAll();
    $categories = Database::query('SELECT id,name FROM categories WHERE is_active=1 ORDER BY name')->fetchAll();
    foreach ($models as $model) {
        if ((int)$model['id'] === $presetModelId) { $presetModel = $model; break; }
    }
} catch (Throwable $e) {
    flash('error', 'Unable to load Products.');
}

$isAccessoryMode = !$presetModel && $requestedType === 'accessory';
if (!$presetModel && !$isAccessoryMode) {
    echo '<section class="page-heading"><div><span class="eyebrow">PRODUCT SETUP</span><h1>Add Variant</h1><p>Choose a model first, then add its variant.</p></div></section>';
    echo '<section class="card callout-card"><div>'.icon('info').'</div><div><h2>Select a model from Products.</h2><p>Open Products, choose the model, then click Add Variant.</p><a class="btn btn-primary" href="index.php?page=products">Go to Products</a></div></section>';
    return;
}

$type = $isAccessoryMode ? 'accessory' : ($presetModel['device_type'] ?? 'phone');
$isApple = !$isAccessoryMode && strcasecmp(trim((string)($presetModel['brand_name'] ?? '')), 'Apple') === 0;
$modelLabel = !$isAccessoryMode ? trim(($presetModel['brand_name'] ?? '').' '.($presetModel['name'] ?? '')) : '';
?>
<section class="page-heading configuration-heading">
    <div>
        <span class="eyebrow">PRODUCT SETUP</span>
        <h1><?= $isAccessoryMode ? 'Add Accessory' : 'Add Variant' ?></h1>
        <p><?= $isAccessoryMode ? 'Create an accessory item once, then receive its stock separately.' : 'Add one variant for '.e($modelLabel).'. Physical units and Serial Numbers / IMEIs are received after this.' ?></p>
    </div>
    <a class="btn btn-secondary" href="index.php?page=products<?= $presetModelId ? '&model='.(int)$presetModelId : '&view=accessories' ?>">Back to Products</a>
</section>

<?php if (!$isAccessoryMode): ?>
<div class="info-strip configuration-rule-strip">
    <?= icon('info') ?>
    <div><strong>One variant can receive many units.</strong><span>Create the variant once. If 10 phones arrive, Receive Stock will create 10 Serial Number / IMEI fields for you.</span></div>
</div>
<?php endif; ?>

<form class="card form-card configuration-form" method="post" action="actions/add_item.php" id="configurationForm">
    <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
    <input type="hidden" name="product_type" value="<?= e($type) ?>">

    <?php if (!$isAccessoryMode): ?>
    <input type="hidden" name="brand_id" value="<?= (int)$presetModel['brand_id'] ?>">
    <input type="hidden" name="model_id" value="<?= (int)$presetModel['id'] ?>">

    <section class="form-section">
        <div class="section-title"><span>1</span><div><h2>Variant Details</h2><p>Add the storage/specs for this model.</p></div></div>
        <div class="selected-model-card">
            <div class="selected-model-icon"><?= icon($type === 'tablet' ? 'tablet' : 'phone') ?></div>
            <div><small>Selected Model</small><strong><?= e($modelLabel) ?></strong><span><?= e(ucfirst($type)) ?></span></div>
        </div>
        <div class="form-grid two variant-detail-grid">
            <?php if (!$isApple): ?>
            <label class="field"><span>RAM <b>*</b></span><select name="ram" required><option value="">Select RAM</option><option>4GB</option><option>6GB</option><option>8GB</option><option>12GB</option><option>16GB</option><option>24GB</option></select></label>
            <?php endif; ?>
            <label class="field"><span>Storage <b>*</b></span><select name="storage" required><option value="">Select storage</option><option>64GB</option><option>128GB</option><option>256GB</option><option>512GB</option><option>1TB</option><option>2TB</option></select></label>
            <?php if ($type === 'phone' && $isApple): ?>
            <label class="field"><span>Color <b>*</b></span><input name="color" data-uppercase maxlength="80" placeholder="E.G. BLACK TITANIUM" required></label>
            <?php endif; ?>
            <?php if ($type === 'tablet'): ?>
            <label class="field"><span>Connectivity <b>*</b></span><select name="connectivity" required><option value="">Select connectivity</option><option value="Wi-Fi">Wi-Fi</option><option value="Wi-Fi + Cellular">Wi-Fi + Cellular</option></select></label>
            <?php endif; ?>
        </div>
    </section>

    <section class="form-section">
        <div class="section-title"><span>2</span><div><h2>Selling Price</h2><p>Set the starting selling price for this variant.</p></div></div>
        <div class="form-grid two price-config-grid">
            <label class="field"><span>Selling Price (PHP) <b>*</b></span><div class="money-input"><span>₱</span><input type="number" step="0.01" min="0.01" name="selling_price" placeholder="0.00" required></div></label>
            <?php if (Auth::isOwner()): ?>
            <label class="field"><span>Cost Price / Unit</span><div class="money-input"><span>₱</span><input type="number" step="0.01" min="0" name="cost_price" placeholder="0.00"></div><small>Optional here. Cost can be completed later.</small></label>
            <?php else: ?>
            <div class="configuration-cost-note"><span class="note-icon"><?= icon('shield') ?></span><div><strong>Cost is Owner-only.</strong><small>You can create the variant and receive stock without seeing the cost.</small></div></div>
            <?php endif; ?>
        </div>
    </section>

    <div class="form-actions sticky-form-actions split-form-actions">
        <a class="btn btn-secondary" href="index.php?page=products&model=<?= (int)$presetModel['id'] ?>">Cancel</a>
        <div class="form-action-group">
            <button class="btn btn-outline" type="submit" name="next_action" value="products">Save Variant</button>
            <button class="btn btn-primary" type="submit" name="next_action" value="receive"><?= icon('stock') ?> Save & Receive Stock</button>
        </div>
    </div>

    <?php else: ?>
    <section class="form-section">
        <div class="section-title"><span>1</span><div><h2>Accessory Details</h2><p>Create the item once. Quantity is received later.</p></div></div>
        <div class="form-grid two">
            <label class="field"><span>Category <b>*</b></span><select name="category_id" required><option value="">Select category</option><?php foreach($categories as $category): ?><option value="<?= (int)$category['id'] ?>"><?= e($category['name']) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span>Product Name <b>*</b></span><input name="product_name" data-uppercase placeholder="E.G. 20W USB-C CHARGER" required></label>
            <label class="field span-2"><span>Barcode</span><input name="barcode" placeholder="Scan barcode or enter manually"><small>Optional if the accessory has no barcode yet.</small></label>
        </div>
    </section>
    <section class="form-section">
        <div class="section-title"><span>2</span><div><h2>Selling Price</h2><p>Set the starting selling price.</p></div></div>
        <div class="form-grid two">
            <label class="field"><span>Selling Price (PHP) <b>*</b></span><div class="money-input"><span>₱</span><input type="number" step="0.01" min="0.01" name="selling_price" placeholder="0.00" required></div></label>
            <?php if (Auth::isOwner()): ?><label class="field"><span>Cost Price / Unit</span><div class="money-input"><span>₱</span><input type="number" step="0.01" min="0" name="cost_price" placeholder="0.00"></div></label><?php endif; ?>
        </div>
    </section>
    <div class="form-actions sticky-form-actions"><a class="btn btn-secondary" href="index.php?page=products&view=accessories">Cancel</a><button class="btn btn-primary" type="submit" name="next_action" value="products"><?= icon('plus') ?> Save Accessory</button></div>
    <?php endif; ?>
</form>
