<?php
$navItems = [
    ['dashboard', 'dashboard', 'Dashboard'],
];

// Owner is monitoring-focused: sales and inventory only for daily operations.
// POS, Products, and Receive Stock remain available to the branch-side operational roles.
if (!Auth::isOwner() && pos_role_allowed()) {
    $navItems[] = ['pos', 'pos', 'POS'];
}
if (Auth::isOwner() || in_array(Auth::user()['role'], ['branch_manager', 'cashier'], true)) {
    $navItems[] = ['sales-records', 'receipt', 'Sales Records'];
}
if (!Auth::isOwner()) {
    $navItems[] = ['products', 'products', 'Products'];
}
$navItems[] = ['inventory', 'inventory', 'Inventory'];
// Owner-only shortcut to the existing Product Archive view.
// The archive backend already enforces owner-only archive mode; this only improves navigation.
if (Auth::isOwner() || in_array((string)(Auth::user()['role'] ?? ''), ['branch_manager','inventory'], true)) {
    $navItems[] = ['branch-transfers', 'movement', 'Branch Transfers'];
}
if (!Auth::isOwner()) {
    $navItems[] = ['stock-in', 'stock', 'Receive Stock'];
}
if (Auth::isOwner() || in_array(Auth::user()['role'], ['branch_manager', 'inventory'], true)) {
    $navItems[] = ['stock-movement', 'movement', 'Stock Movement'];
}
if (Auth::isOwner() || Auth::user()['role'] === 'branch_manager') {
    $navItems[] = ['users', 'users', 'Users'];
}
?>
<?php
$sidebarUser = Auth::user();
$sidebarContext = Auth::isOwner() ? 'All Branches' : ($sidebarUser['branch_name'] ?? 'Assigned Branch');

$pendingTransferCount = 0;
if (!Auth::isOwner() && in_array((string)(Auth::user()['role'] ?? ''), ['branch_manager','inventory'], true) && (Auth::branchId() ?: 0) > 0) {
    try {
        if (Database::query("SHOW TABLES LIKE 'inventory_transfers'")->fetchColumn()) {
            $pendingTransferCount = (int)Database::query(
                "SELECT COUNT(*) FROM inventory_transfers WHERE destination_branch_id=? AND status='pending'",
                [Auth::branchId()]
            )->fetchColumn();
        }
    } catch (Throwable $e) {
        $pendingTransferCount = 0;
    }
}
?>
<aside class="sidebar" id="sidebar">

    <div class="sidebar-brand">
        <div class="brand-mark small">M</div>
        <div>
            <strong><?= e($app['name']) ?></strong>
            <span><?= e($app['subtitle']) ?></span>
        </div>
    </div>
    <nav class="sidebar-nav" aria-label="Main navigation">
        <?php foreach ($navItems as [$slug, $iconName, $label]): ?>
            <a href="index.php?page=<?= e($slug) ?>" class="nav-link <?= ($page === $slug || ($slug === 'products' && $page === 'add-item')) ? 'active' : '' ?>">
                <?= icon($iconName) ?><span><?= e($label) ?></span><?php if ($slug === 'branch-transfers' && $pendingTransferCount > 0): ?><b class="nav-count"><?= $pendingTransferCount > 99 ? '99+' : (int)$pendingTransferCount ?></b><?php endif; ?>
            </a>
        <?php endforeach; ?>
        <?php if (Auth::isOwner()): ?>
            <a href="index.php?page=products&amp;status=archived"
               class="nav-link <?= ($page === 'products' && (($_GET['status'] ?? '') === 'archived')) ? 'active' : '' ?>">
                <?= icon('archive') ?><span>Archive</span>
            </a>
        <?php endif; ?>
    </nav>
    <div class="sidebar-footer">
        <div class="sidebar-context">
            <?= icon('branch') ?>
            <div><strong><?= e($sidebarContext) ?></strong><span><?= e(role_label($sidebarUser['role'] ?? '')) ?></span></div>
        </div>
        <a class="logout-link" href="logout.php"><?= icon('logout') ?><span>Sign Out</span></a>
    </div>
</aside>
<button class="sidebar-scrim" type="button" data-sidebar-close aria-label="Close navigation"></button>
