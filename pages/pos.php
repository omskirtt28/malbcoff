<?php
$role = Auth::user()['role'] ?? '';
$canSell = pos_role_allowed($role);
$scope = current_branch_scope();
$branches = [];
$activeBranch = null;
$recentSales = [];
$saleSuccessRaw = flash('sale_success');
$saleSuccess = $saleSuccessRaw ? json_decode($saleSuccessRaw, true) : null;
$posReady = false;
$posMissing = [];

try {
    $branches = Database::query('SELECT id,name,code FROM branches WHERE is_active=1 ORDER BY id')->fetchAll();
    $branchId = Auth::isOwner() ? ($scope ?: 0) : (Auth::branchId() ?: 0);
    if ($branchId) {
        $activeBranch = Database::query('SELECT id,name,code FROM branches WHERE id=? AND is_active=1 LIMIT 1', [$branchId])->fetch();
    }
    $schemaChecks = [
        'sales table' => (bool)Database::query("SHOW TABLES LIKE 'sales'")->fetchColumn(),
        'sale_items table' => (bool)Database::query("SHOW TABLES LIKE 'sale_items'")->fetchColumn(),
        'branch pricing' => branch_pricing_ready(),
        'inventory condition' => (bool)Database::query("SHOW COLUMNS FROM inventory_units LIKE 'condition_type'")->fetch(),
        'inventory acquisition cost' => (bool)Database::query("SHOW COLUMNS FROM inventory_units LIKE 'acquisition_cost'")->fetch(),
        'stock movement pricing snapshots' => (bool)Database::query("SHOW COLUMNS FROM stock_movements LIKE 'unit_cost'")->fetch(),
    ];
    foreach ($schemaChecks as $label => $ready) if (!$ready) $posMissing[] = $label;
    $posReady = !$posMissing;

    if ($activeBranch && $schemaChecks['sales table']) {
        $recentSales = Database::query(
            "SELECT s.sale_no,s.total,s.payment_method,s.created_at,u.name cashier_name
             FROM sales s JOIN users u ON u.id=s.created_by
             WHERE s.branch_id=? AND s.status='completed'
             ORDER BY s.id DESC LIMIT 5",
            [(int)$activeBranch['id']]
        )->fetchAll();
    }
} catch (Throwable $e) {
    $recentSales = [];
    $posReady = false;
    $posMissing = ['database schema check'];
}

function pos_payment_label(string $method): string {
    return match($method){'gcash'=>'GCash','maya'=>'Maya','card'=>'Card','bank_transfer'=>'Bank Transfer',default=>'Cash'};
}
?>
<section class="page-heading pos-heading">
    <div>
        <span class="eyebrow">POINT OF SALE</span>
        <h1>New Sale</h1>
        <p><?= $activeBranch ? 'Process brand-new device and accessory sales for '.e($activeBranch['name']).'.' : 'Select a branch to start a sale.' ?></p>
    </div>
    <div class="pos-heading-badges"><span class="status-pill available">Brand New Sales</span><?php if($activeBranch): ?><span class="branch-chip compact"><?= icon('branch') ?><span><?= e($activeBranch['name']) ?></span></span><?php endif; ?></div>
</section>

<?php if(!$canSell): ?>
<div class="card pos-blocked-state"><div class="empty-icon"><?= icon('shield') ?></div><strong>POS access is not enabled for this account</strong><span>Owner, Branch Manager and Cashier accounts can process sales.</span></div>
<?php elseif(Auth::isOwner() && !$activeBranch): ?>
<section class="card pos-branch-picker">
    <div class="pos-branch-picker-copy"><span class="eyebrow">CHOOSE SELLING BRANCH</span><h2>Where is this sale happening?</h2><p>Inventory is deducted only from the selected branch. You can switch branches anytime from the top bar.</p></div>
    <div class="pos-branch-grid"><?php foreach($branches as $branch): ?><a class="pos-branch-card" href="index.php?page=pos&branch=<?= (int)$branch['id'] ?>"><div class="pos-branch-icon"><?= icon('branch') ?></div><div><strong><?= e($branch['name']) ?></strong><span>Open POS</span></div><b>→</b></a><?php endforeach; ?></div>
</section>
<?php elseif(!$activeBranch): ?>
<div class="card pos-blocked-state"><div class="empty-icon"><?= icon('branch') ?></div><strong>No active selling branch is assigned</strong><span>Ask the Owner/System Administrator to assign this account to an active branch.</span></div>
<?php elseif(!$posReady): ?>
<section class="card pos-setup-state">
    <div class="empty-icon"><?= icon('alert') ?></div>
    <div>
        <span class="eyebrow">POS SETUP REQUIRED</span>
        <h2>Point of Sale database setup is incomplete</h2>
        <?php if(Auth::isOwner()): ?>
            <p>Run <code>database/P2_001_brand_new_pos.sql</code> then <code>database/P2_004_pricing_variant_serial_ux.sql</code> in phpMyAdmin. This preserves the current database and adds the required POS tables/fields.</p>
            <small>Missing: <?= e(implode(', ', $posMissing)) ?></small>
        <?php else: ?>
            <p>Please contact the Owner/System Administrator to finish the POS database setup before processing sales.</p>
        <?php endif; ?>
    </div>
</section>
<?php else: ?>

<?php if($saleSuccess): ?>
<div class="pos-sale-success" data-sale-complete="1">
    <div class="pos-success-icon">✓</div>
    <div><span class="eyebrow">SALE COMPLETED</span><strong><?= e($saleSuccess['sale_no'] ?? 'Sale completed') ?></strong><small><?= e($saleSuccess['branch'] ?? '') ?> • <?= e($saleSuccess['payment'] ?? '') ?><?= !empty($saleSuccess['change']) ? ' • Change '.peso($saleSuccess['change']) : '' ?></small></div>
    <div class="pos-success-total"><span>Total</span><strong><?= peso($saleSuccess['total'] ?? 0) ?></strong></div>
</div>
<?php endif; ?>

<div class="pos-shell" data-pos-root data-branch-id="<?= (int)$activeBranch['id'] ?>" data-search-url="actions/pos_search.php" data-cart-storage="malbcoff_pos_cart_<?= (int)$activeBranch['id'] ?>">
    <section class="pos-catalog-column">
        <div class="card pos-search-card">
            <div class="pos-section-heading"><div><span class="eyebrow">ADD TO CART</span><h2>Find an item</h2><p>Scan an IMEI, Serial Number or barcode, or search by brand and model.</p></div><div class="pos-search-hint"><?= icon('barcode') ?><span>Scanner ready</span></div></div>
            <label class="pos-search-input"><?= icon('search') ?><input type="search" autocomplete="off" data-pos-search placeholder="Scan or search product…"><kbd>Enter</kbd></label>
            <button class="btn btn-outline shared-scan-launch" type="button" data-device-scan data-scan-target="[data-pos-search]" data-scan-mode="auto" data-scan-label="Scan Item">Scan with Camera</button>
            <div class="pos-search-status" data-pos-search-status>Start typing or scan an item to search this branch.</div>
            <div class="pos-results" data-pos-results></div>
        </div>

        <div class="card pos-recent-card">
            <div class="pos-section-heading compact"><div><span class="eyebrow">RECENT ACTIVITY</span><h2>Recent Sales</h2></div><?php if(Auth::isOwner() || in_array($role, ['branch_manager','cashier'], true)): ?><a class="btn btn-ghost btn-sm" href="index.php?page=sales-records<?= Auth::isOwner() && $activeBranch ? '&branch='.(int)$activeBranch['id'] : '' ?>">View All</a><?php else: ?><span class="mini-chip"><?= count($recentSales) ?> shown</span><?php endif; ?></div>
            <?php if(!$recentSales): ?><div class="pos-recent-empty">No completed sales yet for this branch.</div><?php else: ?><div class="pos-recent-list"><?php foreach($recentSales as $sale): ?><div class="pos-recent-row"><div class="pos-recent-icon"><?= icon('receipt') ?></div><div><strong><?= e($sale['sale_no']) ?></strong><span><?= e(date('M d, Y • h:i A', strtotime($sale['created_at']))) ?> • <?= e($sale['cashier_name']) ?></span></div><div><strong><?= peso($sale['total']) ?></strong><span><?= e(pos_payment_label($sale['payment_method'])) ?></span></div></div><?php endforeach; ?></div><?php endif; ?>
        </div>
    </section>

    <aside class="card pos-checkout-card">
        <div class="pos-checkout-header"><div><span class="eyebrow">CURRENT SALE</span><h2>Cart</h2></div><div class="pos-cart-header-actions"><button type="button" class="pos-clear-cart" data-clear-cart disabled>Clear</button><span class="pos-cart-count" data-cart-count>0 items</span></div></div>
        <div class="pos-cart-lines" data-cart-lines>
            <div class="pos-cart-empty" data-cart-empty><div class="empty-icon"><?= icon('cart') ?></div><strong>Your cart is empty</strong><span>Search or scan a product to start the sale.</span></div>
        </div>

        <form method="post" action="actions/complete_sale.php" data-pos-checkout>
            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="branch_id" value="<?= (int)$activeBranch['id'] ?>">
            <input type="hidden" name="cart_json" value="[]" data-cart-json>

            <div class="pos-summary">
                <div><span>Subtotal</span><strong data-subtotal><?= peso(0) ?></strong></div>
                <div class="pos-total-row"><span>Total</span><strong data-total><?= peso(0) ?></strong></div>
            </div>

            <div class="pos-payment-section">
                <div class="pos-payment-title"><strong>Payment</strong><span>Select how the customer paid.</span></div>
                <div class="payment-method-grid" role="radiogroup" aria-label="Payment method">
                    <?php foreach(['cash'=>'Cash','gcash'=>'GCash','maya'=>'Maya','card'=>'Card','bank_transfer'=>'Bank'] as $value=>$label): ?>
                    <label class="payment-method <?= $value==='cash'?'active':'' ?>"><input type="radio" name="payment_method" value="<?= e($value) ?>" <?= $value==='cash'?'checked':'' ?>><span class="payment-method-icon"><?= icon($value==='cash'?'cash':'receipt') ?></span><span><?= e($label) ?></span></label>
                    <?php endforeach; ?>
                </div>
                <div class="pos-payment-fields">
                    <label class="field" data-cash-field><span>Cash Received <b>*</b></span><div class="money-input"><span>₱</span><input type="number" name="amount_received" step="0.01" min="0" inputmode="decimal" data-cash-received placeholder="0.00"></div></label>
                    <div class="pos-change-box" data-change-box><span>Change</span><strong data-change><?= peso(0) ?></strong></div>
                    <label class="field hidden" data-reference-field><span>Payment Reference <small>Optional</small></span><input type="text" name="payment_reference" maxlength="120" placeholder="Reference / transaction no."></label>
                </div>
            </div>

            <button class="btn btn-primary pos-complete-btn" type="submit" data-complete-sale disabled><?= icon('receipt') ?><span>Complete Sale</span><strong data-complete-total><?= peso(0) ?></strong></button>
            <p class="pos-checkout-note">Completing the sale automatically marks serialized units as Sold and records Stock Out in Stock Movement.</p>
        </form>
    </aside>
</div>

<div class="modal" id="posConfirmModal" hidden>
    <div class="modal-backdrop" data-pos-confirm-close></div>
    <div class="modal-dialog pos-confirm-dialog">
        <div class="modal-header"><div><span class="eyebrow">CONFIRM SALE</span><h2>Complete this transaction?</h2><p class="modal-subtitle">Stock will be deducted immediately after confirmation.</p></div><button type="button" class="icon-button" data-pos-confirm-close>×</button></div>
        <div class="pos-confirm-body"><div class="pos-confirm-row"><span>Branch</span><strong><?= e($activeBranch['name']) ?></strong></div><div class="pos-confirm-row"><span>Items</span><strong data-confirm-items>0</strong></div><div class="pos-confirm-row"><span>Payment</span><strong data-confirm-payment>Cash</strong></div><div class="pos-confirm-row total"><span>Total</span><strong data-confirm-total><?= peso(0) ?></strong></div></div>
        <div class="pos-confirm-actions"><button class="btn btn-secondary" type="button" data-pos-confirm-close>Review Cart</button><button class="btn btn-primary" type="button" data-pos-confirm-submit><?= icon('receipt') ?> Confirm & Complete Sale</button></div>
    </div>
</div>
<?php endif; ?>
