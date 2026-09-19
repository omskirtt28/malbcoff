<?php
$navItems = [
    ['dashboard', 'dashboard', 'Dashboard'],
];
if (pos_role_allowed()) {
    $navItems[] = ['pos', 'pos', 'POS'];
}
$navItems = array_merge($navItems, [
    ['products', 'products', 'Products'],
    ['inventory', 'inventory', 'Inventory'],
    ['stock-in', 'stock', 'Receive Stock'],
]);
if (Auth::isOwner() || in_array(Auth::user()['role'], ['branch_manager', 'inventory'], true)) {
    $navItems[] = ['stock-movement', 'movement', 'Stock Movement'];
}
if (Auth::isOwner() || Auth::user()['role'] === 'branch_manager') {
    $navItems[] = ['users', 'users', 'Users'];
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
                <?= icon($iconName) ?><span><?= e($label) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
        <span class="phase-badge"><?= e(strtoupper($app['phase'] ?? 'Phase 2')) ?></span>
        <a class="logout-link" href="logout.php"><?= icon('logout') ?><span>Sign Out</span></a>
    </div>
</aside>
