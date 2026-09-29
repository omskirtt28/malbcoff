<?php
if (!Auth::isSystemAdmin() || Auth::isImpersonating()) {
    http_response_code(403);
    echo '<section class="card"><div class="empty-state"><strong>Access denied.</strong><span>Return to the System Admin account to use Data Reset.</span></div></section>';
    return;
}

$counts = [
    'product_models' => 0,
    'products' => 0,
    'inventory_units' => 0,
    'sales' => 0,
    'transfers' => 0,
    'movements' => 0,
];

try {
    $counts['product_models'] = (int)Database::query('SELECT COUNT(*) FROM product_models')->fetchColumn();
    $counts['products'] = (int)Database::query('SELECT COUNT(*) FROM products')->fetchColumn();
    $counts['inventory_units'] = (int)Database::query('SELECT COUNT(*) FROM inventory_units')->fetchColumn();
    $counts['sales'] = (int)Database::query('SELECT COUNT(*) FROM sales')->fetchColumn();
    $counts['transfers'] = (int)Database::query('SELECT COUNT(*) FROM inventory_transfers')->fetchColumn();
    $counts['movements'] = (int)Database::query('SELECT COUNT(*) FROM stock_movements')->fetchColumn();
} catch (Throwable $e) {
    Security::reportException($e, 'system_admin_data_reset_counts');
}

$businessRows = array_sum($counts);
?>
<section class="page-heading data-reset-heading">
    <div>
        <span class="eyebrow">SYSTEM CONTROL</span>
        <h1>Data Reset</h1>
        <p>Remove test products, inventory, sales, transfers, and stock activity before real production encoding begins.</p>
    </div>
</section>

<div class="data-reset-summary-grid">
    <article class="card data-reset-stat"><span>Product Models</span><strong><?= number_format($counts['product_models']) ?></strong><small>Model master records</small></article>
    <article class="card data-reset-stat"><span>Product Variants</span><strong><?= number_format($counts['products']) ?></strong><small>Including archived products</small></article>
    <article class="card data-reset-stat"><span>Inventory Units</span><strong><?= number_format($counts['inventory_units']) ?></strong><small>IMEI / serial / barcode units</small></article>
    <article class="card data-reset-stat"><span>Sales</span><strong><?= number_format($counts['sales']) ?></strong><small>Completed/test POS records</small></article>
    <article class="card data-reset-stat"><span>Transfers</span><strong><?= number_format($counts['transfers']) ?></strong><small>All branch transfer records</small></article>
    <article class="card data-reset-stat"><span>Stock Movements</span><strong><?= number_format($counts['movements']) ?></strong><small>Stock monitoring history</small></article>
</div>

<section class="card data-reset-scope-card">
    <div class="card-header">
        <div>
            <h2>Production Cleanup Scope</h2>
            <p>This reset clears operational test data while preserving the system structure needed for tomorrow's real encoding.</p>
        </div>
        <span class="data-reset-count"><?= number_format($businessRows) ?> tracked records</span>
    </div>
    <div class="data-reset-scope-grid">
        <div class="data-reset-scope danger-scope">
            <div class="data-reset-scope-icon"><?= icon('trash') ?></div>
            <div>
                <strong>Will be deleted</strong>
                <span>Product models and variants</span>
                <span>Inventory units and balances</span>
                <span>Branch product prices</span>
                <span>Stock movements</span>
                <span>Branch transfers and transfer units</span>
                <span>POS sales and sale items</span>
            </div>
        </div>
        <div class="data-reset-scope preserve-scope">
            <div class="data-reset-scope-icon"><?= icon('shield') ?></div>
            <div>
                <strong>Will be preserved</strong>
                <span>System Admin, Owner, and Branch user accounts</span>
                <span>Branches</span>
                <span>Brand master list</span>
                <span>Accessory categories</span>
                <span>Security / impersonation audit history</span>
                <span>Database schema and migrations</span>
            </div>
        </div>
    </div>
</section>

<section class="card data-reset-danger-card">
    <div class="data-reset-danger-head">
        <div class="data-reset-danger-icon"><?= icon('alert') ?></div>
        <div>
            <span class="eyebrow danger-eyebrow">DANGER ZONE</span>
            <h2>Reset All Operational Test Data</h2>
            <p>This operation is irreversible from the system. Use it only before staff start entering real products and inventory.</p>
        </div>
    </div>

    <form method="post" action="actions/system_admin_data_reset.php" class="data-reset-form" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">

        <label class="data-reset-check">
            <input type="checkbox" name="backup_confirm" value="1" required>
            <span><strong>I have a recent database backup.</strong><small>The reset cannot restore deleted test data.</small></span>
        </label>

        <div class="data-reset-form-grid">
            <label class="field">
                <span>Confirmation phrase</span>
                <input type="text" name="confirmation_phrase" placeholder="Type RESET TEST DATA" required spellcheck="false" autocapitalize="characters">
                <small>Type exactly: <b>RESET TEST DATA</b></small>
            </label>
            <label class="field">
                <span>Current System Admin password</span>
                <input type="password" name="current_password" placeholder="Enter your current password" required autocomplete="current-password">
                <small>Your password is verified before any deletion starts.</small>
            </label>
        </div>

        <div class="data-reset-submit-row">
            <div>
                <strong>Accounts and branches are not deleted.</strong>
                <span>Only operational/product test data listed above will be cleared.</span>
            </div>
            <button type="submit" class="btn btn-danger" data-confirm="Permanently delete ALL operational test data across every branch?">
                <?= icon('trash') ?> Reset Test Data
            </button>
        </div>
    </form>
</section>
