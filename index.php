<?php
require __DIR__ . '/bootstrap.php';
if (!Auth::check()) {
    redirect('login.php');
}

$allowedPages = [
    'dashboard' => 'Dashboard',
    'pos' => 'Point of Sale',
    'sales-records' => 'Sales Records',
    'products' => 'Products',
    'add-item' => 'Add New Item',
    'inventory' => 'Inventory',
    'branch-transfers' => 'Branch Transfers',
    'stock-in' => 'Stock In',
    'stock-movement' => 'Stock Movement',
    'users' => 'Users',
];
$page = $_GET['page'] ?? 'dashboard';
if (!isset($allowedPages[$page])) {
    $page = 'dashboard';
}


if ($page === 'pos' && !pos_role_allowed()) {
    $page = 'dashboard';
    flash('error', 'You do not have access to Point of Sale.');
}

if ($page === 'sales-records' && !Auth::isOwner() && !in_array(Auth::user()['role'], ['branch_manager', 'cashier'], true)) {
    $page = 'dashboard';
    flash('error', 'You do not have access to Sales Records.');
}

if ($page === 'branch-transfers' && !Auth::isOwner() && !in_array((string)(Auth::user()['role'] ?? ''), ['branch_manager','inventory'], true)) {
    $page = 'dashboard';
    flash('error', 'You do not have access to Branch Transfers.');
}

if ($page === 'stock-movement' && !Auth::isOwner() && !in_array(Auth::user()['role'], ['branch_manager', 'inventory'], true)) {
    $page = 'dashboard';
    flash('error', 'You do not have access to Stock Movement.');
}

$pageTitle = $allowedPages[$page];
require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/sidebar.php';
?>
<main class="app-main">
    <?php require __DIR__ . '/partials/topbar.php'; ?>
    <div class="page-content">
        <?php if ($message = flash('success')): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
        <?php if ($message = flash('error')): ?><div class="alert alert-error"><?= e($message) ?></div><?php endif; ?>
        <?php require __DIR__ . '/pages/' . $page . '.php'; ?>
    </div>
</main>
<?php require __DIR__ . '/partials/mobile-nav.php'; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
