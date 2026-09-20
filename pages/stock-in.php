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
function identifierFieldHtml({name,value,placeholder,label,required=false,secondary=false,numeric=false}){
  return `<div class="identifier-field-wrap">
    ${label?`<span class="identifier-field-label">${esc(label)}${required?' <b>*</b>':''}</span>`:''}
    <input name="${name}" value="${esc(value||'')}" autocomplete="off" placeholder="${esc(placeholder)}"
      ${numeric?'inputmode="numeric" pattern="[0-9]*"':''}
      data-identifier-input ${secondary?'data-identifier-secondary="1"':'data-identifier-primary="1"'} data-uppercase>
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
          ${identifierFieldHtml({name:'identifiers[]',value:existingPrimary[i]||'',placeholder:'SCAN OR ENTER IMEI 1',label:'IMEI 1',required:true,numeric:true})}
          ${identifierFieldHtml({name:'secondary_identifiers[]',value:existingSecondary[i]||'',placeholder:'SCAN OR ENTER IMEI 2 (OPTIONAL)',label:'IMEI 2',secondary:true,numeric:true})}
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
