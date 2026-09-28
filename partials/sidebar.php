<?php
$sidebarUser = Auth::user();
$isSystemAdminControl = Auth::isSystemAdmin() && !Auth::isImpersonating();

if ($isSystemAdminControl) {
    $navItems = [
        ['system-admin-dashboard', 'dashboard', 'Dashboard'],
        ['system-admin-users', 'users', 'User Accounts'],
        ['system-admin-history', 'movement', 'Impersonation History'],
        ['security-logs', 'shield', 'Security Logs'],
    ];
    $sidebarContext = 'System Control';
    $pendingTransferCount = 0;
} else {
    $navItems = [
        ['dashboard', 'dashboard', 'Dashboard'],
    ];
    if (!Auth::isOwner() && pos_role_allowed()) $navItems[] = ['pos', 'pos', 'POS'];
    if (Auth::isOwner() || in_array($sidebarUser['role'], ['branch_manager', 'cashier'], true)) $navItems[] = ['sales-records', 'receipt', 'Sales Records'];
    if (!Auth::isOwner()) $navItems[] = ['products', 'products', 'Products'];
    $navItems[] = ['inventory', 'inventory', 'Inventory'];
    if (Auth::isOwner() || in_array((string)($sidebarUser['role'] ?? ''), ['branch_manager','inventory'], true)) $navItems[] = ['branch-transfers', 'movement', 'Branch Transfers'];
    if (!Auth::isOwner() && in_array((string)($sidebarUser['role'] ?? ''), ['branch_manager','inventory'], true)) $navItems[] = ['stock-in', 'stock', 'Receive Stock'];
    if (Auth::isOwner() || in_array($sidebarUser['role'], ['branch_manager', 'inventory'], true)) $navItems[] = ['stock-movement', 'movement', 'Stock Movement'];
    if (Auth::isOwner() || ($sidebarUser['role'] ?? '') === 'branch_manager') $navItems[] = ['users', 'users', 'Users'];

    $sidebarContext = Auth::isOwner() ? 'All Branches' : ($sidebarUser['branch_name'] ?? 'Assigned Branch');
    $pendingTransferCount = 0;
    if (!Auth::isOwner() && in_array((string)($sidebarUser['role'] ?? ''), ['branch_manager','inventory'], true) && (Auth::branchId() ?: 0) > 0) {
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
}
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-mark small">M</div>
        <div><strong><?= e($app['name']) ?></strong><span><?= e($isSystemAdminControl ? 'System Administration' : $app['subtitle']) ?></span></div>
    </div>
    <nav class="sidebar-nav" aria-label="Main navigation">
        <?php foreach ($navItems as [$slug, $iconName, $label]): ?>
            <a href="index.php?page=<?= e($slug) ?>" class="nav-link <?= ($page === $slug || ($slug === 'products' && $page === 'add-item')) ? 'active' : '' ?>">
                <?= icon($iconName) ?><span><?= e($label) ?></span><?php if ($slug === 'branch-transfers' && $pendingTransferCount > 0): ?><b class="nav-count"><?= $pendingTransferCount > 99 ? '99+' : (int)$pendingTransferCount ?></b><?php endif; ?>
            </a>
        <?php endforeach; ?>
        <?php if (!$isSystemAdminControl && Auth::isOwner()): ?>
            <a href="index.php?page=products&amp;status=archived" class="nav-link <?= ($page === 'products' && (($_GET['status'] ?? '') === 'archived')) ? 'active' : '' ?>">
                <?= icon('archive') ?><span>Archive</span>
            </a>
        <?php endif; ?>
    </nav>
    <div class="sidebar-footer">
        <div class="sidebar-context">
            <?= icon($isSystemAdminControl ? 'shield' : 'branch') ?>
            <div><strong><?= e($sidebarContext) ?></strong><span><?= e(role_label($sidebarUser['role'] ?? '')) ?></span></div>
        </div>
        <a class="logout-link" href="logout.php"><?= icon('logout') ?><span>Sign Out</span></a>
    </div>
</aside>
<button class="sidebar-scrim" type="button" data-sidebar-close aria-label="Close navigation"></button>
