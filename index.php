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
    'system-admin-dashboard' => 'System Admin',
    'system-admin-users' => 'User Accounts',
    'system-admin-history' => 'Impersonation History',
    'security-logs' => 'Security Logs',
];

$requestedPage = (string)($_GET['page'] ?? '');
if (Auth::isSystemAdmin() && !Auth::isImpersonating() && ($requestedPage === '' || $requestedPage === 'dashboard')) {
    $requestedPage = 'system-admin-dashboard';
}
$page = $requestedPage !== '' ? $requestedPage : 'dashboard';
if (!isset($allowedPages[$page])) {
    $page = Auth::isSystemAdmin() && !Auth::isImpersonating() ? 'system-admin-dashboard' : 'dashboard';
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
    'system-admin-dashboard' => ['system_admin'],
    'system-admin-users' => ['system_admin'],
    'system-admin-history' => ['system_admin'],
    'security-logs' => ['system_admin'],
];

if ($role === 'system_admin' && !Auth::isImpersonating() && !str_starts_with($page, 'system-admin') && $page !== 'security-logs') {
    Security::audit('auth.page_denied', 'page', $page, ['role' => $role, 'reason' => 'system_admin_control_scope']);
    $page = 'system-admin-dashboard';
    flash('error', 'Use Enter Account to access Owner or Branch workflows.');
}

if ($role === 'owner' && $page === 'products' && (($_GET['status'] ?? '') !== 'archived')) {
    Security::audit('auth.page_denied', 'page', $page, ['role' => $role, 'reason' => 'owner_monitoring_scope']);
    $page = 'dashboard';
    flash('error', 'Owner access is focused on monitoring. Use Archive for Product Master history.');
}

if (!in_array($role, $pageRoles[$page] ?? [], true)) {
    Security::audit('auth.page_denied', 'page', $page, ['role' => $role]);
    $page = $role === 'system_admin' ? 'system-admin-dashboard' : 'dashboard';
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
