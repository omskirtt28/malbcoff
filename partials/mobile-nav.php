<?php
$mobileUser = Auth::user();
$mobileItems = [
    ['dashboard', 'dashboard', 'Home'],
];
if (!Auth::isOwner() && pos_role_allowed()) {
    $mobileItems[] = ['pos', 'pos', 'POS'];
}
if (Auth::isOwner() || in_array($mobileUser['role'], ['branch_manager', 'cashier'], true)) {
    $mobileItems[] = ['sales-records', 'receipt', 'Sales'];
}
if (!Auth::isOwner()) {
    $mobileItems[] = ['products', 'products', 'Products'];
}
$mobileItems[] = ['inventory', 'inventory', 'Inventory'];
if (!Auth::isOwner()) {
    $mobileItems[] = ['stock-in', 'stock', 'Receive Stock'];
}
if (Auth::isOwner() || in_array($mobileUser['role'], ['branch_manager', 'inventory'], true)) {
    $mobileItems[] = ['stock-movement', 'movement', 'Stock Movement'];
}
if (Auth::isOwner() || $mobileUser['role'] === 'branch_manager') {
    $mobileItems[] = ['users', 'users', 'Users'];
}

// Keep the bottom navigation intentionally short. Remaining destinations live in More.
$primarySlugs = Auth::isOwner()
    ? ['dashboard', 'sales-records', 'inventory']
    : (pos_role_allowed() ? ['dashboard', 'pos', 'sales-records', 'products'] : ['dashboard', 'products', 'inventory', 'stock-in']);
$primary = [];
$secondary = [];
foreach ($mobileItems as $item) {
    if (in_array($item[0], $primarySlugs, true)) $primary[] = $item;
    else $secondary[] = $item;
}
$mobileContext = Auth::isOwner() ? 'Owner View' : ($mobileUser['branch_name'] ?? 'Assigned Branch');
?>
<nav class="mobile-bottom-nav" aria-label="Mobile primary navigation">
    <?php foreach ($primary as [$slug, $iconName, $label]): ?>
        <?php $active = $page === $slug || ($slug === 'products' && $page === 'add-item'); ?>
        <a href="index.php?page=<?= e($slug) ?>" class="mobile-nav-item <?= $active ? 'active' : '' ?>">
            <?= icon($iconName) ?><span><?= e($label) ?></span>
        </a>
    <?php endforeach; ?>
    <button type="button" class="mobile-nav-item <?= in_array($page, array_column($secondary, 0), true) ? 'active' : '' ?>" data-mobile-more-open>
        <?= icon('more') ?><span>More</span>
    </button>
</nav>

<div class="mobile-more-sheet" data-mobile-more hidden>
    <button class="mobile-more-backdrop" type="button" data-mobile-more-close aria-label="Close menu"></button>
    <section class="mobile-more-panel" role="dialog" aria-modal="true" aria-label="More navigation">
        <header>
            <div><span class="eyebrow">NAVIGATION</span><strong>More</strong><small><?= e($mobileContext) ?></small></div>
            <button class="icon-button" type="button" data-mobile-more-close aria-label="Close menu"><?= icon('close') ?></button>
        </header>
        <div class="mobile-more-links">
            <?php foreach ($secondary as [$slug, $iconName, $label]): ?>
                <?php $active = $page === $slug || ($slug === 'products' && $page === 'add-item'); ?>
                <a href="index.php?page=<?= e($slug) ?>" class="<?= $active ? 'active' : '' ?>"><?= icon($iconName) ?><span><?= e($label) ?></span><?= icon('chevron') ?></a>
            <?php endforeach; ?>
        </div>
        <a class="mobile-more-signout" href="logout.php"><?= icon('logout') ?><span>Sign Out</span></a>
    </section>
</div>
