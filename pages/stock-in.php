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

<div class="modal camera-scan-modal" id="cameraScanModal" hidden>
    <div class="modal-backdrop" data-camera-close></div>
    <div class="modal-dialog camera-scan-dialog">
        <div class="modal-header">
            <div><span class="eyebrow">CAMERA SCANNER</span><h2 id="cameraScanTitle">Scan Identifier</h2><p class="modal-subtitle" id="cameraScanSubtitle">Point the camera at one barcode only.</p></div>
            <button type="button" class="icon-button" data-camera-close>×</button>
        </div>
        <div class="modal-body">
            <div class="camera-scan-stage" id="cameraScanStage">
                <video id="cameraScanVideo" playsinline muted></video>
                <div class="camera-scan-guide"><span></span></div>
                <div class="camera-scan-state" id="cameraScanState">Starting camera…</div>
            </div>
            <div class="camera-scan-message hidden" id="cameraScanMessage"></div>
            <div class="camera-scan-tips">Keep the IMEI label close and sharp. The scanner accepts a valid IMEI barcode or the printed IMEI 1 / IMEI 2 text.</div>
            <button class="btn btn-outline camera-gallery-btn hidden" type="button" id="cameraGalleryBtn">Use Existing Photo</button>
        </div>
        <div class="modal-actions camera-scan-actions">
            <button class="btn btn-secondary" type="button" data-camera-close>Cancel</button>
            <button class="btn btn-outline" type="button" id="cameraRetryBtn">Retry Camera</button>
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

<script src="https://unpkg.com/@zxing/library@0.20.0/umd/index.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js"></script>
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
function isApple(item){ return (item?.brand || '').toLowerCase() === 'apple'; }
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
function identifierFieldHtml({name,value,placeholder,label,required=false,secondary=false,numeric=false,includeCamera=true}){
  const scanLabel=label || (identifierKind()==='serial'?'Serial Number':'IMEI');
  return `<div class="identifier-field-wrap">
    ${label?`<span class="identifier-field-label">${esc(label)}${required?' <b>*</b>':''}</span>`:''}
    <div class="identifier-input-row ${includeCamera?'':'identifier-input-row-solo'}">
      <input name="${name}" value="${esc(value||'')}" autocomplete="off" placeholder="${esc(placeholder)}"
        ${numeric?'inputmode="numeric" pattern="[0-9]*"':''}
        data-identifier-input ${secondary?'data-identifier-secondary="1"':'data-identifier-primary="1"'} data-uppercase>
      ${includeCamera?`<button class="btn btn-outline identifier-camera-btn" type="button" data-camera-scan aria-label="Scan ${esc(scanLabel)} with camera">Scan</button>`:''}
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

  const existingPrimary=[...identifierRows.querySelectorAll('[data-identifier-primary]')].map(i=>i.value);
  const existingSecondary=[...identifierRows.querySelectorAll('[data-identifier-secondary]')].map(i=>i.value);

  if(dual){
    $('identifierPanelTitle').textContent='IMEI Numbers';
    $('identifierPanelHint').textContent=count===1
      ? 'Enter IMEI 1. IMEI 2 is optional if the phone has a second IMEI.'
      : `Enter IMEI 1 for each of the ${count} phones. IMEI 2 is optional.`;
    identifierRows.innerHTML=Array.from({length:count},(_,i)=>`
      <div class="identifier-entry identifier-entry-dual">
        <span class="identifier-entry-number">${i+1}</span>
        <div class="identifier-dual-grid">
          <div class="identifier-pair-scan-card">
            <div>
              <strong>Camera Scan</strong>
              <span>Take one close photo of the IMEI label. We will fill IMEI 1 and IMEI 2 together.</span>
            </div>
            <button class="btn btn-outline identifier-pair-scan-btn" type="button" data-camera-scan-pair>Scan IMEI Label</button>
          </div>
          ${identifierFieldHtml({name:'identifiers[]',value:existingPrimary[i]||'',placeholder:'SCAN OR ENTER IMEI 1',label:'IMEI 1',required:true,numeric:true,includeCamera:false})}
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
        ${identifierFieldHtml({name:'identifiers[]',value:existingPrimary[i]||'',placeholder:`SCAN OR ENTER ${label}`,required:true,numeric:kind==='imei'})}
      </div>`).join('');
  }

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
  const target=inputs.find(i=>!i.value.trim() && !i.disabled) || inputs[0];
  if(target){target.focus();target.select?.();updateScannerBadge('ready');}
}
function scheduleScannerFocus(){setTimeout(()=>{if(!identifierPanel.classList.contains('hidden'))focusFirstEmptyIdentifier();},90);}
function focusNextIdentifier(input){
  const rows=[...identifierRows.querySelectorAll('.identifier-entry')];
  const row=input.closest('.identifier-entry');
  const rowIndex=rows.indexOf(row);
  let next=null;
  if(usesDualImei()){
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
let cameraTargetInput=null;
let cameraPairRow=null;
let cameraStream=null;
let cameraDetector=null;
let cameraFrameHandle=0;
let cameraBusy=false;

function cameraFieldLabel(input){
  if(input?.dataset.identifierSecondary==='1')return 'IMEI 2';
  if(identifierKind()==='serial')return 'Serial Number';
  return usesDualImei()?'IMEI 1':'IMEI';
}
function cameraStop(){
  if(cameraFrameHandle)cancelAnimationFrame(cameraFrameHandle);
  cameraFrameHandle=0;
  cameraBusy=false;
  if(cameraStream){cameraStream.getTracks().forEach(track=>track.stop());cameraStream=null;}
  if(cameraVideo){cameraVideo.srcObject=null;}
}
function closeCameraScanner(){
  cameraStop();
  if(cameraModal)cameraModal.hidden=true;
  document.body.classList.remove('modal-open');
  cameraTargetInput?.focus();
  cameraTargetInput=null;
  cameraPairRow=null;
}
function cameraShowMessage(message,isError=true,stateText=''){
  cameraState.textContent=stateText || (isError?'Scan needs attention':'Ready');
  cameraMessage.textContent=message;
  cameraMessage.classList.remove('hidden');
  cameraMessage.classList.toggle('is-error',isError);
}
async function cameraAcceptValue(raw){
  if(!cameraTargetInput)return false;
  const value=String(raw||'').replace(/\s+/g,'').trim().toUpperCase();
  const imeiField=identifierKind()==='imei';
  if(imeiField){
    if(!/^\d{15}$/.test(value) || !validImeiChecksum(value)){
      cameraState.textContent='Not an IMEI — keep scanning';
      return false;
    }
  }else if(!value){
    return false;
  }
  cameraBusy=true;
  cameraTargetInput.value=value;
  cameraTargetInput.dispatchEvent(new Event('input',{bubbles:true}));
  const ok=await checkIdentifier(cameraTargetInput);
  if(!ok){cameraState.textContent='Identifier needs attention';cameraBusy=false;return false;}
  const completed=cameraTargetInput;
  cameraStop();
  cameraModal.hidden=true;
  document.body.classList.remove('modal-open');
  cameraTargetInput=null;
  focusNextIdentifier(completed);
  return true;
}
async function cameraDetectLoop(){
  if(!cameraStream || !cameraDetector || !cameraVideo || cameraModal.hidden)return;
  if(!cameraBusy && cameraVideo.readyState>=2){
    try{
      const found=await cameraDetector.detect(cameraVideo);
      for(const code of found){
        if(await cameraAcceptValue(code.rawValue))return;
      }
    }catch(e){}
  }
  cameraFrameHandle=requestAnimationFrame(cameraDetectLoop);
}
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
async function recognizeImeiPairFast(file){
  const worker=await getCameraOcrWorker();
  const base=await fileToPairBaseCanvas(file);
  cameraState.textContent='Finding IMEI label…';
  const anchor=await decodeBarcodeAnchor(file,base);
  const merged={labelled:{1:'',2:''},ordered:[],markers:{1:false,2:false}};
  const run=async(canvas,psm,label)=>{
    cameraState.textContent=label;
    await worker.setParameters({
      tessedit_pageseg_mode:String(psm),
      preserve_interword_spaces:'1'
    });
    const result=await worker.recognize(canvas);
    const evidence=imeiEvidenceFromText(result?.data?.text||'');
    mergeImeiEvidence(merged,evidence);
    return evidence;
  };

  // Fast path: use the detected 1D/QR barcode only as a LOCATION anchor.
  // The barcode value itself is never blindly assigned to IMEI 1/2.
  if(anchor.rect){
    const focused=preparePairOcrCanvas(base.canvas,anchor.rect);
    await run(focused,6,'Reading IMEI 1 + IMEI 2…');
    let pair=pairFromEvidence(merged,{anchored:true,barcodeRaw:anchor.raw});
    if(pair[1] && (pair[2] || !usesDualImei()))return {pair,evidence:merged,anchored:true,barcodeRaw:anchor.raw};

    // One small sparse-text fallback only when the focused block pass missed a line.
    await run(focused,11,'Checking IMEI label…');
    pair=pairFromEvidence(merged,{anchored:true,barcodeRaw:anchor.raw});
    if(pair[1] || pair[2])return {pair,evidence:merged,anchored:true,barcodeRaw:anchor.raw};
  }

  // Fallback when the barcode itself could not be decoded: locate the densest
  // label-like region first so we still avoid OCR over the entire camera photo.
  const likely=likelyImeiRect(base.canvas);
  if(likely && likely.width>80 && likely.height>60){
    const focused=preparePairOcrCanvas(base.canvas,likely);
    await run(focused,6,'Reading IMEI label…');
    let pair=pairFromEvidence(merged,{anchored:true,barcodeRaw:''});
    if(pair[1] && (pair[2] || !usesDualImei()))return {pair,evidence:merged,anchored:true,barcodeRaw:''};
    await run(focused,11,'Checking IMEI label…');
    pair=pairFromEvidence(merged,{anchored:true,barcodeRaw:''});
    if(pair[1] || pair[2])return {pair,evidence:merged,anchored:true,barcodeRaw:''};
  }

  // Last resort: one resized full-image pass only.
  const full=preparePairOcrCanvas(base.canvas,null);
  await run(full,6,'Reading close-up IMEI label…');
  const pair=pairFromEvidence(merged,{anchored:false,barcodeRaw:anchor.raw});
  return {pair,evidence:merged,anchored:false,barcodeRaw:anchor.raw};
}
async function setCameraIdentifierValue(input,value){
  if(!input || !value)return false;
  input.value=value;
  input.dispatchEvent(new Event('input',{bubbles:true}));
  return await checkIdentifier(input);
}
async function decodeImeiPairPhoto(file){
  if(!cameraPairRow)return;
  const primary=cameraPairRow.querySelector('[data-identifier-primary]');
  const secondary=cameraPairRow.querySelector('[data-identifier-secondary]');
  cameraState.textContent='Reading IMEI label…';
  cameraMessage.classList.add('hidden');
  try{
    const result=await recognizeImeiPairFast(file);
    const imei1=result.pair?.[1]||'';
    const imei2=result.pair?.[2]||'';
    let ok1=false,ok2=false;
    if(imei1)ok1=await setCameraIdentifierValue(primary,imei1);
    if(imei2)ok2=await setCameraIdentifierValue(secondary,imei2);

    if(ok1 && (ok2 || !imei2)){
      if(ok2){
        cameraStop();
        cameraModal.hidden=true;
        const completedSecondary=secondary;
        cameraTargetInput=null;
        cameraPairRow=null;
        focusNextIdentifier(completedSecondary);
        return;
      }
      cameraShowMessage('IMEI 1 captured. IMEI 2 was not clear. Retake the same label once more, or leave IMEI 2 blank only for a single-IMEI phone.',false,'IMEI 1 captured');
      secondary?.focus();
      return;
    }
    if(!imei1 && imei2){
      cameraShowMessage('IMEI 2 was detected, but IMEI 1 was not clear. Retake one close photo showing both IMEI lines.');
      return;
    }
    if(imei1 && !ok1){
      cameraShowMessage('IMEI 1 was read but needs attention. Check the field message before scanning again.');
      return;
    }
    cameraShowMessage('IMEI label not detected. Take one close, straight photo showing IMEI1 and IMEI2 together.');
  }catch(err){
    cameraShowMessage('Could not read the IMEI label. Retake one close, sharp photo of the IMEI1 / IMEI2 sticker.');
  }
}
function startImeiPairScanner(button){
  const row=button.closest('.identifier-entry-dual');
  if(!row)return;
  cameraStop();
  cameraPairRow=row;
  cameraTargetInput=row.querySelector('[data-identifier-primary]');
  $('cameraScanTitle').textContent='Scan IMEI Label';
  $('cameraScanSubtitle').textContent='Take one close photo. IMEI 1 and IMEI 2 will be filled together.';
  cameraState.textContent='Preparing IMEI reader…';
  cameraMessage.classList.add('hidden');
  cameraMessage.classList.remove('is-error');
  cameraModal.hidden=false;
  document.body.classList.add('modal-open');
  if(cameraRetryBtn)cameraRetryBtn.textContent='Open Camera';
  cameraGalleryBtn?.classList.remove('hidden');
  warmCameraOcr();
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
async function decodeLocalPhoto(file){
  if(!file || !cameraTargetInput)return;
  if(cameraPairRow){
    try{await decodeImeiPairPhoto(file);}
    finally{
      if(cameraPhotoInput)cameraPhotoInput.value='';
      if(cameraGalleryInput)cameraGalleryInput.value='';
    }
    return;
  }
  const slot=cameraTargetInput.dataset.identifierSecondary==='1'?2:1;
  cameraState.textContent=`Reading IMEI ${slot}…`;
  cameraMessage.classList.add('hidden');
  let barcodeRaw='';
  const url=URL.createObjectURL(file);
  try{
    // Strict rule for photos: read the printed IMEI1:/IMEI2: labels first.
    // A raw 15-digit barcode has no slot metadata, so accepting the first valid
    // barcode could put IMEI 2 into the IMEI 1 field (or vice versa).
    const printed=await decodePrintedImei(file);
    if(printed.accepted)return;

    if(window.ZXing?.BrowserMultiFormatReader){
      try{
        const reader=new ZXing.BrowserMultiFormatReader();
        const result=await reader.decodeFromImageUrl(url);
        barcodeRaw=result?.getText?.() ?? result?.text ?? String(result||'');
      }catch(err){}
    }

    const otherSlot=slot===1?2:1;
    if(printed.labelled?.[otherSlot] && !printed.labelled?.[slot] && !(printed.ordered?.length>=2)){
      cameraShowMessage(`IMEI ${slot} was not found. The photo read IMEI ${otherSlot} instead. Retake closer so both IMEI lines are sharp and visible.`);
      return;
    }

    // Never use an unlabeled barcode to decide IMEI 1 vs IMEI 2 on dual-IMEI
    // phones. Some packaging has a single 1D barcode that represents IMEI 2;
    // accepting it while Scan IMEI 1 is active would silently swap the slots.
    // Barcode-only fallback remains available only for non-dual identifier flows.
    if(barcodeRaw && !usesDualImei() && !printed.labelled?.[1] && !printed.labelled?.[2]){
      const normalized=String(barcodeRaw||'').replace(/\s+/g,'').trim();
      if(/^\d{15}$/.test(normalized) && validImeiChecksum(normalized)){
        if(await cameraAcceptValue(normalized))return;
      }
    }

    if(barcodeRaw){
      cameraShowMessage(`IMEI ${slot} not confirmed. Retake closer so the IMEI 1 / IMEI 2 lines are sharp and fill most of the photo.`);
    }else{
      cameraShowMessage(`IMEI ${slot} not detected. Retake closer so both IMEI lines fill most of the photo.`);
    }
  }finally{
    URL.revokeObjectURL(url);
    if(cameraPhotoInput)cameraPhotoInput.value='';
    if(cameraGalleryInput)cameraGalleryInput.value='';
  }
}
function openLocalPhotoScanner(){
  cameraStop();
  cameraState.textContent='Local camera mode';
  cameraShowMessage('Local HTTP test: take a close-up so the IMEI label fills most of the photo. For owner-sent test photos, use Existing Photo to avoid re-photographing a screen.',false);
  if(cameraRetryBtn)cameraRetryBtn.textContent='Open Camera';
  cameraGalleryBtn?.classList.remove('hidden');
  warmCameraOcr();
  cameraPhotoInput?.click();
}

async function startCameraScanner(input){
  cameraStop();
  cameraTargetInput=input;
  const label=cameraFieldLabel(input);
  $('cameraScanTitle').textContent='Scan '+label;
  $('cameraScanSubtitle').textContent='Only a valid '+label+' will be accepted.';
  cameraState.textContent='Starting camera…';
  cameraMessage.classList.add('hidden');
  cameraMessage.classList.remove('is-error');
  cameraModal.hidden=false;
  document.body.classList.add('modal-open');

  if(!window.isSecureContext){
    openLocalPhotoScanner();
    return;
  }
  if(cameraRetryBtn)cameraRetryBtn.textContent='Retry Camera';
  cameraGalleryBtn?.classList.add('hidden');
  if(!('BarcodeDetector' in window)){
    cameraShowMessage('This browser does not support the built-in barcode detector. Use External Scanner or manual input on this device.');
    return;
  }
  if(!navigator.mediaDevices?.getUserMedia){
    cameraShowMessage('Camera access is not available in this browser. Use External Scanner or manual input.');
    return;
  }
  try{
    const supported=await BarcodeDetector.getSupportedFormats?.() || [];
    const preferred=['code_128','code_39','ean_13','ean_8','itf','upc_a','upc_e','qr_code'].filter(f=>supported.includes(f));
    cameraDetector=new BarcodeDetector(preferred.length?{formats:preferred}:undefined);
    cameraStream=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'},width:{ideal:1280},height:{ideal:720}},audio:false});
    cameraVideo.srcObject=cameraStream;
    await cameraVideo.play();
    cameraState.textContent='Scanning '+label+'…';
    cameraFrameHandle=requestAnimationFrame(cameraDetectLoop);
  }catch(err){
    cameraShowMessage('Camera could not start. Allow camera permission, or use External Scanner / manual input.');
  }
}
identifierRows.addEventListener('click',e=>{
  const pairBtn=e.target.closest('[data-camera-scan-pair]');
  if(pairBtn){
    startImeiPairScanner(pairBtn);
    return;
  }
  const btn=e.target.closest('[data-camera-scan]');
  if(!btn)return;
  const input=btn.closest('.identifier-field-wrap')?.querySelector('[data-identifier-input]');
  if(input)startCameraScanner(input);
});
document.querySelectorAll('[data-camera-close]').forEach(b=>b.addEventListener('click',closeCameraScanner));
cameraRetryBtn?.addEventListener('click',()=>{
  if(!cameraTargetInput)return;
  if(cameraPairRow){
    warmCameraOcr();
    cameraState.textContent='Ready for one close IMEI label photo…';
    cameraMessage.classList.add('hidden');
    cameraPhotoInput?.click();
    return;
  }
  if(!window.isSecureContext)openLocalPhotoScanner();
  else startCameraScanner(cameraTargetInput);
});
cameraPhotoInput?.addEventListener('change',()=>{
  const file=cameraPhotoInput.files?.[0];
  if(file)decodeLocalPhoto(file);
});
cameraGalleryBtn?.addEventListener('click',()=>{
  if(!cameraTargetInput)return;
  warmCameraOcr();
  cameraGalleryInput?.click();
});
cameraGalleryInput?.addEventListener('change',()=>{
  const file=cameraGalleryInput.files?.[0];
  if(file)decodeLocalPhoto(file);
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
      const imeiField=identifierKind()==='imei';
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
async function checkIdentifier(input){
  const v=input.value.replace(/\s+/g,'').trim().toUpperCase();
  input.value=v;
  if(!v){clearRestoreState(input);setIdentifierState(input,'','');updateScannerBadge('ready');return input.dataset.identifierSecondary==='1';}
  if(identifierKind()==='imei' && !/^\d{15}$/.test(v)){
    clearRestoreState(input);setIdentifierState(input,'error','IMEI must be 15 digits');updateScannerBadge('error');return false;
  }
  if(identifierKind()==='imei' && !validImeiChecksum(v)){
    clearRestoreState(input);setIdentifierState(input,'error','Invalid IMEI');updateScannerBadge('error');return false;
  }
  const approvedFor=input.dataset.restoreApprovedValue||'';
  setIdentifierState(input,'checking','Checking…');
  updateScannerBadge('checking');
  try{
    const params=new URLSearchParams({check_identifier:v,product_id:String(productId.value||''),branch_id:String(activeBranchId()||'')});
    const res=await fetch('actions/stock_in.php?'+params.toString(),{headers:{Accept:'application/json'}});
    const data=await res.json();
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
        secondaries[n].value=rows[n].secondary||'';
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
      return secondary?`IMEI 1: ${primary} / IMEI 2: ${secondary}`:`IMEI 1: ${primary}`;
    }).filter(Boolean);
  }else{
    identifierSummary=identifierInputs.map(i=>i.value.trim().toUpperCase()).filter(Boolean);
  }
  box.classList.toggle('hidden',!identifierSummary.length);
  box.innerHTML=identifierSummary.length?`<strong>${esc(usesDualImei()?'IMEI Numbers':(identifierKind()==='serial'?'Serial Numbers':'IMEIs'))}</strong><span>${identifierSummary.map(esc).join(' • ')}</span>`:'';
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
