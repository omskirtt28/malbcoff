<?php
require __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

if (!Auth::check() || !Auth::isSystemAdmin() || Auth::isImpersonating()) {
    Security::audit('system_admin.data_reset_denied', 'system', 'business_data', ['reason' => 'invalid_role_or_impersonation']);
    http_response_code(403);
    exit('Forbidden');
}

if (!Csrf::verify($_POST['_csrf'] ?? null)) {
    Security::audit('system_admin.data_reset_denied', 'system', 'business_data', ['reason' => 'csrf']);
    flash('error', 'Your session verification expired. Please try again.');
    redirect(app_url('system-admin-data-reset'));
}

$phrase = trim((string)($_POST['confirmation_phrase'] ?? ''));
$password = (string)($_POST['current_password'] ?? '');
$backupConfirmed = (string)($_POST['backup_confirm'] ?? '') === '1';

if (!$backupConfirmed) {
    flash('error', 'Confirm that you have a recent database backup before resetting data.');
    redirect(app_url('system-admin-data-reset'));
}

if ($phrase !== 'RESET TEST DATA') {
    flash('error', 'The confirmation phrase does not match. Type RESET TEST DATA exactly.');
    redirect(app_url('system-admin-data-reset'));
}

$systemAdminId = (int)(Auth::user()['id'] ?? 0);
try {
    $admin = Database::query(
        "SELECT id,password_hash FROM users WHERE id=? AND role='system_admin' AND is_active=1 LIMIT 1",
        [$systemAdminId]
    )->fetch();
} catch (Throwable $e) {
    Security::reportException($e, 'system_admin_data_reset_password_lookup');
    flash('error', 'Unable to verify the System Admin account. No data was deleted.');
    redirect(app_url('system-admin-data-reset'));
}

if (!$admin || !password_verify($password, (string)$admin['password_hash'])) {
    Security::audit('system_admin.data_reset_denied', 'system', 'business_data', ['reason' => 'password_verification_failed']);
    flash('error', 'Incorrect System Admin password. No data was deleted.');
    redirect(app_url('system-admin-data-reset'));
}

$tables = [
    'inventory_transfer_units',
    'sale_items',
    'stock_movements',
    'inventory_transfers',
    'sales',
    'inventory_balances',
    'branch_product_prices',
    'inventory_units',
    'products',
    'product_models',
];

$counts = [];
if(inventory_void_schema_ready())array_unshift($tables,'inventory_movement_voids');
$pdo = Database::connection();

try {
    foreach ($tables as $table) {
        $counts[$table] = (int)Database::query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    }

    Security::audit('system_admin.data_reset_started', 'system', 'business_data', [
        'rows_before' => $counts,
        'preserved' => ['users','branches','brands','categories','security_audit_logs','security_impersonation_sessions','schema_migrations'],
    ]);

    $pdo->beginTransaction();
    foreach ($tables as $table) {
        Database::query("DELETE FROM `$table`");
    }
    $pdo->commit();

    Security::audit('system_admin.data_reset_completed', 'system', 'business_data', [
        'deleted_rows' => $counts,
        'preserved' => ['users','branches','brands','categories','security_audit_logs','security_impersonation_sessions','schema_migrations'],
    ]);

    Csrf::rotate();
    $deletedTotal = array_sum($counts);
    flash('success', 'Production cleanup completed. ' . number_format($deletedTotal) . ' operational test records were removed. User accounts, branches, brands, categories, and security history were preserved.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    Security::reportException($e, 'system_admin_data_reset');
    Security::audit('system_admin.data_reset_failed', 'system', 'business_data', ['error_class' => get_class($e)]);
    flash('error', 'Data reset failed and was rolled back. No partial cleanup was kept.');
}

redirect(app_url('system-admin-data-reset'));
