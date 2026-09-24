<?php
$role = Auth::user()['role'] ?? '';
$canReceive = in_array($role, ['owner','branch_manager','inventory'], true);
if (!$canReceive) {
    echo '<section class="card callout-card"><div>'.icon('shield').'</div><div><h2>Receive Stock is not available for this account.</h2><p>Ask the Owner or an authorized inventory user to receive new stock.</p></div></section>';
    return;
}

$isOwner = Auth::isOwner();
$assignedBranchId = Auth::branchId();
$assignedBranchName = Auth::user()['branch_name'] ?? 'Assigned Branch';
$requestedBranch = filter_input(INPUT_GET, 'branch', FILTER_VALIDATE_INT) ?: 0;
$presetProductId = filter_input(INPUT_GET, 'product_id', FILTER_VALIDATE_INT) ?: 0;
$successData = null;
$successRaw = flash('stock_in_success');
if ($successRaw) {
    $decoded = json_decode($successRaw, true);
    if (is_array($decoded)) $successData = $decoded;
}

$models = $variants = $accessories = $branches = [];
$branchPrices = [];
$canEditSelling = in_array($role, ['owner','branch_manager'], true);
try {
    $models = Database::query(
        "SELECT pm.id,pm.name,pm.device_type,b.id brand_id,b.name brand_name
         FROM product_models pm
         JOIN brands b ON b.id=pm.brand_id
         WHERE pm.is_active=1 AND b.is_active=1
         ORDER BY b.name,pm.name"
    )->fetchAll();

    $variants = Database::query(
        "SELECT p.id,p.product_type,p.model_id,p.ram,p.storage,p.color,p.connectivity,p.cost_price,p.selling_price,
                b.name brand_name,pm.name model_name
         FROM products p
         JOIN brands b ON b.id=p.brand_id
         JOIN product_models pm ON pm.id=p.model_id
         WHERE p.is_active=1 AND p.product_type IN ('phone','tablet') AND b.is_active=1 AND pm.is_active=1
         ORDER BY b.name,pm.name,p.ram,p.storage,p.connectivity,p.color"
    )->fetchAll();

    $accessories = Database::query(
        "SELECT p.id,p.product_name,p.barcode,p.cost_price,p.selling_price,c.name category_name
         FROM products p
         LEFT JOIN categories c ON c.id=p.category_id
         WHERE p.is_active=1 AND p.product_type='accessory'
         ORDER BY COALESCE(c.name,''),p.product_name"
    )->fetchAll();

    if ($isOwner) $branches = Database::query('SELECT id,name,code FROM branches WHERE is_active=1 ORDER BY id')->fetchAll();
    if (branch_pricing_ready()) {
        $priceRows = $isOwner
            ? Database::query('SELECT product_id,branch_id,selling_price FROM branch_product_prices')->fetchAll()
            : Database::query('SELECT product_id,branch_id,selling_price FROM branch_product_prices WHERE branch_id=?', [$assignedBranchId ?: 0])->fetchAll();
        foreach ($priceRows as $priceRow) $branchPrices[(int)$priceRow['product_id']][(int)$priceRow['branch_id']] = (float)$priceRow['selling_price'];
    }
} catch (Throwable $e) {
    flash('error', 'Unable to load products for receiving.');
}

function receive_specs(array $row): string {
    $parts = [];
    foreach (['ram','storage','connectivity','color'] as $key) {
        if (!empty($row[$key])) $parts[] = $row[$key];
    }
    return $parts ? implode(' • ', $parts) : 'Standard';
}

$modelPayload = [];
foreach ($models as $model) {
    $modelPayload[(int)$model['id']] = [
        'kind' => 'model',
        'id' => (int)$model['id'],
        'brandId' => (int)$model['brand_id'],
        'brand' => $model['brand_name'],
        'appleSerial' => stock_uses_apple_serial((string)$model['brand_name'], (string)$model['name']),
        'model' => $model['name'],
        'type' => $model['device_type'] ?: 'phone',
        'label' => trim($model['brand_name'].' '.$model['name']),
        'variants' => [],
    ];
}
foreach ($variants as $variant) {
    $modelId = (int)$variant['model_id'];
    if (!isset($modelPayload[$modelId])) continue;
    $modelPayload[$modelId]['variants'][] = [
        'id' => (int)$variant['id'],
        'specs' => receive_specs($variant),
        'selling' => (float)$variant['selling_price'],
        'prices' => $branchPrices[(int)$variant['id']] ?? [],
        'cost' => $isOwner ? (float)$variant['cost_price'] : null,
        'costReady' => (float)$variant['cost_price'] > 0,
        'type' => $variant['product_type'],
    ];
}
$itemPayload = array_values($modelPayload);
foreach ($accessories as $accessory) {
    $itemPayload[] = [
        'kind' => 'accessory',
        'id' => (int)$accessory['id'],
        'label' => trim((string)$accessory['product_name']),
        'category' => $accessory['category_name'] ?? '',
        'barcode' => $accessory['barcode'] ?? '',
        'selling' => (float)$accessory['selling_price'],
        'prices' => $branchPrices[(int)$accessory['id']] ?? [],
        'cost' => $isOwner ? (float)$accessory['cost_price'] : null,
        'costReady' => (float)$accessory['cost_price'] > 0,
        'type' => 'accessory',
    ];
}
?>

<section class="page-heading receive-heading">
    <div>
        <span class="eyebrow">STOCK</span>
        <h1>Receive Stock</h1>
        <p>Add newly arrived items to your branch inventory.</p>
    </div>
    <a class="btn btn-secondary" href="index.php?page=products">Manage Products</a>
</section>

<?php if ($successData): ?>
<div class="stock-success-card">
    <div class="stock-success-icon">✓</div>
    <div>
        <strong>Stock received successfully</strong>
        <span><?= e($successData['product'] ?? 'Item') ?> • <?= number_format((int)($successData['quantity'] ?? 0)) ?> unit<?= (int)($successData['quantity'] ?? 0) === 1 ? '' : 's' ?> • <?= e($successData['branch'] ?? '') ?></span>
        <?php if (!empty($successData['restored'])): ?><small><?= (int)$successData['restored'] ?> previously removed unit<?= (int)$successData['restored'] === 1 ? '' : 's' ?> restored safely.</small><?php endif; ?>
        <small>Reference: <?= e($successData['reference'] ?? '—') ?></small>
    </div>
    <div class="stock-success-actions">
        <a class="btn btn-outline btn-sm" href="index.php?page=inventory">View Inventory</a>
        <a class="btn btn-primary btn-sm" href="index.php?page=stock-in">Receive More</a>
    </div>
</div>
<?php endif; ?>

<form class="card receive-form" id="receiveStockForm" method="post" action="actions/stock_in.php">
    <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
    <input type="hidden" name="product_id" id="productId" value="">
    <input type="hidden" name="confirmed" id="confirmedField" value="0">

    <section class="receive-step">
        <div class="receive-step-number">1</div>
        <div class="receive-step-body">
            <div class="receive-step-title">
                <h2>Choose Item</h2>
                <p>Search the model or accessory that arrived.</p>
            </div>
            <div class="receive-search-wrap" id="receiveSearchWrap">
                <label class="field receive-search-field">
                    <span>Item <b>*</b></span>
                    <div class="receive-search-input"><?= icon('search') ?><input type="search" id="itemSearch" autocomplete="off" placeholder="Search iPhone 17, Galaxy A56, charger…"></div>
                </label>
                <div class="receive-search-results" id="itemSearchResults" hidden></div>
            </div>
            <div class="receive-selected-item hidden" id="selectedItemCard">
                <div class="receive-selected-icon" id="selectedItemIcon">AP</div>
                <div class="receive-selected-copy"><small>Selected item</small><strong id="selectedItemName">—</strong><span id="selectedItemMeta">—</span></div>
                <button class="btn btn-ghost btn-sm" type="button" id="changeItemBtn">Change</button>
            </div>
            <p class="receive-help">Can’t find the model? Add it first in <a href="index.php?page=products">Products</a>.</p>
        </div>
    </section>

    <section class="receive-step hidden" id="variantStep">
        <div class="receive-step-number">2</div>
        <div class="receive-step-body">
            <div class="receive-step-title receive-title-row">
                <div><h2>Choose Variant</h2><p>Select the storage/specs that arrived.</p></div>
                <button class="btn btn-outline btn-sm" type="button" id="openAddVariant"><?= icon('plus') ?> Add Variant</button>
            </div>
            <div class="receive-variant-grid" id="variantGrid"></div>
            <div class="receive-empty-variants hidden" id="noVariantsBox">
                <strong>No variant yet</strong>
                <span>Add the storage/specs for this model, then continue receiving stock.</span>
                <button class="btn btn-primary btn-sm" type="button" id="openAddVariantEmpty"><?= icon('plus') ?> Add First Variant</button>
            </div>
        </div>
    </section>

    <section class="receive-step hidden" id="stockDetailsStep">
        <div class="receive-step-number" id="stockStepNumber">3</div>
        <div class="receive-step-body">
            <div class="receive-step-title"><h2>Stock Details</h2><p>Enter the quantity received. Cost is protected; your branch selling price stays editable for authorized users.</p></div>
            <div class="receive-summary-bar" id="selectedVariantSummary"></div>
            <div class="form-grid two receive-stock-grid">
                <?php if ($isOwner): ?>
                <label class="field"><span>Stock Location <b>*</b></span><select name="branch_id" id="branchSelect" required><option value="">Select branch</option><?php foreach ($branches as $branch): ?><option value="<?= (int)$branch['id'] ?>" <?= $requestedBranch === (int)$branch['id'] ? 'selected' : '' ?>><?= e($branch['name']) ?></option><?php endforeach; ?></select><small>Choose which branch receives this stock.</small></label>
                <?php else: ?>
                <div class="field"><span>Stock Location</span><div class="branch-lock-value stock-branch-lock"><?= icon('branch') ?><strong><?= e($assignedBranchName) ?></strong><span>Assigned to your branch</span></div></div>
                <?php endif; ?>
                <label class="field"><span>Quantity <b>*</b></span><input type="number" min="1" max="100" value="1" name="quantity" id="quantityInput" required><small id="quantityHint">Number of units received.</small></label>
                <div class="field"><span>Cost Price / Unit</span><div class="protected-price" id="costPriceLock"><span class="protected-price-icon"><?= icon('shield') ?></span><div><?php if ($isOwner): ?><strong id="costPriceDisplay">₱0.00</strong><small id="costPriceHelp">Locked here. Update cost from Products → Variants.</small><?php else: ?><strong id="costPriceDisplay">Owner managed</strong><small id="costPriceHelp">Cost is protected and cannot be changed from this branch account.</small><?php endif; ?></div></div></div>
                <?php if ($canEditSelling): ?>
                <label class="field"><span>Selling Price <b>*</b></span><div class="money-input"><span>₱</span><input type="number" step="0.01" min="0.01" name="selling_price" id="sellingPriceInput" placeholder="0.00" required></div><small id="sellingPriceHelp">Price used by POS for this branch.</small></label>
                <?php else: ?>
                <div class="field"><span>Selling Price</span><div class="money-readonly" id="sellingPriceDisplay">₱0.00</div><small>Current POS price for this branch.</small></div>
                <?php endif; ?>
                <label class="field span-2"><span>Reference / Notes</span><input name="notes" maxlength="180" placeholder="Optional: DR number, supplier reference or short note"></label>
            </div>
            <div class="receive-cost-warning hidden" id="costNotReady"><?= icon('alert') ?><div><strong>Cost Price is not set yet.</strong><span>Ask the Owner to set the cost in Products → Variants before receiving this stock.</span></div></div>

            <div class="identifier-panel receive-identifiers hidden" id="identifierPanel">
                <div class="identifier-panel-head">
                    <div><h3 id="identifierPanelTitle">Serial Numbers</h3><p id="identifierPanelHint">Scan or enter one Serial Number for each device.</p></div>
                    <div class="identifier-panel-actions">
                        <span class="scanner-ready-badge" id="scannerReadyBadge"><span></span>Scanner ready</span>
                        <span class="identifier-progress" id="identifierCountBadge">0 / 0</span>
                        <button class="btn btn-outline btn-sm" type="button" id="focusScannerBtn">Focus Scanner</button>
                        <button class="btn btn-outline btn-sm" type="button" id="openPasteIdentifiers">Paste Multiple</button>
                    </div>
                </div>
                <div class="scanner-help">Scan the highlighted field. Enter or Tab moves to the next field automatically.</div>
                <div class="identifier-list" id="identifierRows"></div>
            </div>
        </div>
    </section>

    <div class="receive-form-actions hidden" id="receiveFormActions">
        <a class="btn btn-secondary" href="index.php?page=inventory">Cancel</a>
        <button class="btn btn-primary" type="submit"><?= icon('stock') ?> Review Stock</button>
    </div>
</form>

<div class="modal" id="variantModal" hidden>
    <div class="modal-backdrop" data-variant-close></div>
    <div class="modal-dialog receive-variant-dialog">
        <div class="modal-header">
            <div><span class="eyebrow">NEW VARIANT</span><h2>Add Variant</h2><p class="modal-subtitle" id="variantModalModel">Add the missing storage/specs without leaving Receive Stock.</p></div>
            <button type="button" class="icon-button" data-variant-close>×</button>
        </div>
        <div class="modal-body">
            <div class="form-grid two" id="variantFields">
                <label class="field hidden" id="variantRamField"><span>RAM <b>*</b></span><select id="variantRam"><option value="">Select RAM</option><option>4GB</option><option>6GB</option><option>8GB</option><option>12GB</option><option>16GB</option><option>24GB</option></select></label>
                <label class="field"><span>Storage <b>*</b></span><select id="variantStorage"><option value="">Select storage</option><option>64GB</option><option>128GB</option><option>256GB</option><option>512GB</option><option>1TB</option><option>2TB</option></select></label>
                <label class="field hidden" id="variantColorField"><span>Color <b>*</b></span><input id="variantColor" data-uppercase maxlength="80" placeholder="E.G. BLACK TITANIUM"></label>
                <label class="field hidden" id="variantConnectivityField"><span>Connectivity <b>*</b></span><select id="variantConnectivity"><option value="">Select connectivity</option><option>Wi-Fi</option><option>Wi-Fi + Cellular</option></select></label>
                <?php if ($isOwner): ?><label class="field"><span>Cost Price / Unit <b>*</b></span><div class="money-input"><span>₱</span><input id="variantCostPrice" type="number" min="0.01" step="0.01" placeholder="0.00"></div><small>Owner-only cost for newly received units.</small></label><?php endif; ?>
                <label class="field <?= $isOwner ? '' : 'span-2' ?>"><span>Selling Price <b>*</b></span><div class="money-input"><span>₱</span><input id="variantSellingPrice" type="number" min="0.01" step="0.01" placeholder="0.00"></div><small>Starting POS price for this branch.</small></label>
            </div>
            <div class="alert alert-error hidden" id="variantError"></div>
        </div>
        <div class="modal-actions"><button class="btn btn-secondary" type="button" data-variant-close>Cancel</button><button class="btn btn-primary" type="button" id="saveVariantBtn">Save & Use Variant</button></div>
    </div>
</div>


<style>
#cameraCapturedPreview:not([hidden]){z-index:1}
#cameraScanStage .camera-scan-guide{z-index:2;pointer-events:none}
#cameraScanStage .camera-scan-state{z-index:3}
#cameraScanStage .camera-scan-state{pointer-events:none}
#cameraSerialSelection{position:absolute;z-index:4;border:2px solid #1670ea;background:rgba(22,112,234,.12);pointer-events:none}
.camera-serial-crop{margin-top:12px;padding:10px;border:1px solid #cbdcf8;border-radius:12px;background:#fff}
.camera-serial-crop img{display:block;max-width:100%;max-height:100px;margin:8px auto;object-fit:contain}
.camera-serial-crop small{color:var(--muted)}
#cameraScanStage .camera-scan-guide.is-photo-tap span{border-style:dashed}
.camera-scan-actions{flex-wrap:wrap;gap:10px}
.camera-scan-actions .btn{min-height:46px}
.camera-scan-actions .camera-gallery-btn{flex:1 1 44%}
.camera-scan-actions #cameraRetryBtn{flex:1 1 44%}
@media (max-width:640px){.camera-scan-actions .btn{font-size:15px}.camera-scan-actions [data-camera-close]{flex:1 1 100%}}
.identifier-pair-scan-card-single .identifier-pair-scan-btn{width:100%;margin-top:10px}
.identifier-entry-dual .identifier-input-row-solo{display:block}
.identifier-entry-dual .identifier-input-row-solo input{width:100%}
@media (max-width:640px){.identifier-pair-scan-card-single{padding:16px}.identifier-pair-scan-card-single .identifier-pair-scan-btn{min-height:54px;font-size:17px}}
</style>

<div class="modal camera-scan-modal" id="cameraScanModal" hidden>
    <div class="modal-backdrop" data-camera-close></div>
    <div class="modal-dialog camera-scan-dialog" role="dialog" aria-modal="true" aria-labelledby="cameraScanTitle">
        <div class="modal-header">
            <div><span class="eyebrow">CAMERA SCANNER</span><h2 id="cameraScanTitle">Scan Identifier</h2><p class="modal-subtitle" id="cameraScanSubtitle">Point the camera at one barcode only.</p></div>
            <button type="button" class="icon-button" data-camera-close aria-label="Close scanner">×</button>
        </div>
        <div class="modal-body">
            <label class="field hidden" id="cameraIdentifierTypeWrap"><span>Read</span><select id="cameraIdentifierType"></select><small>No IMEI on the label? Choose Serial / Barcode.</small></label>
            <div class="camera-scan-stage" id="cameraScanStage">
                <video id="cameraScanVideo" playsinline muted></video>
                <img id="cameraCapturedPreview" alt="Captured device label" hidden style="position:absolute;inset:0;width:100%;height:100%;object-fit:contain;background:#0b1422;touch-action:manipulation;cursor:crosshair;">
                <div class="camera-scan-guide" id="cameraScanGuide"><span></span></div>
                <div class="camera-scan-state" id="cameraScanState">Starting camera…</div>
                <div id="cameraSerialSelection" hidden></div>
            </div>
            <div class="camera-scan-message hidden" id="cameraScanMessage" role="status" aria-live="polite"></div>
            <button class="btn btn-outline hidden" type="button" id="cameraSelectSerialBtn">Select Serial Text</button>
            <div class="camera-serial-crop" id="cameraSerialCrop" hidden><small>Area being read — include the printed value, without the barcode.</small><img id="cameraSerialCropImage" alt="Selected identifier text"></div>
            <button class="btn btn-primary hidden camera-single-result" type="button" id="cameraUseSingleBtn"></button>
            <div id="cameraSerialReview" class="camera-serial-review hidden">
                <label class="field"><span id="cameraReviewLabel">Check Serial Number against the label</span><input id="cameraSerialValue" type="text" maxlength="80" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="TYPE OR CORRECT SERIAL NUMBER"></label>
                <label class="field hidden" id="cameraReviewSecondary"><span>Check IMEI 2 against the label</span><input id="cameraReviewSecondaryValue" type="text" inputmode="numeric" maxlength="15" autocomplete="off" spellcheck="false" placeholder="TYPE OR CORRECT IMEI 2"></label>
                <div id="cameraSerialCandidates" class="camera-serial-candidates"></div>
                <small id="cameraReviewHelp">Check each character, especially 0/O, 1/I and 8/B.</small>
            </div>
            <div class="camera-scan-tips">Keep one phone box label clear and sharp. Repeated copies of the same IMEI pair are accepted; different phone labels are blocked.</div>
        </div>
        <div class="modal-actions camera-scan-actions">
            <button class="btn btn-secondary" type="button" data-camera-close>Cancel</button>
            <button class="btn btn-outline camera-gallery-btn hidden" type="button" id="cameraGalleryBtn">Use Existing Photo</button>
            <button class="btn btn-outline hidden" type="button" id="cameraSkipSecondaryBtn">No IMEI 2</button>
            <button class="btn btn-outline" type="button" id="cameraRetryBtn">Take Photo</button>
            <button class="btn btn-primary hidden" type="button" id="cameraUseSerialBtn">Use Serial Number</button>
        </div>
    </div>
</div>
<input type="file" id="cameraPhotoInput" accept="image/*" capture="environment" hidden>
<input type="file" id="cameraGalleryInput" accept="image/*" hidden>

<div class="modal paste-identifiers-modal" id="pasteIdentifiersModal" hidden>
    <div class="modal-backdrop" data-paste-close></div>
    <div class="modal-dialog paste-identifiers-dialog">
        <div class="modal-header"><div><span class="eyebrow">PASTE MULTIPLE</span><h2 id="pasteIdentifiersTitle">Paste Serial Numbers</h2><p class="modal-subtitle">One identifier per line.</p></div><button type="button" class="icon-button" data-paste-close>×</button></div>
        <div class="modal-body">
            <div class="paste-identifiers-editor">
                <label class="field paste-identifiers-field"><span id="pasteIdentifiersLabel">Serial Numbers</span><textarea id="pasteIdentifiersInput" data-uppercase rows="9" placeholder="PASTE ONE SERIAL NUMBER PER LINE"></textarea><small id="pasteIdentifiersHelp">One identifier per line. Extra spaces are removed automatically.</small></label>
                <div class="paste-meta"><strong id="pasteIdentifiersMeta">0 detected</strong><span id="pasteIdentifiersWarning"></span></div>
            </div>
        </div>
        <div class="modal-actions paste-identifiers-actions"><button class="btn btn-secondary" type="button" data-paste-close>Cancel</button><button class="btn btn-primary" type="button" id="applyPasteIdentifiers">Apply List</button></div>
    </div>
</div>

<div class="modal" id="restoreUnitModal" hidden>
    <div class="modal-backdrop" data-restore-close></div>
    <div class="modal-dialog restore-unit-dialog">
        <div class="modal-header">
            <div><span class="eyebrow">PREVIOUSLY REMOVED</span><h2>Restore This Unit?</h2><p class="modal-subtitle">This IMEI / Serial Number already exists in history. Review the previous removal reason before restoring it.</p></div>
            <button type="button" class="icon-button" data-restore-close>×</button>
        </div>
        <div class="modal-body">
            <div class="restore-unit-summary">
                <div><span>IMEI / Serial Number</span><strong id="restoreUnitIdentifier">—</strong></div>
                <div><span>Restore to</span><strong id="restoreUnitProduct">—</strong><small id="restoreUnitBranch">—</small></div><div><span>Previous removal</span><strong id="restoreUnitReason">—</strong><small>History will stay in Stock Movement.</small></div>
            </div>
            <div class="restore-unit-info"><strong>No duplicate will be created.</strong><span>The same inventory record will return to Available when you finish and confirm Receive Stock.</span></div>
        </div>
        <div class="modal-actions"><button class="btn btn-secondary" type="button" data-restore-close>Cancel</button><button class="btn btn-primary" type="button" id="confirmRestoreUnit">Restore This Unit</button></div>
    </div>
</div>

<div class="modal" id="stockConfirmModal" hidden>
    <div class="modal-backdrop" data-confirm-close></div>
    <div class="modal-dialog stock-confirm-dialog">
        <div class="modal-header"><div><span class="eyebrow">REVIEW STOCK</span><h2>Check before saving</h2><p class="modal-subtitle">Confirm the item, branch, quantity and selling price.</p></div><button type="button" class="icon-button" data-confirm-close>×</button></div>
        <div class="modal-body"><div class="confirm-summary-grid"><div><span>Item</span><strong id="confirmProduct">—</strong></div><div><span>Branch</span><strong id="confirmBranch">—</strong></div><div><span>Quantity</span><strong id="confirmQuantity">—</strong></div><?php if ($isOwner): ?><div><span>Cost / Unit</span><strong id="confirmCost">—</strong></div><?php endif; ?><div><span>Selling Price</span><strong id="confirmSelling">—</strong></div></div><div class="restore-review-note hidden" id="confirmRestoreNotice"><strong>Restore existing unit</strong><span>Previously removed stock-correction units will be reactivated using the same Serial Number / IMEI. No duplicate record will be created.</span></div><div class="confirm-imeis hidden" id="confirmIdentifiers"></div></div>
        <div class="modal-actions"><button class="btn btn-secondary" type="button" data-confirm-close>Go Back</button><button class="btn btn-primary" type="button" id="confirmStockIn">Confirm & Save</button></div>
    </div>
</div>

<script src="assets/vendor/zxing-wasm/reader.js"></script>
<script src="assets/js/imei-reader.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/imei-reader.js') ?>"></script>
<script src="assets/vendor/legacy-scanner/zxing.min.js"></script>
<script src="assets/vendor/legacy-scanner/tesseract.min.js"></script>
<script src="assets/js/serial-label-reader.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/serial-label-reader.js') ?>"></script>
<script>
(() => {
const items = <?= json_encode($itemPayload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const csrf = <?= json_encode(Csrf::token()) ?>;
const presetProductId = <?= (int)$presetProductId ?>;
const isOwner = <?= $isOwner ? 'true' : 'false' ?>;
const assignedBranchName = <?= json_encode($assignedBranchName, JSON_UNESCAPED_UNICODE) ?>;
const assignedBranchId = <?= (int)($assignedBranchId ?: 0) ?>;
const canEditSelling = <?= $canEditSelling ? 'true' : 'false' ?>;
const $ = id => document.getElementById(id);
const form = $('receiveStockForm');
const itemSearch = $('itemSearch');
const results = $('itemSearchResults');
const selectedItemCard = $('selectedItemCard');
const variantStep = $('variantStep');
const stockStep = $('stockDetailsStep');
const actions = $('receiveFormActions');
const variantGrid = $('variantGrid');
const noVariants = $('noVariantsBox');
const productId = $('productId');
const quantity = $('quantityInput');
const identifierPanel = $('identifierPanel');
const identifierRows = $('identifierRows');
const countBadge = $('identifierCountBadge');
const confirmModal = $('stockConfirmModal');
const variantModal = $('variantModal');
const pasteModal = $('pasteIdentifiersModal');
let selectedItem = null;
let selectedVariant = null;
let identifierTimer = null;

function money(v){ return '₱' + Number(v || 0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function esc(v=''){ return String(v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function initials(label=''){ return label.split(/\s+/).filter(Boolean).slice(0,2).map(x=>x[0]).join('').toUpperCase() || 'IT'; }
function isApple(item){ return item?.appleSerial === true; }
function itemMatches(item,q){ q=q.toLowerCase(); return [item.label,item.category,item.barcode,item.type].filter(Boolean).some(v=>String(v).toLowerCase().includes(q)); }
function activeBranchId(){ return isOwner ? Number($('branchSelect')?.value||0) : assignedBranchId; }
function variantSelling(v){ const bid=activeBranchId(); return Number((v?.prices&&v.prices[bid]!==undefined)?v.prices[bid]:v?.selling||0); }
function syncPriceFields(){ if(!selectedVariant)return; const selling=variantSelling(selectedVariant); if(canEditSelling&&$('sellingPriceInput')) $('sellingPriceInput').value=selling>0?selling.toFixed(2):''; if($('sellingPriceDisplay')) $('sellingPriceDisplay').textContent=money(selling); if(isOwner&&$('costPriceDisplay')) $('costPriceDisplay').textContent=selectedVariant.costReady?money(selectedVariant.cost):'Pending'; const warning=$('costNotReady'); if(warning){ warning.classList.toggle('hidden',!isOwner || !!selectedVariant.costReady); if(isOwner && !selectedVariant.costReady){ warning.querySelector('strong').textContent='Cost Price is still pending.'; warning.querySelector('span').textContent='You can receive this stock now and complete the protected cost later.'; } } }

function renderResults(){
  const q=itemSearch.value.trim();
  const matched=items.filter(i=>!q || itemMatches(i,q)).slice(0,12);
  if(!matched.length){ results.innerHTML='<div class="receive-result-empty"><strong>No item found</strong><span>Try another search or add the model in Products.</span></div>'; }
  else results.innerHTML=matched.map(item=>{
    const meta=item.kind==='model' ? `${item.type==='tablet'?'Tablet':'Phone'} • ${item.variants.length} variant${item.variants.length===1?'':'s'}` : `${item.category||'Accessory'}${item.barcode?' • '+item.barcode:''}`;
    return `<button type="button" class="receive-result-row" data-item-kind="${item.kind}" data-item-id="${item.id}"><span class="receive-result-avatar">${esc(initials(item.label))}</span><span><strong>${esc(item.label)}</strong><small>${esc(meta)}</small></span><b>›</b></button>`;
  }).join('');
  results.hidden=false;
}

function hideResults(){ setTimeout(()=>results.hidden=true,120); }
itemSearch.addEventListener('focus',renderResults);
itemSearch.addEventListener('input',renderResults);
itemSearch.addEventListener('blur',hideResults);
results.addEventListener('mousedown',e=>e.preventDefault());
results.addEventListener('click',e=>{
  const btn=e.target.closest('[data-item-id]'); if(!btn)return;
  const item=items.find(i=>i.kind===btn.dataset.itemKind && String(i.id)===btn.dataset.itemId); if(item) selectItem(item);
});

function selectItem(item){
  // Identifiers belong to the previous device, not to the next selected item.
  identifierRows.innerHTML='';
  delete identifierRows.dataset.mode;
  selectedItem=item; selectedVariant=null; productId.value=''; itemSearch.value=''; results.hidden=true;
  selectedItemCard.classList.remove('hidden'); $('selectedItemIcon').textContent=initials(item.label); $('selectedItemName').textContent=item.label;
  $('selectedItemMeta').textContent=item.kind==='model' ? (item.type==='tablet'?'Tablet':'Phone') : (item.category||'Accessory');
  $('receiveSearchWrap').classList.add('hidden');
  if(item.kind==='accessory'){
    variantStep.classList.add('hidden');
    selectVariant({id:item.id,specs:item.category||'Accessory',selling:item.selling,prices:item.prices||{},cost:item.cost,costReady:item.costReady,type:'accessory'},true);
  } else {
    variantStep.classList.remove('hidden'); renderVariants(); stockStep.classList.add('hidden'); actions.classList.add('hidden');
  }
}
$('changeItemBtn').addEventListener('click',()=>{
  selectedItem=null; selectedVariant=null; productId.value=''; selectedItemCard.classList.add('hidden'); $('receiveSearchWrap').classList.remove('hidden'); variantStep.classList.add('hidden'); stockStep.classList.add('hidden'); actions.classList.add('hidden'); itemSearch.focus();
});

function renderVariants(){
  if(!selectedItem || selectedItem.kind!=='model') return;
  const vars=selectedItem.variants || [];
  noVariants.classList.toggle('hidden',vars.length>0);
  variantGrid.classList.toggle('hidden',vars.length===0);
  variantGrid.innerHTML=vars.map(v=>`<button type="button" class="receive-variant-card ${selectedVariant?.id===v.id?'selected':''}" data-variant-id="${v.id}"><span><strong>${esc(v.specs)}</strong><small>${money(variantSelling(v))} selling price</small></span><b>✓</b></button>`).join('');
}
variantGrid.addEventListener('click',e=>{ const btn=e.target.closest('[data-variant-id]'); if(!btn)return; const v=selectedItem.variants.find(x=>String(x.id)===btn.dataset.variantId); if(v)selectVariant(v); });

function selectVariant(v,isAccessory=false){
  selectedVariant=v; productId.value=v.id; if(!isAccessory) renderVariants();
  stockStep.classList.remove('hidden'); actions.classList.remove('hidden');
  quantity.max = selectedVariant.type==='accessory' ? '100000' : '100';
  $('quantityHint').textContent = selectedVariant.type==='accessory' ? 'Enter how many accessory units arrived.' : 'Enter how many devices arrived. We will create one Serial Number / IMEI field for each unit.';
  $('stockStepNumber').textContent = isAccessory ? '2' : '3';
  $('selectedVariantSummary').innerHTML=`<div><small>${isAccessory?'Selected item':'Selected variant'}</small><strong>${esc(selectedItem.label)}</strong><span>${esc(v.specs||'')}</span></div><div><small>Branch Selling Price</small><strong id="summarySellingPrice">${money(variantSelling(v))}</strong></div>`;
  syncPriceFields();
  configureIdentifiers();
}

if(isOwner && $('branchSelect')) $('branchSelect').addEventListener('change',()=>{ if(selectedVariant){ syncPriceFields(); const summary=$('summarySellingPrice'); if(summary)summary.textContent=money(variantSelling(selectedVariant)); document.querySelectorAll('[data-identifier-input]').forEach(input=>{ if(input.value.trim()) checkIdentifier(input); }); } });

function openVariant(){
  if(!selectedItem || selectedItem.kind!=='model')return;
  $('variantModalModel').textContent=selectedItem.label;
  $('variantError').classList.add('hidden'); $('variantError').textContent='';
  $('variantRam').value=''; $('variantStorage').value=''; $('variantColor').value=''; $('variantConnectivity').value=''; $('variantSellingPrice').value=''; if($('variantCostPrice')) $('variantCostPrice').value='';
  const apple=isApple(selectedItem), tablet=selectedItem.type==='tablet';
  $('variantRamField').classList.toggle('hidden',apple);
  $('variantColorField').classList.toggle('hidden',!(apple && !tablet));
  $('variantConnectivityField').classList.toggle('hidden',!tablet);
  variantModal.hidden=false; document.body.classList.add('modal-open');
}
$('openAddVariant').addEventListener('click',openVariant); $('openAddVariantEmpty').addEventListener('click',openVariant);
document.querySelectorAll('[data-variant-close]').forEach(b=>b.addEventListener('click',()=>{variantModal.hidden=true;document.body.classList.remove('modal-open');}));
$('saveVariantBtn').addEventListener('click',async()=>{
  if(!selectedItem)return;
  const payload=new URLSearchParams({ajax_action:'create_variant',_csrf:csrf,model_id:String(selectedItem.id),storage:$('variantStorage').value,ram:$('variantRam').value,color:$('variantColor').value,connectivity:$('variantConnectivity').value,selling_price:$('variantSellingPrice').value,cost_price:$('variantCostPrice')?.value||'',branch_id:String(activeBranchId()||'')});
  const btn=$('saveVariantBtn'); btn.disabled=true; btn.textContent='Saving…';
  try{
    const res=await fetch('actions/stock_in.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body:payload.toString()});
    const data=await res.json(); if(!res.ok||!data.ok)throw new Error(data.error||'Unable to add variant.');
    const existingIndex=selectedItem.variants.findIndex(v=>Number(v.id)===Number(data.variant.id));
    if(existingIndex>=0) selectedItem.variants[existingIndex]=data.variant; else selectedItem.variants.push(data.variant);
    variantModal.hidden=true; document.body.classList.remove('modal-open'); selectVariant(data.variant);
  }catch(err){ $('variantError').textContent=err.message; $('variantError').classList.remove('hidden'); }
  finally{ btn.disabled=false; btn.textContent='Save & Use Variant'; }
});

function identifierKind(){
  if(!selectedItem || selectedItem.kind!=='model')return null;
  if(isApple(selectedItem))return 'serial';
  if(selectedItem.type==='tablet' && selectedVariant && /Wi-Fi$/i.test(selectedVariant.specs) && !/Cellular/i.test(selectedVariant.specs))return 'serial';
  return 'imei';
}
function usesDualImei(){
  return !!(selectedItem && selectedItem.kind==='model' && selectedItem.type==='phone' && !isApple(selectedItem));
}
function identifierInputKind(input){
  if(input?.dataset.identifierSecondary==='1')return 'imei';
  return input?.closest('.identifier-entry')?.querySelector('[data-identifier-type]')?.value || identifierKind();
}
function validStockBarcode(value){
  return /^[\x21-\x7e]{1,120}$/.test(value);
}
function applyIdentifierType(row,kind){
  const primary=row.querySelector('[data-identifier-primary]');
  const secondary=row.querySelector('[data-identifier-secondary]');
  const selector=row.querySelector('[data-identifier-type]');
  const barcode=kind==='barcode';
  if(barcode && secondary?.value.trim()){
    selector.value='imei';
    setIdentifierState(primary,'error','Clear IMEI 2 before using a Serial / Barcode for this unit.');
    return false;
  }
  selector.value=kind;
  const label=barcode?'Serial / Barcode':kind==='serial'?'Serial Number':usesDualImei()?'IMEI 1':'IMEI';
  primary.inputMode=kind==='imei'?'numeric':'text';
  primary.maxLength=barcode?120:kind==='imei'?15:80;
  if(kind==='imei')primary.setAttribute('pattern','[0-9]*');else primary.removeAttribute('pattern');
  primary.placeholder='SCAN OR ENTER '+label.toUpperCase();
  const wrap=primary.closest('.identifier-field-wrap');
  wrap.querySelector('.identifier-field-label').innerHTML=esc(label)+' <b>*</b>';
  const button=wrap.querySelector('[data-camera-scan]');
  if(button){button.textContent='Scan '+label;button.setAttribute('aria-label','Scan '+label+' with camera');}
  if(secondary){
    // Keep an empty posted slot so quantities with mixed identifier types stay aligned.
    secondary.readOnly=barcode;
    secondary.closest('.identifier-field-wrap').classList.toggle('hidden',barcode);
  }
  const card=row.querySelector('.identifier-pair-scan-card');
  if(card){
    card.querySelector('strong').textContent=barcode?'Scan Serial / Barcode':'Scan Device IMEIs';
    card.querySelector('span').textContent=barcode?'Read the barcode or select its printed value, then tap Use.':'Frame both IMEI barcodes on one box label, or read them one at a time.';
    card.querySelector('button').textContent=barcode?'Scan Barcode':'Scan IMEIs';
  }
  return true;
}
function configureIdentifiers(){
  if(!selectedVariant || selectedVariant.type==='accessory'){identifierPanel.classList.add('hidden'); return;}
  identifierPanel.classList.remove('hidden');
  const kind=identifierKind();
  if(usesDualImei()){
    $('identifierPanelHint').textContent='IMEI 1 is required. IMEI 2 is optional for dual-SIM phones.';
  }else{
    const label=kind==='serial'?'Serial Number':'IMEI';
    $('identifierPanelHint').textContent=`Scan or enter one ${label} for each device.`;
  }
  $('openPasteIdentifiers').textContent='Paste Multiple';
  renderIdentifierRows();
}
function identifierFieldHtml({name,value,placeholder,label,required=false,secondary=false,numeric=false,includeCamera=true,kind=identifierKind()}){
  const scanLabel=label || (identifierKind()==='serial'?'Serial Number':'IMEI');
  return `<div class="identifier-field-wrap">
    ${secondary?'':isApple(selectedItem)?'<input type="hidden" name="identifier_types[]" data-identifier-type value="serial">':`<label class="field"><span>Identifier type</span><select name="identifier_types[]" data-identifier-type><option value="${identifierKind()}" ${kind===identifierKind()?'selected':''}>${identifierKind()==='imei'?'IMEI':'Serial Number'}</option><option value="barcode" ${kind==='barcode'?'selected':''}>Serial / Barcode (no IMEI)</option></select></label>`}
    ${label?`<span class="identifier-field-label">${esc(label)}${required?' <b>*</b>':''}</span>`:''}
    <div class="identifier-input-row ${includeCamera?'':'identifier-input-row-solo'}">
      <input name="${name}" value="${esc(value||'')}" autocomplete="off" placeholder="${esc(placeholder)}"
        ${numeric?'inputmode="numeric" pattern="[0-9]*"':''}
        data-identifier-input ${secondary?'data-identifier-secondary="1"':'data-identifier-primary="1"'} data-uppercase>
      ${includeCamera?`<button class="btn btn-outline identifier-camera-btn" type="button" data-camera-scan aria-label="Scan ${esc(scanLabel)} with camera">Scan ${esc(scanLabel)}</button>`:''}
    </div>
    <div class="identifier-entry-feedback">
      <span class="identifier-entry-status" data-identifier-status></span>
      ${secondary?'':'<button class="identifier-restore-btn" type="button" data-restore-trigger hidden>Restore This Unit</button>'}
    </div>
  </div>`;
}
function renderIdentifierRows(){
  if(identifierPanel.classList.contains('hidden'))return;
  const count=Math.max(1,Math.min(100,Number(quantity.value)||1));
  const kind=identifierKind();
  const dual=usesDualImei();

  const mode=dual?'dual-imei':kind;
  if(identifierRows.dataset.mode && identifierRows.dataset.mode!==mode)identifierRows.innerHTML='';
  identifierRows.dataset.mode=mode;

  const existingPrimary=[...identifierRows.querySelectorAll('[data-identifier-primary]')].map(i=>i.value);
  const existingSecondary=[...identifierRows.querySelectorAll('[data-identifier-secondary]')].map(i=>i.value);
  const existingTypes=[...identifierRows.querySelectorAll('[data-identifier-type]')].map(i=>i.value);

  if(dual){
    $('identifierPanelTitle').textContent='IMEI Numbers';
    $('identifierPanelHint').textContent=count===1
      ? 'Enter IMEI 1. IMEI 2 is optional if the phone has a second IMEI.'
      : `Enter IMEI 1 for each of the ${count} phones. IMEI 2 is optional.`;
    identifierRows.innerHTML=Array.from({length:count},(_,i)=>`
      <div class="identifier-entry identifier-entry-dual">
        <span class="identifier-entry-number">${i+1}</span>
        <div class="identifier-dual-grid">
          <div class="identifier-pair-scan-card identifier-pair-scan-card-single">
            <div>
              <strong>Scan Device IMEIs</strong>
              <span>Open the camera and frame both IMEI barcodes on one box label. You can also scan the top barcode first, then the bottom barcode.</span>
            </div>
            <button class="btn btn-primary identifier-pair-scan-btn" type="button" data-guided-imei-scan>Scan IMEIs</button>
          </div>
          ${identifierFieldHtml({name:'identifiers[]',value:existingPrimary[i]||'',placeholder:'SCAN OR ENTER IMEI 1',label:'IMEI 1',required:true,numeric:true,includeCamera:false,kind:existingTypes[i]||kind})}
          ${identifierFieldHtml({name:'secondary_identifiers[]',value:existingSecondary[i]||'',placeholder:'SCAN OR ENTER IMEI 2 (OPTIONAL)',label:'IMEI 2',secondary:true,numeric:true,includeCamera:false})}
        </div>
      </div>`).join('');
  }else{
    const singular=kind==='serial'?'Serial Number':'IMEI';
    const label=kind==='serial'?'serial number':'IMEI';
    $('identifierPanelTitle').textContent = count===1 ? singular : singular+'s';
    $('identifierPanelHint').textContent = count===1 ? `Enter the ${singular} for this device.` : `Enter one ${singular} for each of the ${count} devices.`;
    identifierRows.innerHTML=Array.from({length:count},(_,i)=>`
      <div class="identifier-entry">
        <span class="identifier-entry-number">${i+1}</span>
        ${identifierFieldHtml({name:'identifiers[]',value:existingPrimary[i]||'',placeholder:`SCAN OR ENTER ${label.toUpperCase()}`,label:singular,required:true,numeric:kind==='imei',kind:existingTypes[i]||kind})}
      </div>`).join('');
  }

  identifierRows.querySelectorAll('.identifier-entry').forEach(row=>applyIdentifierType(row,row.querySelector('[data-identifier-type]').value));
  if(!isApple(selectedItem))$('identifierPanelHint').textContent+=' No IMEI? Choose Serial / Barcode for that unit.';
  $('openPasteIdentifiers').classList.toggle('hidden',count===1);
  countBadge.classList.toggle('hidden',count===1);
  bindIdentifierInputs();
  updateIdentifierCount();
  updateScannerBadge('ready');
  if(document.activeElement!==quantity) scheduleScannerFocus();
}
quantity.addEventListener('input',()=>{if(Number(quantity.value)>100)quantity.value=100;if(Number(quantity.value)<1)quantity.value=1;renderIdentifierRows();});
quantity.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();focusFirstEmptyIdentifier();}});
quantity.addEventListener('change',()=>setTimeout(focusFirstEmptyIdentifier,60));

function setIdentifierState(input,state,message=''){
  const wrap=input.closest('.identifier-field-wrap') || input.closest('.identifier-entry');
  const row=input.closest('.identifier-entry');
  const status=wrap?.querySelector('[data-identifier-status]');
  const restoreBtn=wrap?.querySelector('[data-restore-trigger]');
  wrap?.classList.remove('is-valid','is-error','is-checking','is-restore','is-restore-approved');
  input.classList.remove('input-error');
  if(state)wrap?.classList.add('is-'+state);
  if(state==='error')input.classList.add('input-error');
  if(status)status.textContent=message;
  if(restoreBtn)restoreBtn.hidden=state!=='restore';
  if(row && state==='restore-approved') row.classList.add('has-restore');
}
function clearRestoreState(input){
  delete input.dataset.restoreUnitId;
  delete input.dataset.restoreApproved;
  delete input.dataset.restoreApprovedValue;
  delete input.dataset.restoreBranch;
  delete input.dataset.restoreReason;
}
function updateScannerBadge(state='ready',text=''){
  const badge=$('scannerReadyBadge');
  if(!badge)return;
  badge.classList.remove('is-ready','is-checking','is-error');
  badge.classList.add('is-'+state);
  badge.lastChild.textContent=text || (state==='checking'?'Checking':state==='error'?'Check field':'Scanner ready');
}
function focusFirstEmptyIdentifier(){
  const inputs=[...identifierRows.querySelectorAll('[data-identifier-input]')];
  const target=inputs.find(i=>!i.value.trim() && !i.disabled && !i.readOnly) || inputs[0];
  if(target){target.focus();target.select?.();updateScannerBadge('ready');}
}
function scheduleScannerFocus(){setTimeout(()=>{if(!identifierPanel.classList.contains('hidden'))focusFirstEmptyIdentifier();},90);}
function focusNextIdentifier(input){
  const rows=[...identifierRows.querySelectorAll('.identifier-entry')];
  const row=input.closest('.identifier-entry');
  const rowIndex=rows.indexOf(row);
  let next=null;
  if(usesDualImei() && identifierInputKind(input)!=='barcode'){
    if(input.dataset.identifierPrimary==='1') next=row?.querySelector('[data-identifier-secondary]');
    else next=rows[rowIndex+1]?.querySelector('[data-identifier-primary]');
  }else{
    next=rows[rowIndex+1]?.querySelector('[data-identifier-primary]');
  }
  if(next){next.focus();next.select?.();updateScannerBadge('ready');}
  else{document.querySelector('#receiveFormActions button[type="submit"]')?.focus();updateScannerBadge('ready','Scan complete');}
}
function validImeiChecksum(value){
  if(!/^\d{15}$/.test(value))return false;
  let sum=0;
  for(let i=0;i<14;i++){
    let digit=Number(value[i]);
    if(i%2===1){digit*=2;if(digit>9)digit-=9;}
    sum+=digit;
  }
  return ((10-(sum%10))%10)===Number(value[14]);
}

const cameraModal=$('cameraScanModal');
const cameraVideo=$('cameraScanVideo');
const cameraState=$('cameraScanState');
const cameraMessage=$('cameraScanMessage');
const cameraPhotoInput=$('cameraPhotoInput');
const cameraGalleryInput=$('cameraGalleryInput');
const cameraGalleryBtn=$('cameraGalleryBtn');
const cameraRetryBtn=$('cameraRetryBtn');
const cameraSkipSecondaryBtn=$('cameraSkipSecondaryBtn');
const cameraCapturedPreview=$('cameraCapturedPreview');
const cameraScanGuide=$('cameraScanGuide');
let cameraCapturedFile=null;
let cameraCapturedUrl='';
let cameraTargetInput=null;
let cameraReadKind=null;
let cameraPairRow=null;
let guidedImeiRow=null;
let guidedImeiStep=0;
let cameraStream=null;
let cameraFrameHandle=0;
let cameraBusy=false;
let cameraSession=0;
let cameraLastFrame=0;
let cameraSingleValue='';
let cameraReviewPair=null;
let serialSelecting=false,serialSelectionStart=null,serialSelectionPointer=null,serialIgnoreClickUntil=0;
const cameraUseSingleBtn=$('cameraUseSingleBtn');

function cameraKind(){return cameraReadKind || identifierInputKind(cameraTargetInput);}
function canReadStockBarcode(){
  return !isApple(selectedItem) && cameraTargetInput?.dataset.identifierPrimary==='1';
}
function configureCameraType(kind=identifierInputKind(cameraTargetInput)){
  cameraReadKind=kind;
  const select=$('cameraIdentifierType'),base=identifierKind();
  select.replaceChildren();
  const add=(value,label)=>{const option=document.createElement('option');option.value=value;option.textContent=label;select.appendChild(option);};
  add(base,base==='imei'?'IMEI':'Serial Number');
  if(canReadStockBarcode())add('barcode','Serial / Barcode');
  select.value=kind;
  $('cameraIdentifierTypeWrap').classList.toggle('hidden',!canReadStockBarcode());
  resetSerialSelection();
  const label=cameraFieldLabel(cameraTargetInput);
  $('cameraScanTitle').textContent='Scan '+label;
  $('cameraScanSubtitle').textContent=kind==='barcode'?'Read any barcode or its printed value, check it, then tap Use Barcode.':'Only a valid '+label+' will be accepted.';
  cameraModal.querySelector('.camera-scan-tips').textContent=kind==='barcode'
    ? 'Select the barcode or printed S/N for this unit. Choose the matching value if several codes are found.'
    : kind==='serial'?'Frame the Serial Number / S/N label.':'Frame the IMEI label. No IMEI? Choose Serial / Barcode above.';
  cameraSkipSecondaryBtn?.classList.add('hidden');
  if(kind==='imei' && guidedImeiRow)updateGuidedImeiUi();
}
$('cameraIdentifierType').addEventListener('change',()=>{
  if(!cameraTargetInput || cameraModal.hidden)return;
  const kind=$('cameraIdentifierType').value;
  if(kind==='barcode' && !canReadStockBarcode())return;
  cameraStop();configureCameraType(kind);
  if(cameraCapturedFile){
    $('cameraSelectSerialBtn').classList.remove('hidden');
    decodeLocalPhoto(cameraCapturedFile);
  }else{
    showSerialReview('','Take a photo or enter the printed value below.');
  }
});
function normalizeBarcodeReading(value){
  // Strip printed field labels, never characters from an unlabelled code.
  return String(value||'').replace(/^\s*(?:\(S\)\s*)?(?:SERIAL\s*(?:NUMBER|NO\.?)?|S\s*\/\s*N|SN|MAC(?:\s*ADDRESS)?|BARCODE|UPC|EAN)\s*[:#]\s*/i,'').replace(/\s+/g,'').toUpperCase();
}
async function readPrintedIdentifier(canvas,options){
  const kind=cameraKind();
  let result=await MalbcoffSerialOcr.read(canvas,{...options,type:kind});
  if(options.cancelled())return {values:[]};
  if(!result.values.length && kind!=='barcode' && canReadStockBarcode()){
    result=await MalbcoffSerialOcr.read(canvas,{...options,type:'barcode'});
    if(options.cancelled())return {values:[]};
    result.values=result.values.map(normalizeBarcodeReading).filter(validStockBarcode);
    if(result.values.length)configureCameraType('barcode');
  }
  return result;
}
function resetSerialSelection(){
  serialSelecting=false;serialSelectionStart=null;serialSelectionPointer=null;
  $('cameraSerialSelection').hidden=true;
  $('cameraSelectSerialBtn').textContent=cameraKind()==='barcode'?'Select Text':cameraKind()==='imei'?'Select IMEI Text':'Select Serial Text';
  cameraCapturedPreview.style.touchAction='manipulation';
}
function showSerialCrop(image,session){
  if(session!==cameraSession || cameraModal.hidden)return;
  $('cameraSerialCropImage').src=image.toDataURL('image/png');
  $('cameraSerialCrop').hidden=false;
}

function cameraFieldLabel(input){
  if(input?.dataset.identifierSecondary==='1')return 'IMEI 2';
  const kind=input===cameraTargetInput?cameraKind():identifierInputKind(input);
  if(kind==='barcode')return 'Barcode';
  if(kind==='serial')return 'Serial Number';
  return usesDualImei()?'IMEI 1':'IMEI';
}
function cameraStop(){
  cameraSession++;
  resetSerialSelection();
  $('cameraSelectSerialBtn').classList.add('hidden');
  $('cameraSerialCrop').hidden=true;
  $('cameraSerialCropImage').removeAttribute('src');
  $('cameraSerialReview').classList.add('hidden');
  $('cameraUseSerialBtn').classList.add('hidden');
  $('cameraSerialCandidates').replaceChildren();
  $('cameraSerialValue').value='';
  cameraReviewPair=null;
  $('cameraReviewSecondary').classList.add('hidden');
  $('cameraReviewSecondaryValue').value='';
  cameraSingleValue='';
  cameraUseSingleBtn?.classList.add('hidden');
  if(cameraFrameHandle)cancelAnimationFrame(cameraFrameHandle);
  cameraFrameHandle=0;
  cameraBusy=false;
  if(cameraStream){cameraStream.getTracks().forEach(track=>track.stop());cameraStream=null;}
  if(cameraVideo){cameraVideo.srcObject=null;}
}
function clearCapturedPhoto(){
  cameraCapturedFile=null;
  if(cameraCapturedUrl){URL.revokeObjectURL(cameraCapturedUrl);cameraCapturedUrl='';}
  if(cameraCapturedPreview){
    cameraCapturedPreview.hidden=true;
    cameraCapturedPreview.removeAttribute('src');
  }
  if(cameraVideo)cameraVideo.hidden=false;
  if(cameraScanGuide)cameraScanGuide.classList.remove('is-photo-tap');
}
function showCapturedPhoto(file){
  if(!file || !cameraCapturedPreview)return;
  cameraStop();
  if(cameraCapturedUrl)URL.revokeObjectURL(cameraCapturedUrl);
  cameraCapturedFile=file;
  cameraCapturedUrl=URL.createObjectURL(file);
  cameraCapturedPreview.src=cameraCapturedUrl;
  cameraCapturedPreview.hidden=false;
  if(cameraVideo)cameraVideo.hidden=true;
  if(cameraScanGuide)cameraScanGuide.classList.add('is-photo-tap');
  $('cameraSelectSerialBtn').classList.remove('hidden');
  cameraCapturedPreview.alt='Captured label; tap the printed '+cameraFieldLabel(cameraTargetInput);
  if(cameraRetryBtn)cameraRetryBtn.textContent='Take Another Photo';
  if(cameraGalleryBtn)cameraGalleryBtn.textContent='Use Different Photo';
}
async function ocrTappedImeiRegion(file,nx,ny,slot){
  if(!file)return {value:'',candidates:[]};
  const worker=await getCameraOcrWorker();
  const bitmap=await createImageBitmap(file);
  try{
    // Precision row reader: the user already tells us exactly which printed
    // number to read by tapping it. Keep the crop thin so nearby CODE128 bars,
    // QR codes and the other IMEI row cannot confuse OCR.
    const attempts=[
      {w:.48,h:.050,y:.000},
      {w:.60,h:.064,y:.004},
      {w:.40,h:.045,y:.008}
    ];
    const all=[];
    for(let ai=0;ai<attempts.length;ai++){
      const a=attempts[ai];
      const cropW=Math.max(240,Math.round(bitmap.width*a.w));
      const cropH=Math.max(44,Math.round(bitmap.height*a.h));
      let left=Math.round(nx*bitmap.width-cropW/2);
      let top=Math.round((ny+a.y)*bitmap.height-cropH/2);
      left=Math.max(0,Math.min(bitmap.width-cropW,left));
      top=Math.max(0,Math.min(bitmap.height-cropH,top));

      const targetW=Math.min(1800,Math.max(1200,cropW*4));
      const scale=targetW/cropW;
      const c=document.createElement('canvas');
      c.width=Math.max(1,Math.round(cropW*scale));
      c.height=Math.max(1,Math.round(cropH*scale));
      const ctx=c.getContext('2d',{willReadFrequently:true});
      ctx.imageSmoothingEnabled=true;
      ctx.imageSmoothingQuality='high';
      ctx.drawImage(bitmap,left,top,cropW,cropH,0,0,c.width,c.height);

      const image=ctx.getImageData(0,0,c.width,c.height);
      const d=image.data;
      let avg=0;
      for(let i=0;i<d.length;i+=4){
        let g=(d[i]*.299)+(d[i+1]*.587)+(d[i+2]*.114);
        g=(g-128)*1.75+128;
        g=Math.max(0,Math.min(255,g));
        d[i]=d[i+1]=d[i+2]=g;
        avg+=g;
      }
      avg/=Math.max(1,d.length/4);
      ctx.putImageData(image,0,0);

      cameraState.textContent=`Reading IMEI ${slot} digits…`;
      await worker.setParameters({
        tessedit_char_whitelist:'0123456789',
        tessedit_pageseg_mode:'7',
        classify_bln_numeric_mode:'1',
        preserve_interword_spaces:'0',
        user_defined_dpi:'300'
      });
      let result=await worker.recognize(c);
      let values=guidedImeiCandidatesFromText(result?.data?.text||'');
      for(const v of values)if(!all.includes(v))all.push(v);
      if(all.length===1)return {value:all[0],candidates:all};
      if(all.length>1)break;

      // One binary retry on the same small row only. This is still fast and is
      // much more reliable for photos of small factory-label digits.
      const binary=ctx.getImageData(0,0,c.width,c.height);
      const bd=binary.data;
      const threshold=Math.max(120,Math.min(205,avg*.92));
      for(let i=0;i<bd.length;i+=4){
        const g=bd[i];
        const v=g<threshold?0:255;
        bd[i]=bd[i+1]=bd[i+2]=v;
      }
      ctx.putImageData(binary,0,0);
      await worker.setParameters({
        tessedit_char_whitelist:'0123456789',
        tessedit_pageseg_mode:'13',
        classify_bln_numeric_mode:'1',
        preserve_interword_spaces:'0',
        user_defined_dpi:'300'
      });
      result=await worker.recognize(c);
      values=guidedImeiCandidatesFromText(result?.data?.text||'');
      for(const v of values)if(!all.includes(v))all.push(v);
      if(all.length===1)return {value:all[0],candidates:all};
      if(all.length>1)break;
    }
    return {value:all.length===1?all[0]:'',candidates:all};
  }finally{bitmap.close?.();}
}
async function recognizeCapturedImeiPairQuick(file,session){
  const base=await fileToPairBaseCanvas(file,2400);
  if(session!==cameraSession)return null;
  return await MalbcoffImeiReader.read(base.canvas,{cancelled:()=>session!==cameraSession});
}
async function freezeCameraReview(session){
  if(session!==cameraSession || cameraModal.hidden)return false;
  if(!cameraStream)return true;
  const frame=document.createElement('canvas');
  frame.width=cameraVideo.videoWidth;frame.height=cameraVideo.videoHeight;
  if(!frame.width || !frame.height)return false;
  frame.getContext('2d').drawImage(cameraVideo,0,0);
  const photo=await new Promise(resolve=>frame.toBlob(resolve,'image/png'));
  if(!photo || session!==cameraSession || cameraModal.hidden)return false;
  showCapturedPhoto(photo);
  return true;
}
async function acceptGuidedPair(pair,session){
  const row=guidedImeiRow || cameraPairRow;
  if(session!==cameraSession || !row || cameraModal.hidden)return false;
  const primary=row.querySelector('[data-identifier-primary]');
  const secondary=row.querySelector('[data-identifier-secondary]');
  if(!primary || !secondary || !MalbcoffImeiReader.valid(pair[1]) || !MalbcoffImeiReader.valid(pair[2]) || pair[1]===pair[2])return false;
  if(guidedImeiStep===2 && primary.value && primary.value!==pair[1]){
    cameraShowMessage('This label does not match IMEI 1. Scan the same phone box.',true,'Different device');
    return false;
  }
  if(!await freezeCameraReview(session))return false;
  showSerialReview(pair[1],'Check IMEI 1 and IMEI 2 against the label, then tap Use Both IMEIs.',[],{primary,secondary,value:pair[2]});
  return true;
}
async function autoReadCapturedImeiPair(file){
  if(!guidedImeiRow || !file)return false;
  const session=cameraSession;
  cameraBusy=true;
  cameraState.textContent='Reading IMEI barcodes…';
  try{
    const result=await recognizeCapturedImeiPairQuick(file,session);
    if(session!==cameraSession || cameraModal.hidden || !result)return false;
    if(result.ambiguous){
      cameraShowMessage('More than two different IMEIs were found. Take a closer photo of one phone label.',true,'Multiple devices');
      return false;
    }
    if(result.pair)return await acceptGuidedPair(result.pair,session);
    if(result.unique.length===1)return await showSingleImei(result.unique[0]);
    cameraShowMessage(result.unique.length===2
      ? 'Two IMEIs were found, but their order is unclear. Take a closer upright photo of one sticker, or tap the printed IMEI 1 number.'
      : 'No clear IMEI barcode yet. Take a closer, sharp photo with both bars fully visible, or tap the printed IMEI number.',false,'Try a closer photo');
    return false;
  }catch(err){
    if(session===cameraSession)cameraShowMessage('The label reader could not run. Refresh the page and try again. You can also enter the IMEIs manually.',true,'Reader unavailable');
    return false;
  }finally{
    if(session===cameraSession)cameraBusy=false;
  }
}
function previewNormalizedPoint(event,clamped=false){
  const img=cameraCapturedPreview;
  if(!img || img.hidden || !img.naturalWidth || !img.naturalHeight)return null;
  const rect=img.getBoundingClientRect();
  const scale=Math.min(rect.width/img.naturalWidth,rect.height/img.naturalHeight);
  const shownW=img.naturalWidth*scale,shownH=img.naturalHeight*scale;
  const left=rect.left+(rect.width-shownW)/2,top=rect.top+(rect.height-shownH)/2;
  const x=event.clientX-left,y=event.clientY-top;
  if(!clamped && (x<0||y<0||x>shownW||y>shownH))return null;
  return {x:Math.max(0,Math.min(1,x/shownW)),y:Math.max(0,Math.min(1,y/shownH))};
}

function resetGuidedImeiScanner(){
  guidedImeiRow=null;
  guidedImeiStep=0;
  cameraSkipSecondaryBtn?.classList.add('hidden');
}
function closeCameraScanner(){
  cameraStop();
  clearCapturedPhoto();
  if(cameraModal)cameraModal.hidden=true;
  document.body.classList.remove('modal-open');
  cameraTargetInput?.focus();
  cameraTargetInput=null;
  cameraPairRow=null;
  resetGuidedImeiScanner();
}
function cameraShowMessage(message,isError=true,stateText=''){
  cameraState.textContent=stateText || (isError?'Scan needs attention':'Ready');
  cameraMessage.textContent=message;
  cameraMessage.classList.remove('hidden');
  cameraMessage.classList.toggle('is-error',isError);
}
async function cameraAcceptValue(raw){
  if(!cameraTargetInput || cameraModal.hidden)return false;
  const value=String(raw||'').replace(/\s+/g,'').toUpperCase();
  const valid=cameraKind()==='barcode'?validStockBarcode(value):cameraKind()==='imei'?MalbcoffImeiReader.valid(value):MalbcoffImeiReader.validSerial(value);
  if(!valid)return false;
  const session=cameraSession;
  if(!await freezeCameraReview(session))return false;
  showSerialReview(value,'Check the reading against the label, correct it if needed, then tap Use.');
  return true;
}
async function cameraDetectLoop(timestamp=0){
  if(!cameraStream || !cameraVideo || cameraModal.hidden)return;
  const session=cameraSession;
  if(!cameraBusy && cameraVideo.readyState>=2 && timestamp-cameraLastFrame>=450){
    cameraLastFrame=timestamp;cameraBusy=true;
    try{
      if(cameraKind()==='imei'){
        const result=await MalbcoffImeiReader.read(cameraVideo,{live:true,cancelled:()=>session!==cameraSession});
        if(session!==cameraSession)return;
        if(result.ambiguous){cameraShowMessage('Point at one phone label only.',true,'Multiple devices');}
        else if(result.pair && (guidedImeiRow || cameraPairRow)){
          if(await acceptGuidedPair(result.pair,session))return;
        }else if(result.unique.length){
          if(!await freezeCameraReview(session))return;
          showSerialReview(result.unique.length===1?result.unique[0]:'','Check which printed IMEI belongs to this field, then tap Use.',result.unique);
          return;
        }else if(canReadStockBarcode()){
          const other=await MalbcoffImeiReader.readCodes(cameraVideo,{live:true,cancelled:()=>session!==cameraSession});
          if(session!==cameraSession)return;
          const values=other.values.map(normalizeBarcodeReading).filter(validStockBarcode);
          if(values.length){
            if(!await freezeCameraReview(session))return;
            configureCameraType('barcode');
            showSerialReview(values.length===1?values[0]:'','No IMEI was read. Choose the Serial / Barcode for this unit, then tap Use Barcode.',values);
            return;
          }
        }
      }else{
        const result=await MalbcoffImeiReader[cameraKind()==='barcode'?'readCodes':'readSerial'](cameraVideo,{live:true,cancelled:()=>session!==cameraSession});
        if(session!==cameraSession)return;
        if(result.values.length){
          if(!await freezeCameraReview(session))return;
          showSerialReview(result.values.length===1?result.values[0]:'','Check the printed value and choose the matching reading.',result.values);
          return;
        }
      }
    }catch(err){
      if(session===cameraSession)cameraShowMessage('Live reading is unavailable. Tap Take Photo or use an existing photo.');
    }finally{
      if(session===cameraSession)cameraBusy=false;
    }
  }
  if(session===cameraSession && cameraStream && !cameraModal.hidden)cameraFrameHandle=requestAnimationFrame(cameraDetectLoop);
}
function showSingleImei(value){
  return cameraAcceptValue(value);
}
cameraUseSingleBtn.addEventListener('click',()=>{
  if(cameraSingleValue)cameraAcceptValue(cameraSingleValue);
});
let cameraOcrWorker=null;
let cameraOcrWarmup=null;
function cleanOcrText(text=''){
  return String(text)
    .toUpperCase()
    .replace(/[\u2010-\u2015]/g,'-')
    .replace(/\r/g,'\n');
}
function normalizeOcrDigits(value=''){
  return String(value)
    .toUpperCase()
    .replace(/[OQ]/g,'0')
    .replace(/[IL|]/g,'1')
    .replace(/Z/g,'2')
    .replace(/S/g,'5')
    .replace(/G/g,'6')
    .replace(/B/g,'8');
}
function validImeisFromText(text=''){
  const cleaned=cleanOcrText(text);
  const candidates=[];
  const add=value=>{
    const digits=normalizeOcrDigits(value).replace(/\D/g,'');
    if(/^\d{15}$/.test(digits) && validImeiChecksum(digits) && !candidates.includes(digits)) candidates.push(digits);
  };
  // Keep matches on the same OCR line. Using \s here can cross from IMEI 1 into
  // IMEI 2 and silently reverse the slot assignment.
  const labelled=/IMEI\s*([12])?\s*[:#\-]?\s*([0-9OQILZSG B|._\-]{15,30})/g;
  let match;
  while((match=labelled.exec(cleaned))!==null) add(match[2]);
  const compact=normalizeOcrDigits(cleaned).replace(/(?<=\d)[ \t._-]+(?=\d)/g,'');
  for(const m of compact.matchAll(/(?:^|\D)(\d{15})(?!\d)/g)) add(m[1]);
  return candidates;
}
function imeiDigitsFromFragment(fragment=''){
  const digits=normalizeOcrDigits(fragment).replace(/\D/g,'').slice(0,15);
  return /^\d{15}$/.test(digits) && validImeiChecksum(digits) ? digits : '';
}
function imeiMarkerMatch(line=''){
  // OCR often drops or distorts the first "I" in IMEI (for example \\MEI1,
  // /MEI1, MEI1, 1MEI1). Be tolerant when locating the slot label only;
  // the 15-digit value still has to pass the IMEI checksum.
  return String(line).toUpperCase().match(/(?:I|1|L|\||\\|\/)?M(?:E|3)(?:I|1|L|\|)\s*([12])\s*[:#\-]?\s*(.*)$/i);
}
function imeiAnyMarkerMatch(line=''){
  // Some sealed-box labels (notably Infinix-style labels) print both rows simply
  // as "IMEI:" with no 1/2 suffix. Keep the slot optional here so a tight
  // label crop can use the top-to-bottom pair order safely.
  return String(line).toUpperCase().match(/(?:I|1|L|\||\\|\/)?M(?:E|3)(?:I|1|L|\|)\s*([12])?\s*[:#\-]?\s*(.*)$/i);
}
function imeiRowsFromText(text=''){
  const lines=cleanOcrText(text).split(/\n+/).map(line=>line.trim()).filter(Boolean);
  const rows=[];
  for(let i=0;i<lines.length;i++){
    const marker=imeiAnyMarkerMatch(lines[i]);
    if(!marker)continue;
    const slot=marker[1]?Number(marker[1]):0;
    let value=imeiDigitsFromFragment(marker[2]);
    if(!value && lines[i+1] && !imeiAnyMarkerMatch(lines[i+1])){
      value=imeiDigitsFromFragment(lines[i+1]);
    }
    if(value)rows.push({slot,value,line:i});
  }
  return rows;
}
function pairFromImeiRows(text=''){
  const rows=imeiRowsFromText(text);
  if(!rows.length)return {pair:null,rows,ambiguous:false};

  const explicit={1:'',2:''};
  for(const row of rows){
    if(row.slot && !explicit[row.slot])explicit[row.slot]=row.value;
  }
  if(explicit[1] && explicit[2] && explicit[1]!==explicit[2]){
    return {pair:{1:explicit[1],2:explicit[2]},rows,ambiguous:false,mode:'explicit'};
  }

  // For labels that say IMEI: on both rows, score adjacent IMEI rows. Repeated
  // tear-off stickers produce A,B,A,B... so A→B wins by consensus while B→A
  // gets fewer votes. We only use rows that were explicitly introduced by an
  // IMEI marker, never arbitrary 15-digit text elsewhere on the box.
  const pairVotes=new Map();
  const unique=new Set(rows.map(r=>r.value));
  for(let i=0;i<rows.length-1;i++){
    const a=rows[i],b=rows[i+1];
    if(!a.value || !b.value || a.value===b.value)continue;
    if(a.slot===2 || b.slot===1)continue;
    const key=a.value+'|'+b.value;
    pairVotes.set(key,(pairVotes.get(key)||0)+1);
  }
  if(!pairVotes.size)return {pair:null,rows,ambiguous:unique.size>2};
  const ranked=[...pairVotes.entries()].sort((a,b)=>b[1]-a[1]);
  if(ranked.length>1 && ranked[0][1]===ranked[1][1] && ranked[0][0]!==ranked[1][0]){
    return {pair:null,rows,ambiguous:true};
  }
  if(unique.size>2 && ranked[0][1]<2)return {pair:null,rows,ambiguous:true};
  const [a,b]=ranked[0][0].split('|');
  return {pair:{1:a,2:b},rows,ambiguous:false,mode:'ordered-imei-lines',votes:ranked[0][1]};
}
function imeiSlotMarkersFromText(text=''){
  const markers={1:false,2:false};
  const lines=cleanOcrText(text).split(/\n+/).map(line=>line.trim()).filter(Boolean);
  for(const line of lines){
    const marker=imeiMarkerMatch(line);
    if(marker)markers[Number(marker[1])]=true;
  }
  return markers;
}
function labelledImeisFromText(text=''){
  const cleaned=cleanOcrText(text);
  const found={1:'',2:''};
  const lines=cleaned.split(/\n+/).map(line=>line.trim()).filter(Boolean);

  for(let i=0;i<lines.length;i++){
    const marker=imeiMarkerMatch(lines[i]);
    if(!marker)continue;
    const slot=Number(marker[1]);
    let value=imeiDigitsFromFragment(marker[2]);
    // OCR may move the number onto the next line. Only use that line when it
    // is not another IMEI slot label.
    if(!value && lines[i+1] && !imeiMarkerMatch(lines[i+1])){
      value=imeiDigitsFromFragment(lines[i+1]);
    }
    if(value)found[slot]=value;
  }

  // Exact-label fallback for OCR output that contains extra punctuation.
  for(const slot of [1,2]){
    if(found[slot])continue;
    const exact=new RegExp('IMEI\\s*'+slot+'\\s*[:#\\-]?\\s*([0-9OQILZSG B|._\\-]{15,30})','i');
    const m=cleaned.match(exact);
    if(m)found[slot]=imeiDigitsFromFragment(m[1]);
  }
  return found;
}
function imeiEvidenceFromText(text=''){
  return {
    labelled:labelledImeisFromText(text),
    ordered:validImeisFromText(text),
    markers:imeiSlotMarkersFromText(text)
  };
}
function selectImeiFromEvidence(evidence,slot=1){
  if(evidence.labelled?.[slot])return evidence.labelled[slot];

  const ordered=Array.isArray(evidence.ordered)?evidence.ordered:[];
  // When both valid IMEIs are visible in the printed label, OCR text order is
  // top-to-bottom. Packaging prints IMEI1 above IMEI2, so this safely maps the
  // pair without trusting the unrelated 1D barcode below them.
  if(ordered.length>=2)return slot===2?ordered[1]:ordered[0];

  // One detected IMEI is only safe when OCR also recognized the requested slot
  // marker and did not recognize the opposite slot marker.
  const other=slot===1?2:1;
  if(ordered.length===1 && evidence.markers?.[slot] && !evidence.markers?.[other]){
    return ordered[0];
  }
  return '';
}
function labelledImeiFromText(text='',slot=1){
  return labelledImeisFromText(text)[slot] || '';
}
function pickImeiFromOcrText(text='',slot=1,strictSlot=false){
  const evidence=imeiEvidenceFromText(text);
  if(evidence.labelled[slot])return evidence.labelled[slot];
  if(strictSlot)return '';
  return selectImeiFromEvidence(evidence,slot);
}
async function getCameraOcrWorker(){
  if(cameraOcrWorker)return cameraOcrWorker;
  if(cameraOcrWarmup)return cameraOcrWarmup;
  if(!window.Tesseract?.createWorker)throw new Error('OCR engine unavailable');
  cameraOcrWarmup=(async()=>{
    cameraState.textContent='Preparing IMEI reader…';
    const worker=await Tesseract.createWorker('eng',1,{
      logger:m=>{
        if(!cameraTargetInput)return;
        if(m.status==='recognizing text') cameraState.textContent=`Reading IMEI… ${Math.round((m.progress||0)*100)}%`;
        else if(m.status) cameraState.textContent='Preparing IMEI reader…';
      }
    });
    await worker.setParameters({
      tessedit_char_whitelist:'IME0123456789OQILZSG B|: #-._',
      tessedit_pageseg_mode:window.Tesseract?.PSM?.SPARSE_TEXT ?? '11',
      preserve_interword_spaces:'1',
      user_defined_dpi:'220'
    });
    cameraOcrWorker=worker;
    return worker;
  })();
  try{
    return await cameraOcrWarmup;
  }finally{
    cameraOcrWarmup=null;
  }
}
function warmCameraOcr(){
  if(identifierKind()!=='imei' || cameraOcrWorker || cameraOcrWarmup)return;
  getCameraOcrWorker().catch(()=>{});
}

async function fileToPairBaseCanvas(file,maxSide=1800){
  const bitmap=await createImageBitmap(file);
  const sourceWidth=bitmap.width,sourceHeight=bitmap.height;
  const scale=Math.min(1,maxSide/Math.max(sourceWidth,sourceHeight));
  const canvas=document.createElement('canvas');
  canvas.width=Math.max(1,Math.round(sourceWidth*scale));
  canvas.height=Math.max(1,Math.round(sourceHeight*scale));
  const ctx=canvas.getContext('2d',{willReadFrequently:true});
  ctx.drawImage(bitmap,0,0,canvas.width,canvas.height);
  bitmap.close?.();
  return {canvas,sourceWidth,sourceHeight};
}
function zxingPointXY(point){
  if(!point)return null;
  const x=typeof point.getX==='function'?point.getX():point.x;
  const y=typeof point.getY==='function'?point.getY():point.y;
  return Number.isFinite(Number(x))&&Number.isFinite(Number(y))?{x:Number(x),y:Number(y)}:null;
}
async function decodeBarcodeAnchor(file,baseInfo){
  if(!window.ZXing?.BrowserMultiFormatReader)return {raw:'',rect:null};
  const url=URL.createObjectURL(file);
  try{
    const reader=new ZXing.BrowserMultiFormatReader();
    const result=await reader.decodeFromImageUrl(url);
    const raw=result?.getText?.() ?? result?.text ?? String(result||'');
    const rawPoints=result?.getResultPoints?.() ?? result?.resultPoints ?? [];
    const points=[...rawPoints].map(zxingPointXY).filter(Boolean);
    if(points.length<2)return {raw:String(raw||''),rect:null};
    const xs=points.map(p=>p.x),ys=points.map(p=>p.y);
    const scaleX=baseInfo.canvas.width/Math.max(1,baseInfo.sourceWidth);
    const scaleY=baseInfo.canvas.height/Math.max(1,baseInfo.sourceHeight);
    const minX=Math.min(...xs)*scaleX,maxX=Math.max(...xs)*scaleX;
    const minY=Math.min(...ys)*scaleY,maxY=Math.max(...ys)*scaleY;
    const bw=Math.max(30,maxX-minX),bh=Math.max(12,maxY-minY);
    const padX=Math.max(24,bw*.18);
    const above=Math.max(110,bh*5.5);
    const below=Math.max(28,bh*.7);
    const left=Math.max(0,minX-padX);
    const right=Math.min(baseInfo.canvas.width,maxX+padX);
    const top=Math.max(0,minY-above);
    const bottom=Math.min(baseInfo.canvas.height,maxY+below);
    if(right-left<80 || bottom-top<60)return {raw:String(raw||''),rect:null};
    return {raw:String(raw||''),rect:{left,top,width:right-left,height:bottom-top}};
  }catch(err){
    return {raw:'',rect:null};
  }finally{
    URL.revokeObjectURL(url);
  }
}
function preparePairOcrCanvas(source,rect=null){
  const r=rect||{left:0,top:0,width:source.width,height:source.height};
  const targetWidth=Math.min(1800,Math.max(1300,Math.round(r.width*3.2)));
  const scale=targetWidth/Math.max(1,r.width);
  const out=document.createElement('canvas');
  out.width=Math.max(1,Math.round(r.width*scale));
  out.height=Math.max(1,Math.round(r.height*scale));
  const ctx=out.getContext('2d',{willReadFrequently:true});
  ctx.imageSmoothingEnabled=true;
  ctx.imageSmoothingQuality='high';
  ctx.drawImage(source,r.left,r.top,r.width,r.height,0,0,out.width,out.height);
  const image=ctx.getImageData(0,0,out.width,out.height);
  const d=image.data;
  for(let i=0;i<d.length;i+=4){
    const gray=.299*d[i]+.587*d[i+1]+.114*d[i+2];
    let v=(gray-128)*1.9+128;
    v=v<0?0:v>255?255:v;
    d[i]=d[i+1]=d[i+2]=v;
  }
  ctx.putImageData(image,0,0);
  return out;
}
function mergeImeiEvidence(target,evidence){
  for(const slot of [1,2]){
    if(!target.labelled[slot] && evidence?.labelled?.[slot])target.labelled[slot]=evidence.labelled[slot];
    if(evidence?.markers?.[slot])target.markers[slot]=true;
  }
  for(const value of (evidence?.ordered||[])){
    if(!target.ordered.includes(value))target.ordered.push(value);
  }
}
function pairFromEvidence(evidence,{anchored=false,barcodeRaw=''}={}){
  let imei1=evidence.labelled?.[1]||'';
  let imei2=evidence.labelled?.[2]||'';
  const ordered=[...(evidence.ordered||[])];
  if(anchored && ordered.length>=2){
    if(!imei1)imei1=ordered[0];
    if(!imei2)imei2=ordered.find(v=>v!==imei1)||ordered[1]||'';
  }
  const raw=String(barcodeRaw||'').replace(/\D/g,'');
  if(/^\d{15}$/.test(raw) && validImeiChecksum(raw)){
    if(imei1===raw || imei2===raw){/* already mapped */}
    else if(imei1 && !imei2 && evidence.markers?.[2])imei2=raw;
    else if(imei2 && !imei1 && evidence.markers?.[1])imei1=raw;
  }
  if(imei1===imei2)imei2='';
  return {1:imei1,2:imei2};
}
function rectKey(rect){
  return [rect.left,rect.top,rect.width,rect.height].map(v=>Math.round(v/12)).join(':');
}
function clampRect(rect,w,h){
  const left=Math.max(0,Math.min(w-1,rect.left));
  const top=Math.max(0,Math.min(h-1,rect.top));
  const right=Math.max(left+1,Math.min(w,rect.left+rect.width));
  const bottom=Math.max(top+1,Math.min(h,rect.top+rect.height));
  return {left,top,width:right-left,height:bottom-top};
}
function makeDecodeTile(source,rect,{binary=false,contrast=false}={}){
  const r=clampRect(rect,source.width,source.height);
  // Barcode lines must stay crisp. Smoothing blurred narrow CODE128 bars on
  // sealed-box photos, so barcode tiles are always scaled with nearest-neighbor.
  const targetWidth=1600;
  const scale=Math.max(1,Math.min(3.0,targetWidth/Math.max(1,r.width)));
  const tile=document.createElement('canvas');
  tile.width=Math.max(1,Math.round(r.width*scale));
  tile.height=Math.max(1,Math.round(r.height*scale));
  const ctx=tile.getContext('2d',{willReadFrequently:true});
  ctx.imageSmoothingEnabled=false;
  ctx.drawImage(source,r.left,r.top,r.width,r.height,0,0,tile.width,tile.height);
  if(binary || contrast){
    const image=ctx.getImageData(0,0,tile.width,tile.height);
    const d=image.data;
    // A light contrast boost preserves quiet zones better than heavy OCR-style
    // processing. The binary variant is only a second decode attempt.
    for(let i=0;i<d.length;i+=4){
      const gray=.299*d[i]+.587*d[i+1]+.114*d[i+2];
      let v=contrast?((gray-128)*1.55+128):gray;
      if(binary)v=v<150?0:255;
      v=Math.max(0,Math.min(255,v));
      d[i]=d[i+1]=d[i+2]=v;
    }
    ctx.putImageData(image,0,0);
  }
  return {canvas:tile,rect:r,scale};
}
function scoreBarcodeRect(canvas,rect){
  const r=clampRect(rect,canvas.width,canvas.height);
  const sw=180,sh=56;
  const tmp=document.createElement('canvas');tmp.width=sw;tmp.height=sh;
  const ctx=tmp.getContext('2d',{willReadFrequently:true});
  ctx.imageSmoothingEnabled=true;
  ctx.drawImage(canvas,r.left,r.top,r.width,r.height,0,0,sw,sh);
  const d=ctx.getImageData(0,0,sw,sh).data;
  let transitions=0,dark=0,rowsWithBars=0;
  for(let y=0;y<sh;y++){
    let rowTransitions=0;
    let prev=null;
    for(let x=0;x<sw;x++){
      const i=(y*sw+x)*4;
      const g=.299*d[i]+.587*d[i+1]+.114*d[i+2];
      if(g<105)dark++;
      if(prev!==null && Math.abs(g-prev)>55)rowTransitions++;
      prev=g;
    }
    transitions+=rowTransitions;
    if(rowTransitions>=18)rowsWithBars++;
  }
  const transitionDensity=transitions/(sw*sh);
  const barRowRatio=rowsWithBars/sh;
  const darkRatio=dark/(sw*sh);
  // Text has some transitions, but long barcode rows sustain them across many y rows.
  return transitionDensity*8 + barRowRatio*3 + Math.min(.35,darkRatio)*.8;
}
function buildBarcodeLineRects(canvas){
  const w=canvas.width,h=canvas.height;
  const candidates=[];
  const push=rect=>{
    const r=clampRect(rect,w,h);
    if(r.width<120||r.height<36)return;
    candidates.push({...r,score:scoreBarcodeRect(canvas,r)});
  };
  // Scan narrow horizontal bands across several column widths. This isolates
  // CODE128 IMEI rows from EAN/QR codes and from repeated tear-off stickers.
  const columnSpecs=[
    [0,1],[0,.68],[0,.56],[.34,.66],[.48,.52]
  ];
  for(const hf of [.07,.09,.12,.15]){
    const rh=Math.max(44,Math.round(h*hf));
    const step=Math.max(22,Math.round(rh*.42));
    for(let top=0;top<=h-rh;top+=step){
      for(const [xf,wf] of columnSpecs)push({left:w*xf,top,width:w*wf,height:rh});
    }
  }
  const likely=likelyImeiRect(canvas);
  if(likely){
    const rowH=Math.max(42,likely.height*.20);
    for(let y=likely.top;y<=likely.top+likely.height-rowH;y+=rowH*.36){
      push({left:likely.left,top:y,width:likely.width,height:rowH});
    }
  }
  const seen=new Set();
  return candidates.sort((a,b)=>b.score-a.score).filter(r=>{
    const key=rectKey(r);if(seen.has(key))return false;seen.add(key);return true;
  }).slice(0,36);
}
function buildBarcodeScanRects(canvas){
  const w=canvas.width,h=canvas.height;
  const candidates=[];
  const push=rect=>{
    const r=clampRect(rect,w,h);
    if(r.width<100||r.height<55)return;
    candidates.push({...r,score:scoreBarcodeRect(canvas,r)});
  };
  const specs=[
    [.62,.32,[0,.38,1],[0,.25,.5,.75,1]],
    [.48,.24,[0,.26,.52,1],[0,.25,.5,.75,1]]
  ];
  for(const [wf,hf,xp,yp] of specs){
    const rw=Math.round(w*wf),rh=Math.round(h*hf);
    const maxX=Math.max(0,w-rw),maxY=Math.max(0,h-rh);
    for(const xv of xp)for(const yv of yp)push({left:maxX*xv,top:maxY*yv,width:rw,height:rh});
  }
  const likely=likelyImeiRect(canvas);if(likely)push(likely);
  const seen=new Set();
  return candidates.sort((a,b)=>b.score-a.score).filter(r=>{
    const key=rectKey(r);if(seen.has(key))return false;seen.add(key);return true;
  }).slice(0,16);
}
function median(values=[]){
  if(!values.length)return 0;
  const sorted=[...values].sort((a,b)=>a-b);
  const mid=Math.floor(sorted.length/2);
  return sorted.length%2?sorted[mid]:(sorted[mid-1]+sorted[mid])/2;
}
function imeiFromBarcodeRaw(raw=''){
  const digits=String(raw||'').replace(/\D/g,'');
  return /^\d{15}$/.test(digits) && validImeiChecksum(digits)?digits:'';
}
function makeImeiBarcodeReader(){
  if(!window.ZXing?.BrowserMultiFormatReader)return null;
  try{
    const formats=[];
    const B=window.ZXing.BarcodeFormat||{};
    // Exclude EAN/UPC on purpose: retail EAN was repeatedly winning the first
    // decode on Infinix photos even though the IMEI CODE128 bars were present.
    for(const key of ['CODE_128','CODE_39','ITF','QR_CODE','DATA_MATRIX']){
      if(B[key]!==undefined)formats.push(B[key]);
    }
    if(formats.length && window.ZXing.DecodeHintType?.POSSIBLE_FORMATS!==undefined){
      const hints=new Map();
      hints.set(window.ZXing.DecodeHintType.POSSIBLE_FORMATS,formats);
      if(window.ZXing.DecodeHintType.TRY_HARDER!==undefined)hints.set(window.ZXing.DecodeHintType.TRY_HARDER,true);
      return new ZXing.BrowserMultiFormatReader(hints,300);
    }
  }catch(err){}
  return new ZXing.BrowserMultiFormatReader();
}
async function decodeBarcodeFromTile(reader,source,rect){
  const variants=[
    makeDecodeTile(source,rect),
    makeDecodeTile(source,rect,{contrast:true}),
    makeDecodeTile(source,rect,{binary:true})
  ];
  for(const tile of variants){
    try{
      let result=null;
      if(typeof reader.decodeFromCanvas==='function')result=await reader.decodeFromCanvas(tile.canvas);
      else result=await reader.decodeFromImageUrl(tile.canvas.toDataURL('image/png'));
      const raw=result?.getText?.() ?? result?.text ?? String(result||'');
      const imei=imeiFromBarcodeRaw(raw);
      if(!imei)continue;
      const points=[...(result?.getResultPoints?.() ?? result?.resultPoints ?? [])].map(zxingPointXY).filter(Boolean);
      let localX=tile.canvas.width/2,localY=tile.canvas.height/2;
      if(points.length){
        localX=points.reduce((sum,p)=>sum+p.x,0)/points.length;
        localY=points.reduce((sum,p)=>sum+p.y,0)/points.length;
      }
      return {
        raw:String(raw||''),imei,
        x:tile.rect.left+(localX/Math.max(1,tile.canvas.width))*tile.rect.width,
        y:tile.rect.top+(localY/Math.max(1,tile.canvas.height))*tile.rect.height,
        rect:tile.rect
      };
    }catch(err){}
  }
  return null;
}
async function scanImeiBarcodesFast(canvas){
  return await MalbcoffImeiReader.read(canvas);
}
function buildOcrCandidateRects(canvas,barcodeInfo){
  const w=canvas.width,h=canvas.height;
  const out=[];
  const push=rect=>{
    const r=clampRect(rect,w,h);
    if(r.width<100||r.height<60)return;
    const key=rectKey(r);
    if(out.some(x=>rectKey(x)===key))return;
    out.push(r);
  };
  // If valid IMEI barcodes were found, OCR around their cluster first so the
  // printed IMEI1:/IMEI2: labels stay close to their numbers.
  const validHits=barcodeInfo?.hits||[];
  if(validHits.length){
    const xs=validHits.map(h=>h.x),ys=validHits.map(h=>h.y);
    const cx=(Math.min(...xs)+Math.max(...xs))/2;
    const cy=(Math.min(...ys)+Math.max(...ys))/2;
    const rw=Math.min(w,Math.max(w*.55,(Math.max(...xs)-Math.min(...xs))+w*.22));
    const rh=Math.min(h,Math.max(h*.30,(Math.max(...ys)-Math.min(...ys))+h*.20));
    push({left:cx-rw/2,top:cy-rh*.58,width:rw,height:rh});
  }
  const candidates=buildBarcodeScanRects(canvas).slice(0,4);
  for(const c of candidates)push(c);
  return out.slice(0,5);
}
function collectPairConsensus(pairVotes,pair){
  if(!pair?.[1]||!pair?.[2]||pair[1]===pair[2])return;
  const key=pair[1]+'|'+pair[2];
  pairVotes.set(key,(pairVotes.get(key)||0)+1);
}
function bestPairVote(pairVotes){
  if(!pairVotes.size)return null;
  const ranked=[...pairVotes.entries()].sort((a,b)=>b[1]-a[1]);
  const [key,count]=ranked[0];
  if(ranked.length>1 && ranked[1][1]===count)return {ambiguous:true,pair:null,count};
  const [a,b]=key.split('|');
  return {ambiguous:false,pair:{1:a,2:b},count};
}
async function recognizeImeiPairFast(file){
  const base=await fileToPairBaseCanvas(file);

  // FAST PATH — decode multiple barcode regions first. This is especially
  // effective for sealed boxes that repeat the same IMEI pair on tear-off
  // stickers. No OCR is needed when exactly two valid IMEI barcodes agree.
  const barcodeInfo=await scanImeiBarcodesFast(base.canvas);
  if(barcodeInfo.ambiguous){
    return {pair:{1:'',2:''},evidence:{labelled:{1:'',2:''},ordered:[],markers:{1:false,2:false}},ambiguous:true,reason:'multiple-imeis',barcodeInfo};
  }
  if(barcodeInfo.pair?.[1] && barcodeInfo.pair?.[2]){
    return {pair:barcodeInfo.pair,evidence:{labelled:{1:'',2:''},ordered:barcodeInfo.unique,markers:{1:false,2:false}},anchored:true,source:'multi-barcode',barcodeInfo};
  }

  // OCR FALLBACK — used for packages such as OPPO where the visible 1D barcode
  // may not encode both IMEIs and the printed IMEI1:/IMEI2: text is authoritative.
  const worker=await getCameraOcrWorker();
  const merged={labelled:{1:'',2:''},ordered:[],markers:{1:false,2:false}};
  const pairVotes=new Map();
  const run=async(rect,psm,label)=>{
    cameraState.textContent=label;
    const focused=preparePairOcrCanvas(base.canvas,rect);
    await worker.setParameters({tessedit_pageseg_mode:String(psm),preserve_interword_spaces:'1'});
    const result=await worker.recognize(focused);
    const text=result?.data?.text||'';
    const evidence=imeiEvidenceFromText(text);
    mergeImeiEvidence(merged,evidence);
    const rowPair=pairFromImeiRows(text);
    if(rowPair.ambiguous) evidence.ambiguousRows=true;
    collectPairConsensus(pairVotes,rowPair.pair||{1:evidence.labelled?.[1]||'',2:evidence.labelled?.[2]||''});
    evidence.localPair=rowPair.pair;
    return evidence;
  };

  const rects=buildOcrCandidateRects(base.canvas,barcodeInfo);
  for(let i=0;i<rects.length;i++){
    const evidence=await run(rects[i],6,i===0?'Reading IMEI label…':'Checking repeated IMEI label…');
    if(evidence.localPair || (evidence.labelled?.[1] && evidence.labelled?.[2])){
      const best=bestPairVote(pairVotes);
      if(best?.pair && best.count>=1)return {pair:best.pair,evidence:merged,anchored:true,source:'label-ocr',barcodeInfo};
    }
  }

  // One sparse-text retry on the best candidate only. Avoid multiple heavy OCR
  // passes over the same photo, which caused the previous long render time.
  if(rects[0]){
    await run(rects[0],11,'Checking IMEI text…');
    const best=bestPairVote(pairVotes);
    if(best?.ambiguous)return {pair:{1:'',2:''},evidence:merged,ambiguous:true,reason:'conflicting-labels',barcodeInfo};
    if(best?.pair)return {pair:best.pair,evidence:merged,anchored:true,source:'label-ocr',barcodeInfo};
  }

  // Last resort: one resized full-image pass. Never blindly map more than two
  // different valid IMEIs from a crowded photo.
  const full=preparePairOcrCanvas(base.canvas,null);
  cameraState.textContent='Final IMEI check…';
  await worker.setParameters({tessedit_pageseg_mode:'6',preserve_interword_spaces:'1'});
  const result=await worker.recognize(full);
  const fullText=result?.data?.text||'';
  const evidence=imeiEvidenceFromText(fullText);
  mergeImeiEvidence(merged,evidence);
  const fullRowPair=pairFromImeiRows(fullText);
  collectPairConsensus(pairVotes,fullRowPair.pair||{1:evidence.labelled?.[1]||'',2:evidence.labelled?.[2]||''});
  const best=bestPairVote(pairVotes);
  if(best?.ambiguous)return {pair:{1:'',2:''},evidence:merged,ambiguous:true,reason:'conflicting-labels',barcodeInfo};
  if(best?.pair)return {pair:best.pair,evidence:merged,anchored:false,source:'label-ocr',barcodeInfo};

  // Safe partial fallback only when exactly two ordered IMEIs were found in the
  // same OCR evidence and no barcode ambiguity exists.
  const pair=pairFromEvidence(merged,{anchored:false,barcodeRaw:''});
  return {pair,evidence:merged,anchored:false,source:'fallback',barcodeInfo};
}
async function decodeImeiPairPhoto(file){
  return decodeLocalPhoto(file);
}
function updateGuidedImeiUi(note=''){
  const slot=guidedImeiStep===2?2:1;
  $('cameraScanTitle').textContent='Scan Device IMEIs';
  $('cameraScanSubtitle').textContent=slot===1
    ? 'Show one upright label. Review both IMEIs together, or read IMEI 1 first.'
    : 'Step 2 of 2 · Read the other IMEI, review it, then tap Use IMEI 2.';
  cameraState.textContent=note || `Waiting for IMEI ${slot}`;
  if(cameraRetryBtn)cameraRetryBtn.textContent='Take Photo';
  if(cameraGalleryBtn)cameraGalleryBtn.textContent=cameraCapturedFile?'Use Different Photo':'Use Existing Photo';
  cameraGalleryBtn?.classList.remove('hidden');
  cameraSkipSecondaryBtn?.classList.toggle('hidden',slot!==2);
}
async function startGuidedCameraStep(){
  if(!guidedImeiRow || !cameraTargetInput)return;
  cameraStop();
  clearCapturedPhoto();
  updateGuidedImeiUi();
  cameraMessage.classList.add('hidden');
  if(!window.isSecureContext || !navigator.mediaDevices?.getUserMedia){
    cameraShowMessage('Take one sharp photo showing both IMEI barcodes. We will read the label after you use the photo.',false,'Phone camera / photo mode');
    cameraPhotoInput.value='';
    cameraPhotoInput.click();
    return;
  }
  const session=cameraSession;
  try{
    const stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'},width:{ideal:1920},height:{ideal:1080}},audio:false});
    if(session!==cameraSession || cameraModal.hidden){stream.getTracks().forEach(track=>track.stop());return;}
    cameraStream=stream;
    cameraVideo.srcObject=stream;
    await cameraVideo.play();
    if(session!==cameraSession)return;
    cameraState.textContent='Point at both IMEI barcodes';
    cameraRetryBtn.textContent='Take Photo';
    cameraLastFrame=0;
    cameraFrameHandle=requestAnimationFrame(cameraDetectLoop);
  }catch(err){
    if(session!==cameraSession)return;
    cameraStop();
    cameraShowMessage('Live camera could not start. Allow camera permission, or tap Take Photo / Use Existing Photo.',true,'Camera unavailable');
    cameraRetryBtn.textContent='Take Photo';
  }
}
function startGuidedImeiScanner(button){
  const row=button.closest('.identifier-entry-dual');
  if(!row)return;
  if(identifierInputKind(row.querySelector('[data-identifier-primary]'))==='barcode'){
    startCameraScanner(row.querySelector('[data-identifier-primary]'));return;
  }
  cameraStop();
  clearCapturedPhoto();
  cameraModal.querySelector('.camera-scan-tips').textContent='Keep one phone box label clear and sharp. Repeated copies of the same IMEI pair are accepted.';
  cameraPairRow=null;
  guidedImeiRow=row;
  const primary=row.querySelector('[data-identifier-primary]');
  const secondary=row.querySelector('[data-identifier-secondary]');
  const primaryReady=/^\d{15}$/.test(primary?.value||'') && validImeiChecksum(primary.value);
  guidedImeiStep=primaryReady && !(secondary?.value||'').trim()?2:1;
  cameraTargetInput=guidedImeiStep===2?secondary:primary;
  configureCameraType('imei');
  cameraMessage.classList.add('hidden');
  cameraMessage.classList.remove('is-error');
  cameraModal.hidden=false;
  document.body.classList.add('modal-open');
  updateGuidedImeiUi();
  // Camera-first flow. On local HTTP, startGuidedCameraStep() uses the
  // capture=environment file input so Android/iOS opens the rear camera.
  // Gallery stays available only as a fallback via "Use Existing Photo".
  cameraState.textContent=`Opening camera for IMEI ${guidedImeiStep}…`;
  cameraShowMessage(guidedImeiStep===1
    ? 'Camera will open for IMEI 1. After it is accepted, the same scanner continues to IMEI 2.'
    : 'Camera will open for IMEI 2.',false,`Step ${guidedImeiStep} of 2`);
  if(cameraRetryBtn)cameraRetryBtn.textContent=`Open Camera for IMEI ${guidedImeiStep}`;
  cameraGalleryBtn?.classList.remove('hidden');
  startGuidedCameraStep();
}

function startImeiPairScanner(button){
  const row=button.closest('.identifier-entry-dual');
  if(!row)return;
  cameraStop();
  clearCapturedPhoto();
  resetGuidedImeiScanner();
  cameraPairRow=row;
  cameraTargetInput=row.querySelector('[data-identifier-primary]');
  configureCameraType('imei');
  $('cameraScanTitle').textContent='Scan IMEI Label';
  $('cameraScanSubtitle').textContent='Review both IMEIs against the label before using them.';
  cameraState.textContent='Preparing IMEI reader…';
  cameraMessage.classList.add('hidden');
  cameraMessage.classList.remove('is-error');
  cameraModal.hidden=false;
  document.body.classList.add('modal-open');
  if(cameraRetryBtn)cameraRetryBtn.textContent='Open Camera';
  cameraGalleryBtn?.classList.remove('hidden');
  cameraPhotoInput?.click();
}
async function fileToOcrCanvas(file){
  const bitmap=await createImageBitmap(file);
  const maxSide=1600;
  const scale=Math.min(1,maxSide/Math.max(bitmap.width,bitmap.height));
  const canvas=document.createElement('canvas');
  canvas.width=Math.max(1,Math.round(bitmap.width*scale));
  canvas.height=Math.max(1,Math.round(bitmap.height*scale));
  const ctx=canvas.getContext('2d',{willReadFrequently:true});
  ctx.drawImage(bitmap,0,0,canvas.width,canvas.height);
  bitmap.close?.();

  const image=ctx.getImageData(0,0,canvas.width,canvas.height);
  const d=image.data;
  for(let i=0;i<d.length;i+=4){
    const gray=.299*d[i]+.587*d[i+1]+.114*d[i+2];
    const contrasted=Math.max(0,Math.min(255,(gray-128)*1.55+128));
    d[i]=d[i+1]=d[i+2]=contrasted;
  }
  ctx.putImageData(image,0,0);
  return canvas;
}
function scoreOcrRect(canvas,rect){
  const sampleW=90,sampleH=60;
  const tmp=document.createElement('canvas');
  tmp.width=sampleW;tmp.height=sampleH;
  const t=tmp.getContext('2d',{willReadFrequently:true});
  t.drawImage(canvas,rect.left,rect.top,rect.width,rect.height,0,0,sampleW,sampleH);
  const data=t.getImageData(0,0,sampleW,sampleH).data;
  let dark=0,light=0,transitions=0,total=0;
  for(let y=0;y<sampleH;y++){
    let prev=null;
    for(let x=0;x<sampleW;x++){
      const i=(y*sampleW+x)*4;
      const g=data[i];
      if(g<95)dark++;
      if(g>185)light++;
      if(prev!==null && Math.abs(g-prev)>75)transitions++;
      prev=g; total++;
    }
  }
  const mix=(dark/total)*(light/total);
  return mix*5+(transitions/(sampleW*sampleH))*1.4;
}
function likelyImeiRect(canvas){
  const w=canvas.width,h=canvas.height;
  const rw=Math.round(w*.68),rh=Math.round(h*.44);
  let best={left:0,top:0,width:w,height:h,score:-1};
  const maxX=Math.max(0,w-rw),maxY=Math.max(0,h-rh);
  const xs=[0,.5,1].map(v=>Math.round(maxX*v));
  const ys=[0,.33,.66,1].map(v=>Math.round(maxY*v));
  for(const left of xs){
    for(const top of ys){
      const rect={left,top,width:rw,height:rh};
      const score=scoreOcrRect(canvas,rect);
      if(score>best.score)best={...rect,score};
    }
  }
  return best;
}
function cropCanvasForOcr(canvas,rect){
  const minWidth=1200;
  const scale=Math.max(1,Math.min(3,minWidth/Math.max(1,rect.width)));
  const out=document.createElement('canvas');
  out.width=Math.max(1,Math.round(rect.width*scale));
  out.height=Math.max(1,Math.round(rect.height*scale));
  const ctx=out.getContext('2d',{willReadFrequently:true});
  ctx.imageSmoothingEnabled=true;
  ctx.imageSmoothingQuality='high';
  ctx.drawImage(canvas,rect.left,rect.top,rect.width,rect.height,0,0,out.width,out.height);
  return out;
}
async function recognizeImeiText(worker,canvas,slot){
  const primary=likelyImeiRect(canvas);
  const focused=cropCanvasForOcr(canvas,primary);
  const merged={labelled:{1:'',2:''},ordered:[],markers:{1:false,2:false}};
  const mergeEvidence=evidence=>{
    for(const s of [1,2]){
      if(!merged.labelled[s] && evidence?.labelled?.[s])merged.labelled[s]=evidence.labelled[s];
      if(evidence?.markers?.[s])merged.markers[s]=true;
    }
    for(const imei of (evidence?.ordered||[])){
      if(!merged.ordered.includes(imei))merged.ordered.push(imei);
    }
  };
  const runPass=async(source,psm,label)=>{
    cameraState.textContent=label;
    await worker.setParameters({
      tessedit_pageseg_mode:String(psm),
      preserve_interword_spaces:'1'
    });
    const result=await worker.recognize(source);
    const evidence=imeiEvidenceFromText(result?.data?.text||'');
    mergeEvidence(evidence);
    return evidence;
  };

  // Pass 1: treat the cropped label as one text block. This is especially good
  // at keeping "IMEI1:" attached to the first number instead of drifting to IMEI2.
  let evidence=await runPass(focused,6,`Reading IMEI ${slot} label…`);
  if(evidence.labelled?.[slot]){
    return {value:evidence.labelled[slot],labelled:merged.labelled,ordered:merged.ordered,markers:merged.markers};
  }

  // Pass 2: sparse text recovers labels/numbers that the block pass missed.
  // We still only accept a value when it is tied to the requested IMEI slot.
  evidence=await runPass(focused,11,`Checking IMEI ${slot} line…`);
  if(evidence.labelled?.[slot]){
    return {value:evidence.labelled[slot],labelled:merged.labelled,ordered:merged.ordered,markers:merged.markers};
  }

  // Last resort: run the same two modes on the resized full image. Do not map
  // IMEI1/IMEI2 from raw number order because OCR reading order can change.
  evidence=await runPass(canvas,6,'Checking full label…');
  if(evidence.labelled?.[slot]){
    return {value:evidence.labelled[slot],labelled:merged.labelled,ordered:merged.ordered,markers:merged.markers};
  }
  evidence=await runPass(canvas,11,'Checking remaining text…');
  const value=evidence.labelled?.[slot] || merged.labelled?.[slot] || '';
  return {value,labelled:merged.labelled,ordered:merged.ordered,markers:merged.markers};
}
async function decodePrintedImei(file){
  if(identifierKind()!=='imei' || !cameraTargetInput)return {accepted:false,labelled:{1:'',2:''},ordered:[],markers:{1:false,2:false}};
  try{
    const worker=await getCameraOcrWorker();
    const slot=cameraTargetInput.dataset.identifierSecondary==='1'?2:1;
    const canvas=await fileToOcrCanvas(file);
    const result=await recognizeImeiText(worker,canvas,slot);
    if(result.value){
      return {
        accepted:await cameraAcceptValue(result.value),
        labelled:result.labelled,
        ordered:result.ordered||[],
        markers:result.markers||{1:false,2:false}
      };
    }
    return {
      accepted:false,
      labelled:result.labelled,
      ordered:result.ordered||[],
      markers:result.markers||{1:false,2:false}
    };
  }catch(err){
    return {accepted:false,labelled:{1:'',2:''},ordered:[],markers:{1:false,2:false}};
  }
}
function cropSingleImeiWorkingArea(canvas){
  // Guided mode asks the user to frame ONE IMEI row. Trim quiet margins first so
  // Tesseract spends its time on the printed number instead of the whole photo.
  const ctx=canvas.getContext('2d',{willReadFrequently:true});
  const w=canvas.width,h=canvas.height;
  if(w<20||h<20)return canvas;
  const data=ctx.getImageData(0,0,w,h).data;
  let minX=w,minY=h,maxX=-1,maxY=-1;
  const step=Math.max(1,Math.floor(Math.max(w,h)/900));
  for(let y=0;y<h;y+=step){
    for(let x=0;x<w;x+=step){
      const i=(y*w+x)*4;
      const lum=(data[i]*0.299)+(data[i+1]*0.587)+(data[i+2]*0.114);
      if(lum<185){
        if(x<minX)minX=x;if(x>maxX)maxX=x;
        if(y<minY)minY=y;if(y>maxY)maxY=y;
      }
    }
  }
  if(maxX<=minX||maxY<=minY)return canvas;
  const bw=maxX-minX+1,bh=maxY-minY+1;
  // If dark content covers almost the whole image, auto-cropping is not useful.
  if((bw*bh)/(w*h)>0.88)return canvas;
  const padX=Math.round(bw*0.08),padY=Math.round(bh*0.18);
  const left=Math.max(0,minX-padX),top=Math.max(0,minY-padY);
  const right=Math.min(w,maxX+padX),bottom=Math.min(h,maxY+padY);
  const out=document.createElement('canvas');
  out.width=Math.max(1,right-left);out.height=Math.max(1,bottom-top);
  out.getContext('2d',{willReadFrequently:true}).drawImage(canvas,left,top,out.width,out.height,0,0,out.width,out.height);
  return out;
}
function prepareSingleImeiOcrCanvas(source){
  const cropped=cropSingleImeiWorkingArea(source);
  const targetWidth=Math.max(1000,Math.min(1400,cropped.width*1.5));
  const scale=targetWidth/Math.max(1,cropped.width);
  const out=document.createElement('canvas');
  out.width=Math.max(1,Math.round(cropped.width*scale));
  out.height=Math.max(1,Math.round(cropped.height*scale));
  const ctx=out.getContext('2d',{willReadFrequently:true});
  ctx.imageSmoothingEnabled=true;
  ctx.imageSmoothingQuality='high';
  ctx.drawImage(cropped,0,0,out.width,out.height);
  const image=ctx.getImageData(0,0,out.width,out.height);
  const d=image.data;
  // Light grayscale + contrast only. Heavy thresholding destroyed thin digits on
  // several Infinix labels, so keep anti-aliased edges intact for OCR.
  for(let i=0;i<d.length;i+=4){
    let g=(d[i]*0.299)+(d[i+1]*0.587)+(d[i+2]*0.114);
    g=(g-128)*1.45+128;
    g=Math.max(0,Math.min(255,g));
    d[i]=d[i+1]=d[i+2]=g;
  }
  ctx.putImageData(image,0,0);
  return out;
}
function guidedImeiCandidatesFromText(text=''){
  const values=validImeisFromText(text);
  if(values.length)return values;
  // Guided scan is already slot-bound, so a clean 15-character OCR run is safe
  // to normalize even when the word IMEI itself was missed by OCR.
  const cleaned=cleanOcrText(text);
  const candidates=[];
  const fragments=cleaned.match(/[0-9OQILZSGB| ._\-]{15,34}/g)||[];
  for(const fragment of fragments){
    const normalized=normalizeOcrDigits(fragment).replace(/\D/g,'');
    for(let i=0;i+15<=normalized.length;i++){
      const value=normalized.slice(i,i+15);
      if(validImeiChecksum(value)&&!candidates.includes(value))candidates.push(value);
    }
  }
  return candidates;
}
async function decodeFastSingleImeiRow(file,slot=1){
  if(!file || identifierKind()!=='imei')return {value:'',unique:[],ambiguous:false,source:''};
  try{
    const worker=await getCameraOcrWorker();
    const base=await fileToPairBaseCanvas(file,1800);
    const working=prepareSingleImeiOcrCanvas(base.canvas);
    const recognize=async(psm,label)=>{
      cameraState.textContent=label;
      await worker.setParameters({
        tessedit_char_whitelist:'IME0123456789OQILZSGB|: #-._',
        tessedit_pageseg_mode:String(psm),
        preserve_interword_spaces:'1',
        user_defined_dpi:'250'
      });
      const result=await worker.recognize(working);
      return guidedImeiCandidatesFromText(result?.data?.text||'');
    };

    // Fast path: one framed row behaves like a single text line.
    let unique=await recognize(7,`Reading printed IMEI ${slot}…`);
    if(unique.length===1)return {value:unique[0],unique,ambiguous:false,source:'ocr-line'};
    if(unique.length>1)return {value:'',unique,ambiguous:true,source:'ocr-line'};

    // One fallback only. This remains much faster than the old multi-region OCR
    // pipeline while recovering photos where IMEI: and the digits wrap slightly.
    unique=await recognize(6,`Checking IMEI ${slot} row…`);
    if(unique.length===1)return {value:unique[0],unique,ambiguous:false,source:'ocr-block'};
    if(unique.length>1)return {value:'',unique,ambiguous:true,source:'ocr-block'};

    // Last, cheap barcode attempt on the already tightly-framed photo. No tile
    // scanning and no multi-label consensus work in guided mode.
    const url=URL.createObjectURL(file);
    try{
      if(window.ZXing?.BrowserMultiFormatReader){
        try{
          const reader=makeImeiBarcodeReader() || new ZXing.BrowserMultiFormatReader();
          const result=await reader.decodeFromImageUrl(url);
          const raw=result?.getText?.() ?? result?.text ?? String(result||'');
          const value=imeiFromBarcodeRaw(raw);
          if(value)return {value,unique:[value],ambiguous:false,source:'barcode-fallback'};
        }catch(err){}
      }
    }finally{URL.revokeObjectURL(url);}
  }catch(err){}
  return {value:'',unique:[],ambiguous:false,source:''};
}
async function decodeSingleImeiBarcodePhoto(file,slot=1){
  if(!file || identifierKind()!=='imei')return {value:'',unique:[],ambiguous:false,pair:null};
  try{
    const base=await fileToPairBaseCanvas(file,2600);
    const info=await scanImeiBarcodesFast(base.canvas,{maxUnique:3});
    const unique=[...(info?.unique||[])];
    if(info?.pair?.[1] && info?.pair?.[2]){
      return {value:info.pair[slot]||'',unique,ambiguous:false,pair:info.pair,source:'pair-bars'};
    }
    if(unique.length===1){
      // Guided mode is already slot-bound by the button/step the user selected.
      return {value:unique[0],unique,ambiguous:false,pair:null,source:'single-bar'};
    }
    if(unique.length>2 || info?.ambiguous)return {value:'',unique,ambiguous:true,pair:null,source:'bars'};
    if(unique.length===2){
      // Two valid values were found but their positions were too uncertain to
      // assign automatically. Do not guess the slot.
      return {value:'',unique,ambiguous:true,pair:null,source:'bars'};
    }
  }catch(err){}
  return {value:'',unique:[],ambiguous:false,pair:null};
}
// Shared review for serials, single IMEIs and an ordered IMEI pair.
function showSerialReview(value,message,candidates=[],pair=null){
  const imei=cameraKind()==='imei',barcode=cameraKind()==='barcode';
  if(barcode){value=normalizeBarcodeReading(value);candidates=candidates.map(normalizeBarcodeReading).filter(validStockBarcode);}
  const label=pair?'IMEI 1':cameraFieldLabel(cameraTargetInput);
  cameraReviewPair=pair;
  const input=$('cameraSerialValue');
  input.maxLength=imei?15:barcode?120:80;input.value=value;input.inputMode=imei?'numeric':'text';
  input.placeholder='TYPE OR CORRECT '+label.toUpperCase();
  $('cameraReviewLabel').textContent='Check '+label+' against the label';
  $('cameraReviewHelp').textContent=imei
    ? 'An IMEI must contain exactly 15 digits. Check IMEI 1 and IMEI 2 against their printed labels.'
    : barcode?'Use the printed code only, without S/N or MAC labels. Check each character before using it.':'Check each character, especially 0/O, 1/I and 8/B.';
  $('cameraReviewSecondary').classList.toggle('hidden',!pair);
  $('cameraReviewSecondaryValue').value=pair?.value || '';
  $('cameraSerialReview').classList.remove('hidden');
  $('cameraUseSerialBtn').classList.remove('hidden');
  $('cameraUseSerialBtn').textContent=pair?'Use Both IMEIs':'Use '+label;
  const choices=$('cameraSerialCandidates');choices.replaceChildren();
  for(const candidate of [...new Set(candidates)].slice(0,6)){
    if(candidates.length<2)break;
    const button=document.createElement('button');
    button.type='button';button.className='btn btn-outline';button.textContent=candidate;
    button.addEventListener('click',()=>{input.value=candidate;});
    choices.appendChild(button);
  }
  cameraShowMessage(message,false,value?'Check '+label:'Review or select text');
}
function confirmCameraReview(){
  if(cameraModal.hidden)return;
  const target=cameraTargetInput,kind=cameraKind();
  const fail=message=>{cameraShowMessage(message);cameraMessage.scrollIntoView({block:'nearest'});};
  if(!target?.isConnected || !['imei','serial','barcode'].includes(kind)){
    fail('Close the scanner and select the identifier field again.');return;
  }
  const normalize=value=>kind==='barcode'?normalizeBarcodeReading(value):String(value||'').replace(/\s+/g,'').toUpperCase();
  const value=normalize($('cameraSerialValue').value),pair=cameraReviewPair;
  const assignments=pair
    ? [{input:pair.primary,value},{input:pair.secondary,value:normalize($('cameraReviewSecondaryValue').value)}]
    : [{input:target,value}];
  for(const entry of assignments){
    if(!entry.input?.isConnected){fail('The field changed. Close the scanner and open it again.');return;}
    if(kind==='barcode'?!validStockBarcode(entry.value):kind==='imei'?!MalbcoffImeiReader.valid(entry.value):!MalbcoffImeiReader.validSerial(entry.value)){
      fail(kind==='barcode'?'Enter the printed barcode value (up to 120 characters).':kind==='imei'?'Enter a valid 15-digit IMEI matching the label.':'Enter the printed Serial Number, not the IMEI.');return;
    }
  }
  if(pair && assignments[0].value===assignments[1].value){fail('IMEI 1 and IMEI 2 must be different.');return;}
  if(kind==='imei' && !pair){
    const row=target.closest('.identifier-entry-dual');
    const other=row?.querySelector(target.dataset.identifierSecondary==='1'?'[data-identifier-primary]':'[data-identifier-secondary]');
    if(other && normalize(other.value)===value){fail('That IMEI is already in the other field. Select the other printed IMEI.');return;}
  }
  if(pair && guidedImeiStep===2 && normalize(pair.primary.value)!==value){
    fail('The reviewed IMEI 1 does not match the current device. Scan the same phone box.');return;
  }
  if(kind==='barcode' && (!canReadStockBarcode() || target.closest('.identifier-entry')?.querySelector('[data-identifier-secondary]')?.value.trim())){
    fail('Clear IMEI 2 in the form before using a Serial / Barcode for this unit.');return;
  }
  if(target.dataset.identifierPrimary==='1' && !applyIdentifierType(target.closest('.identifier-entry'),kind))return;
  const continuePair=kind==='imei' && !pair && guidedImeiRow && guidedImeiStep===1;
  const nextInput=continuePair?guidedImeiRow.querySelector('[data-identifier-secondary]'):null;
  for(const entry of assignments){
    entry.input.value=entry.value;
    entry.input.dispatchEvent(new Event('input',{bubbles:true}));
  }
  // Validate every populated field, including both sides of a pair. Do not
  // wait for the network before releasing the scanner or advancing its step.
  clearTimeout(identifierTimer);
  if(continuePair && nextInput){
    cameraStop();guidedImeiStep=2;cameraTargetInput=nextInput;configureCameraType('imei');updateGuidedImeiUi('IMEI 1 placed in its field');
    if(cameraCapturedFile){
      $('cameraSelectSerialBtn').classList.remove('hidden');
      showSerialReview('','IMEI 1 is in its field. Tap or select the printed IMEI 2 in this photo, then review and use it.');
    }else{startGuidedCameraStep();}
  }else{
    closeCameraScanner();
  }
  for(const entry of assignments)checkIdentifier(entry.input);
}
$('cameraUseSerialBtn').addEventListener('click',confirmCameraReview);

async function readTappedSerial(point,region=null){
  if(!cameraCapturedFile || !cameraTargetInput)return;
  const session=cameraSession,file=cameraCapturedFile;
  cameraBusy=true;cameraReviewPair=null;
  $('cameraSerialReview').classList.add('hidden');
  $('cameraUseSerialBtn').classList.add('hidden');
  cameraMessage.classList.add('hidden');
  try{
    const base=await fileToPairBaseCanvas(file,4096);
    if(session!==cameraSession)return;
    const result=await readPrintedIdentifier(base.canvas,{
      point,region,cancelled:()=>session!==cameraSession,
      preview:image=>showSerialCrop(image,session),
      progress:text=>{if(session===cameraSession)cameraState.textContent=text;}
    });
    if(session!==cameraSession || cameraModal.hidden)return;
    showSerialReview(result.values.length===1?result.values[0]:'',result.values.length
      ? 'Compare the reading with the printed label. Choose or correct it, then tap Use.'
      : 'Select Text and draw a box around just the printed value. Check the cropped area, or enter the value below.',result.values);
  }catch(err){
    if(session===cameraSession)showSerialReview('',err?.message || 'Reading could not finish. Enter the printed value below.');
  }finally{
    if(session===cameraSession)cameraBusy=false;
  }
}
async function decodeLocalPhoto(file){
  if(!file || !cameraTargetInput)return;
  showCapturedPhoto(file);
  const session=cameraSession,kind=cameraKind();
  cameraBusy=true;cameraState.textContent='Reading '+cameraFieldLabel(cameraTargetInput)+'…';
  cameraMessage.classList.add('hidden');
  try{
    const base=await fileToPairBaseCanvas(file,4096);
    if(session!==cameraSession)return;
    let values=[];
    try{
      if(kind==='imei'){
        const result=await MalbcoffImeiReader.read(base.canvas,{cancelled:()=>session!==cameraSession});
        if(session!==cameraSession || cameraModal.hidden)return;
        if(result.ambiguous){
          showSerialReview('','Several device labels were found. Select only the printed IMEI for this field, or take a closer photo.');return;
        }
        if(result.pair && (guidedImeiRow || cameraPairRow)){
          if(await acceptGuidedPair(result.pair,session))return;
          if(session!==cameraSession)return;
          return;
        }
        values=result.unique;
      }else{
        const result=await MalbcoffImeiReader[kind==='barcode'?'readCodes':'readSerial'](base.canvas,{cancelled:()=>session!==cameraSession});
        values=result.values;
      }
      if(!values.length && kind!=='barcode' && canReadStockBarcode()){
        const result=await MalbcoffImeiReader.readCodes(base.canvas,{cancelled:()=>session!==cameraSession});
        if(session!==cameraSession)return;
        values=result.values.map(normalizeBarcodeReading).filter(validStockBarcode);
        if(values.length)configureCameraType('barcode');
      }
    }catch(err){/* Printed-text reading remains available if the barcode engine fails. */}
    if(session!==cameraSession || cameraModal.hidden)return;
    if(values.length){
      showSerialReview(values.length===1?values[0]:'',values.length===1
        ? 'Barcode read. Check the value and field against the label, then tap Use.'
        : cameraKind()==='barcode'?'Several codes were found. Choose the Serial / Barcode for this unit, then tap Use Barcode.':'Choose the printed value for this field. The scanner will not guess IMEI 1 / IMEI 2 order.',values);return;
    }
    const printed=await readPrintedIdentifier(base.canvas,{
      cancelled:()=>session!==cameraSession,
      preview:image=>showSerialCrop(image,session),
      progress:text=>{if(session===cameraSession)cameraState.textContent=text;}
    });
    if(session!==cameraSession || cameraModal.hidden)return;
    showSerialReview(printed.values.length===1?printed.values[0]:'',printed.values.length
      ? 'Check the printed value and field. Choose or correct the reading, then tap Use.'
      : 'Tap the printed value, or select its text with a box. You can also enter it below.',printed.values);
  }catch(err){
    if(session===cameraSession)showSerialReview('',err?.message || 'The reader could not finish. Enter the printed value or take a closer photo.');
  }finally{
    if(session===cameraSession)cameraBusy=false;
    cameraPhotoInput.value='';cameraGalleryInput.value='';
  }
}
function openLocalPhotoScanner(){
  cameraStop();
  cameraState.textContent='Local camera mode';
  cameraShowMessage(cameraKind()==='barcode'
    ? 'Photograph the barcode or printed S/N for this unit, then choose Use Photo.'
    : cameraKind()==='serial'
    ? 'Take a close-up of the barcode beside Serial Number / S/N, then choose Use Photo. Avoid the IMEI and retail barcode.'
    : 'Take a close-up of one IMEI barcode, then choose Use Photo.',false);
  if(cameraRetryBtn)cameraRetryBtn.textContent='Open Camera';
  cameraGalleryBtn?.classList.remove('hidden');
  cameraPhotoInput?.click();
}

async function startCameraScanner(input){
  cameraStop();
  clearCapturedPhoto();
  resetGuidedImeiScanner();
  cameraPairRow=null;
  cameraTargetInput=input;
  configureCameraType();
  const label=cameraFieldLabel(input);
  cameraState.textContent='Starting camera…';
  cameraMessage.classList.add('hidden');
  cameraMessage.classList.remove('is-error');
  cameraModal.hidden=false;
  document.body.classList.add('modal-open');

  if(!window.isSecureContext || !navigator.mediaDevices?.getUserMedia){
    openLocalPhotoScanner();
    return;
  }
  if(cameraRetryBtn)cameraRetryBtn.textContent='Take Photo';
  cameraGalleryBtn?.classList.remove('hidden');
  const session=cameraSession;
  try{
    // All identifier modes use the bundled reader, including browsers without BarcodeDetector.
    const stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'},width:{ideal:1920},height:{ideal:1080}},audio:false});
    if(session!==cameraSession || cameraModal.hidden){stream.getTracks().forEach(track=>track.stop());return;}
    cameraStream=stream;
    cameraVideo.srcObject=stream;
    await cameraVideo.play();
    if(session!==cameraSession)return;
    cameraState.textContent='Scanning '+label+'…';
    cameraLastFrame=0;
    cameraFrameHandle=requestAnimationFrame(cameraDetectLoop);
  }catch(err){
    if(session!==cameraSession)return;
    cameraStop();
    cameraShowMessage('Camera could not start. Allow camera permission, tap Take Photo, or use an existing photo.');
  }
}
identifierRows.addEventListener('click',e=>{
  const guidedBtn=e.target.closest('[data-guided-imei-scan]');
  if(guidedBtn){
    startGuidedImeiScanner(guidedBtn);
    return;
  }
  const btn=e.target.closest('[data-camera-scan]');
  if(!btn)return;
  const input=btn.closest('.identifier-field-wrap')?.querySelector('[data-identifier-input]');
  if(input)startCameraScanner(input);
});
identifierRows.addEventListener('change',e=>{
  if(!e.target.matches('[data-identifier-type]'))return;
  const row=e.target.closest('.identifier-entry'),input=row.querySelector('[data-identifier-primary]');
  clearTimeout(identifierTimer);
  if(!applyIdentifierType(row,e.target.value))return;
  clearRestoreState(input);setIdentifierState(input,'','');
  checkIdentifier(input);
});
document.querySelectorAll('[data-camera-close]').forEach(b=>b.addEventListener('click',closeCameraScanner));
document.addEventListener('keydown',e=>{if(e.key==='Escape' && !cameraModal.hidden)closeCameraScanner();});
window.addEventListener('pagehide',cameraStop);
function chooseScannerPhoto(input){
  if(!cameraTargetInput || cameraModal.hidden)return;
  cameraStop();
  if(cameraCapturedFile)$('cameraSelectSerialBtn').classList.remove('hidden');
  showSerialReview('','Take or choose a photo, or enter the printed value below.');
  input.value='';input.click();
}
cameraRetryBtn?.addEventListener('click',()=>chooseScannerPhoto(cameraPhotoInput));
cameraSkipSecondaryBtn?.addEventListener('click',()=>{
  if(!guidedImeiRow || guidedImeiStep!==2)return;
  const secondary=guidedImeiRow.querySelector('[data-identifier-secondary]');
  cameraStop();
  clearCapturedPhoto();
  cameraModal.hidden=true;
  document.body.classList.remove('modal-open');
  cameraTargetInput=null;
  resetGuidedImeiScanner();
  if(secondary)focusNextIdentifier(secondary);
});

cameraPhotoInput?.addEventListener('change',()=>{
  const file=cameraPhotoInput.files?.[0];
  if(!file)return;
  decodeLocalPhoto(file);
});
cameraGalleryBtn?.addEventListener('click',()=>chooseScannerPhoto(cameraGalleryInput));
cameraGalleryInput?.addEventListener('change',()=>{
  const file=cameraGalleryInput.files?.[0];
  if(!file)return;
  decodeLocalPhoto(file);
});

$('cameraSelectSerialBtn').addEventListener('click',()=>{
  if(!['imei','serial','barcode'].includes(cameraKind()) || !cameraCapturedFile)return;
  if(serialSelecting){resetSerialSelection();cameraShowMessage('Tap the printed value, or enter it below.',false);return;}
  // Retire the previous read immediately; its late result must not replace a
  // newer selection. The OCR queue finishes only its current bounded operation.
  cameraSession++;cameraBusy=false;
  showSerialReview($('cameraSerialValue').value,'Drag a box around the printed '+cameraFieldLabel(cameraTargetInput)+', or enter it below.');
  serialSelecting=true;
  cameraCapturedPreview.style.touchAction='none';
  $('cameraSelectSerialBtn').textContent='Cancel Selection';
  cameraShowMessage('Drag a box around only the printed '+cameraFieldLabel(cameraTargetInput)+'. Leave barcode bars outside. Release to read that area.',false,'Select printed text');
  $('cameraScanStage').scrollIntoView({block:'nearest'});
});
function drawSerialSelection(start,end){
  const img=cameraCapturedPreview,rect=img.getBoundingClientRect(),stage=$('cameraScanStage').getBoundingClientRect();
  const scale=Math.min(rect.width/img.naturalWidth,rect.height/img.naturalHeight);
  const width=img.naturalWidth*scale,height=img.naturalHeight*scale;
  const region={x:Math.min(start.x,end.x),y:Math.min(start.y,end.y),w:Math.abs(end.x-start.x),h:Math.abs(end.y-start.y)};
  const box=$('cameraSerialSelection');box.hidden=false;
  box.style.left=`${rect.left-stage.left+(rect.width-width)/2+region.x*width}px`;
  box.style.top=`${rect.top-stage.top+(rect.height-height)/2+region.y*height}px`;
  box.style.width=`${region.w*width}px`;box.style.height=`${region.h*height}px`;
  return region;
}
cameraCapturedPreview?.addEventListener('pointerdown',e=>{
  if(!serialSelecting || !e.isPrimary)return;
  const point=previewNormalizedPoint(e);if(!point)return;
  e.preventDefault();serialSelectionStart=point;serialSelectionPointer=e.pointerId;
  cameraCapturedPreview.setPointerCapture(e.pointerId);drawSerialSelection(point,point);
});
cameraCapturedPreview?.addEventListener('pointermove',e=>{
  if(!serialSelecting || !serialSelectionStart || e.pointerId!==serialSelectionPointer)return;
  e.preventDefault();const point=previewNormalizedPoint(e,true);
  if(point)drawSerialSelection(serialSelectionStart,point);
});
cameraCapturedPreview?.addEventListener('pointerup',e=>{
  if(!serialSelecting || !serialSelectionStart || e.pointerId!==serialSelectionPointer)return;
  e.preventDefault();const point=previewNormalizedPoint(e,true);
  const region=point?drawSerialSelection(serialSelectionStart,point):null;
  serialIgnoreClickUntil=performance.now()+600;resetSerialSelection();
  if(!region || region.w*cameraCapturedPreview.naturalWidth<12 || region.h*cameraCapturedPreview.naturalHeight<6){
    cameraShowMessage('Choose Select Text, then drag a box around the entire printed value.',false);return;
  }
  readTappedSerial(null,region);
});
cameraCapturedPreview?.addEventListener('pointercancel',()=>{resetSerialSelection();});
cameraCapturedPreview?.addEventListener('click',e=>{
  if(serialSelecting || performance.now()<serialIgnoreClickUntil || cameraBusy)return;
  if(!cameraCapturedFile || !cameraTargetInput || !['imei','serial','barcode'].includes(cameraKind()))return;
  const point=previewNormalizedPoint(e);
  if(point)readTappedSerial(point);
});

function bindIdentifierInputs(){
  const inputs=[...document.querySelectorAll('[data-identifier-input]')];
  inputs.forEach(input=>{
    input.addEventListener('focus',()=>updateScannerBadge('ready'));
    input.addEventListener('input',()=>{
      clearRestoreState(input);
      setIdentifierState(input,'','');
      updateIdentifierCount();
      clearTimeout(identifierTimer);
      const normalized=input.value.replace(/\s+/g,'').trim().toUpperCase();
      const imeiField=identifierInputKind(input)==='imei';
      if(imeiField && /^\d{15}$/.test(normalized)){
        identifierTimer=setTimeout(async()=>{
          if(document.activeElement!==input)return;
          if(await checkIdentifier(input)) focusNextIdentifier(input);
        },160);
      }else{
        identifierTimer=setTimeout(()=>checkIdentifier(input),300);
      }
    });
    input.addEventListener('keydown',async e=>{
      if(e.key!=='Enter' && e.key!=='Tab')return;
      e.preventDefault();
      clearTimeout(identifierTimer);
      const ok=await checkIdentifier(input);
      if(ok)focusNextIdentifier(input);
    });
  });
}
$('focusScannerBtn')?.addEventListener('click',focusFirstEmptyIdentifier);
function updateIdentifierCount(){
  const required=[...document.querySelectorAll('[data-identifier-primary]')];
  const done=required.filter(i=>i.value.trim()).length;
  countBadge.textContent=`${done} / ${required.length}`;
}
const identifierChecks=new WeakMap();
async function checkIdentifier(input){
  const v=input.value.replace(/\s+/g,'').trim().toUpperCase();
  input.value=v;
  const kind=identifierInputKind(input);
  const request={value:v,kind,product:String(productId.value||''),branch:String(activeBranchId()||'')};
  identifierChecks.set(input,request);
  const isCurrent=()=>input.isConnected && identifierChecks.get(input)===request
    && input.value.replace(/\s+/g,'').trim().toUpperCase()===v
    && identifierInputKind(input)===request.kind
    && String(productId.value||'')===request.product && String(activeBranchId()||'')===request.branch;
  if(!v){clearRestoreState(input);setIdentifierState(input,'','');updateScannerBadge('ready');return input.dataset.identifierSecondary==='1';}
  if(kind==='barcode' && !validStockBarcode(v)){
    clearRestoreState(input);setIdentifierState(input,'error','Enter a barcode value up to 120 characters');updateScannerBadge('error');return false;
  }
  if(isApple(selectedItem) && !MalbcoffImeiReader.validSerial(v)){
    clearRestoreState(input);setIdentifierState(input,'error','Enter the alphanumeric Serial Number (S/N), not the IMEI');updateScannerBadge('error');return false;
  }
  if(kind==='imei' && !/^\d{15}$/.test(v)){
    clearRestoreState(input);setIdentifierState(input,'error','IMEI must be 15 digits');updateScannerBadge('error');return false;
  }
  if(kind==='imei' && !validImeiChecksum(v)){
    clearRestoreState(input);setIdentifierState(input,'error','Invalid IMEI');updateScannerBadge('error');return false;
  }
  const approvedFor=input.dataset.restoreApprovedValue||'';
  setIdentifierState(input,'checking','Checking…');
  updateScannerBadge('checking');
  try{
    const params=new URLSearchParams({check_identifier:v,identifier_type:kind,product_id:request.product,branch_id:request.branch});
    const res=await fetch('actions/stock_in.php?'+params.toString(),{headers:{Accept:'application/json'}});
    const data=await res.json();
    if(!isCurrent())return false;
    if(!data.exists){clearRestoreState(input);setIdentifierState(input,'valid','Ready');updateScannerBadge('ready');return true;}

    if(data.restorable && input.dataset.identifierSecondary!=='1'){
      input.dataset.restoreUnitId=String(data.unit_id||'');
      input.dataset.restoreBranch=String(data.branch_name||'');
      input.dataset.restoreReason=String(data.adjustment_label||data.adjustment_reason||'Previous adjustment');
      if(input.dataset.restoreApproved==='1' && approvedFor===v){setIdentifierState(input,'restore-approved','Ready to restore');updateScannerBadge('ready');return true;}
      delete input.dataset.restoreApproved;delete input.dataset.restoreApprovedValue;
      setIdentifierState(input,'restore',`Previously removed • ${input.dataset.restoreReason}`);
      updateScannerBadge('error','Restore required');
      return false;
    }

    clearRestoreState(input);
    if(data.restore_requires_branch){setIdentifierState(input,'error','Select branch first');updateScannerBadge('error');return false;}
    if(input.dataset.identifierSecondary==='1' && data.restorable){
      setIdentifierState(input,'error','Already registered. Restore using IMEI 1.');
      updateScannerBadge('error');
      return false;
    }
    setIdentifierState(input,'error',data.message||'Already used');
    updateScannerBadge('error');
    return false;
  }catch{
    if(!isCurrent())return false;
    setIdentifierState(input,'','');
    updateScannerBadge('ready');
    return true;
  }
}
async function validateIdentifiers(){
  const required=[...document.querySelectorAll('[data-identifier-primary]')];
  const optional=[...document.querySelectorAll('[data-identifier-secondary]')];
  if(!required.length)return true;

  const all=[...required,...optional];
  all.forEach(input=>input.value=input.value.replace(/\s+/g,'').trim().toUpperCase());
  let ok=true;
  const seen=new Map();

  for(const input of required){
    if(!input.value){
      setIdentifierState(input,'error','Required');
      ok=false;
    }
  }
  for(const input of all){
    if(!input.value)continue;
    if(seen.has(input.value)){
      setIdentifierState(input,'error','Duplicate in list');
      setIdentifierState(seen.get(input.value),'error','Duplicate in list');
      ok=false;
    }else{
      seen.set(input.value,input);
    }
  }
  if(!ok)return false;

  for(const input of all){
    if(input.value && !(await checkIdentifier(input)))ok=false;
  }
  return ok;
}

$('openPasteIdentifiers').addEventListener('click',()=>{
  const dual=usesDualImei();
  const kind=identifierKind();
  const label=dual?'IMEI 1 / IMEI 2':(kind==='serial'?'Serial Numbers':'IMEIs');
  const singular=kind==='serial'?'serial number':'IMEI';
  $('pasteIdentifiersTitle').textContent='Paste '+label;
  $('pasteIdentifiersLabel').textContent=label;
  const help=$('pasteIdentifiersHelp');
  if(dual){
    $('pasteIdentifiersInput').placeholder='IMEI 1, IMEI 2 (optional) — one phone per line';
    if(help)help.textContent='One phone per line. Separate IMEI 1 and optional IMEI 2 with a comma, tab, or |.';
  }else{
    $('pasteIdentifiersInput').placeholder='Paste one '+singular+' per line';
    if(help)help.textContent='One identifier per line. Extra spaces are removed automatically.';
  }
  $('pasteIdentifiersInput').value='';
  updatePasteMeta();
  pasteModal.hidden=false;
  document.body.classList.add('modal-open');
  setTimeout(()=>$('pasteIdentifiersInput').focus(),40);
});
document.querySelectorAll('[data-paste-close]').forEach(b=>b.addEventListener('click',()=>{pasteModal.hidden=true;document.body.classList.remove('modal-open');}));

function pastedRows(){
  const text=$('pasteIdentifiersInput').value.trim();
  if(!text)return[];
  if(usesDualImei()){
    return text.split(/\r?\n/).map(line=>{
      const parts=line.split(/\t|,|\|/).map(v=>v.replace(/\s+/g,'').trim().toUpperCase()).filter(Boolean);
      return {primary:parts[0]||'',secondary:parts[1]||''};
    }).filter(row=>row.primary);
  }
  return text.split(/\r?\n|,/).map(v=>v.replace(/\s+/g,'').trim().toUpperCase()).filter(Boolean).map(primary=>({primary,secondary:''}));
}
function updatePasteMeta(){
  const rows=pastedRows(),needed=Number(quantity.value)||1;
  $('pasteIdentifiersMeta').textContent=`${rows.length} unit${rows.length===1?'':'s'} detected`;
  $('pasteIdentifiersWarning').textContent=rows.length>needed?`${rows.length-needed} extra will not be applied.`:rows.length<needed?`${needed-rows.length} unit${needed-rows.length===1?'':'s'} will remain blank.`:'';
}
$('pasteIdentifiersInput').addEventListener('input',updatePasteMeta);
$('applyPasteIdentifiers').addEventListener('click',()=>{
  const rows=pastedRows();
  const primaries=[...document.querySelectorAll('[data-identifier-primary]')];
  const secondaries=[...document.querySelectorAll('[data-identifier-secondary]')];
  primaries.forEach((input,n)=>{
    if(rows[n]!==undefined){
      input.value=rows[n].primary||'';
      clearRestoreState(input);
      setIdentifierState(input,'','');
      if(secondaries[n]){
        secondaries[n].value=identifierInputKind(input)==='barcode'?'':rows[n].secondary||'';
        clearRestoreState(secondaries[n]);
        setIdentifierState(secondaries[n],'','');
      }
    }
  });
  updateIdentifierCount();
  pasteModal.hidden=true;
  document.body.classList.remove('modal-open');
});

const restoreModal=$('restoreUnitModal');
let pendingRestoreInput=null;
identifierRows.addEventListener('click',e=>{
  const btn=e.target.closest('[data-restore-trigger]');
  if(!btn)return;
  const row=btn.closest('.identifier-entry');
  const input=row?.querySelector('[data-identifier-primary]');
  if(!input||!input.dataset.restoreUnitId)return;
  pendingRestoreInput=input;
  $('restoreUnitIdentifier').textContent=input.value.trim().toUpperCase()||'—';
  $('restoreUnitProduct').textContent=selectedItem&&selectedVariant?`${selectedItem.label} • ${selectedVariant.specs}`:'Selected variant';
  $('restoreUnitBranch').textContent=input.dataset.restoreBranch|| (isOwner?($('branchSelect')?.selectedOptions[0]?.textContent||'Selected branch'):assignedBranchName);
  $('restoreUnitReason').textContent=input.dataset.restoreReason||'Previous adjustment';
  restoreModal.hidden=false;document.body.classList.add('modal-open');
});
document.querySelectorAll('[data-restore-close]').forEach(b=>b.addEventListener('click',()=>{restoreModal.hidden=true;pendingRestoreInput=null;document.body.classList.remove('modal-open');}));
$('confirmRestoreUnit').addEventListener('click',()=>{
  if(!pendingRestoreInput)return;
  pendingRestoreInput.dataset.restoreApproved='1';
  pendingRestoreInput.dataset.restoreApprovedValue=pendingRestoreInput.value.replace(/\s+/g,'').trim().toUpperCase();
  setIdentifierState(pendingRestoreInput,'restore-approved','Ready to restore');
  restoreModal.hidden=true;
  const next=[...document.querySelectorAll('[data-identifier-input]')];
  const idx=next.indexOf(pendingRestoreInput);
  pendingRestoreInput=null;
  document.body.classList.remove('modal-open');
  next[idx+1]?.focus();
});

form.addEventListener('submit',async e=>{
  if($('confirmedField').value==='1')return;
  e.preventDefault();
  if(!productId.value)return;
  if(isOwner && !$('branchSelect').value){$('branchSelect').focus();return;}
  if(canEditSelling&&$('sellingPriceInput')&&Number($('sellingPriceInput').value||0)<=0){$('sellingPriceInput').focus();return;}
  if(!(await validateIdentifiers()))return;
  $('confirmProduct').textContent=selectedItem.kind==='accessory'?selectedItem.label:`${selectedItem.label} • ${selectedVariant.specs}`;
  $('confirmBranch').textContent=isOwner?$('branchSelect').selectedOptions[0]?.textContent||'—':assignedBranchName;
  $('confirmQuantity').textContent=(Number(quantity.value)||1)+' unit'+((Number(quantity.value)||1)===1?'':'s');
  if(isOwner && $('confirmCost')) $('confirmCost').textContent=selectedVariant.costReady?money(selectedVariant.cost):'Not set'; const salePrice=canEditSelling&&$('sellingPriceInput')?Number($('sellingPriceInput').value||0):variantSelling(selectedVariant); $('confirmSelling').textContent=money(salePrice);
  const identifierInputs=[...document.querySelectorAll('[data-identifier-primary]')];
  const box=$('confirmIdentifiers');
  let identifierSummary=[];
  if(usesDualImei()){
    const secondaries=[...document.querySelectorAll('[data-identifier-secondary]')];
    identifierSummary=identifierInputs.map((input,index)=>{
      const primary=input.value.trim().toUpperCase();
      const secondary=secondaries[index]?.value.trim().toUpperCase()||'';
      return identifierInputKind(input)==='barcode'?`Serial / Barcode: ${primary}`:secondary?`IMEI 1: ${primary} / IMEI 2: ${secondary}`:`IMEI 1: ${primary}`;
    }).filter(Boolean);
  }else{
    identifierSummary=identifierInputs.map(i=>i.value.trim().toUpperCase()).filter(Boolean);
  }
  box.classList.toggle('hidden',!identifierSummary.length);
  box.innerHTML=identifierSummary.length?`<strong>Device Identifiers</strong><span>${identifierSummary.map(esc).join(' • ')}</span>`:'';
  const restoreCount=identifierInputs.filter(i=>i.dataset.restoreApproved==='1'&&i.dataset.restoreUnitId).length; const restoreNotice=$('confirmRestoreNotice'); restoreNotice?.classList.toggle('hidden',restoreCount===0); if(restoreCount>0){restoreNotice.querySelector('strong').textContent=`Restore ${restoreCount} existing unit${restoreCount===1?'':'s'}`;}
  confirmModal.hidden=false; document.body.classList.add('modal-open');
});
document.querySelectorAll('[data-confirm-close]').forEach(b=>b.addEventListener('click',()=>{confirmModal.hidden=true;document.body.classList.remove('modal-open');}));
$('confirmStockIn').addEventListener('click',()=>{$('confirmedField').value='1';form.submit();});

if(presetProductId){
  for(const item of items){
    if(item.kind==='accessory' && Number(item.id)===presetProductId){selectItem(item);break;}
    if(item.kind==='model'){const v=item.variants.find(v=>Number(v.id)===presetProductId);if(v){selectItem(item);selectVariant(v);break;}}
  }
}
})();
</script>
