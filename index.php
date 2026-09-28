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

$page = (string)($_GET['page'] ?? 'dashboard');
if (!isset($allowedPages[$page])) {
    $page = 'dashboard';
}

$role = (string)(Auth::user()['role'] ?? '');
$pageRoles = [
    'dashboard' => ['owner','branch_manager','cashier','inventory'],
    'pos' => ['branch_manager','cashier'],
    'sales-records' => ['owner','branch_manager','cashier'],
    'products' => ['owner','branch_manager','cashier','inventory'],
    'add-item' => ['branch_manager','inventory'],
    'inventory' => ['owner','branch_manager','cashier','inventory'],
    'branch-transfers' => ['owner','branch_manager','inventory'],
    'stock-in' => ['branch_manager','inventory'],
    'stock-movement' => ['owner','branch_manager','inventory'],
    'users' => ['owner','branch_manager'],
];

if ($role === 'owner' && $page === 'products' && (($_GET['status'] ?? '') !== 'archived')) {
    Security::audit('auth.page_denied', 'page', $page, ['role' => $role, 'reason' => 'owner_monitoring_scope']);
    $page = 'dashboard';
    flash('error', 'Owner access is focused on monitoring. Use Archive for Product Master history.');
}

if (!in_array($role, $pageRoles[$page] ?? [], true)) {
    Security::audit('auth.page_denied', 'page', $page, ['role' => $role]);
    $page = 'dashboard';
    flash('error', 'You do not have access to that page.');
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
