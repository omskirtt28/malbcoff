<?php
require __DIR__ . '/../bootstrap.php';

if (!Auth::check()) redirect(app_url('login'));
if (!Auth::isSystemAdmin() || Auth::isImpersonating()) {
    flash('error', 'Return to the System Admin account to permanently clean deleted records.');
    redirect(app_url('system-admin-dashboard'));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(app_url('system-admin-deleted-records'));
if (!Csrf::verify($_POST['_csrf'] ?? null)) {
    flash('error', 'Your session expired. Refresh the page and try again.');
    redirect(app_url('system-admin-deleted-records'));
}

$action = (string)($_POST['action'] ?? '');
$pdo = Database::connection();

try {
    $pdo->beginTransaction();

    if ($action === 'purge_product') {
        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        if ($productId <= 0) throw new RuntimeException('Deleted product record not found.');

        $hasCatalogDeletedAt = (bool)Database::query("SHOW COLUMNS FROM products LIKE 'catalog_deleted_at'")->fetch();
        $product = Database::query(
            "SELECT p.id,p.product_type,p.product_name,p.model_id,p.is_active" . ($hasCatalogDeletedAt ? ",p.catalog_deleted_at" : "") . ",
                    b.name AS brand_name,pm.name AS model_name,p.ram,p.storage,p.connectivity,p.color
             FROM products p
             LEFT JOIN brands b ON b.id=p.brand_id
             LEFT JOIN product_models pm ON pm.id=p.model_id
             WHERE p.id=? LIMIT 1 FOR UPDATE",
            [$productId]
        )->fetch();
        if (!$product) throw new RuntimeException('This deleted product record no longer exists.');
        $isDeletedCandidate = !(int)$product['is_active'] || ($hasCatalogDeletedAt && !empty($product['catalog_deleted_at']));
        if (!$isDeletedCandidate) throw new RuntimeException('Only deleted or archived product records can be permanently purged here.');

        $unitCount = (int)Database::query('SELECT COUNT(*) FROM inventory_units WHERE product_id=?', [$productId])->fetchColumn();
        $saleCount = (int)Database::query('SELECT COUNT(*) FROM sale_items WHERE product_id=?', [$productId])->fetchColumn();
        $transferCount = (int)Database::query('SELECT COUNT(*) FROM inventory_transfers WHERE product_id=?', [$productId])->fetchColumn();
        $balanceQty = (int)Database::query('SELECT COALESCE(SUM(quantity),0) FROM inventory_balances WHERE product_id=?', [$productId])->fetchColumn();
        $movementCount = (int)Database::query('SELECT COUNT(*) FROM stock_movements WHERE product_id=?', [$productId])->fetchColumn();

        if ($unitCount > 0) throw new RuntimeException('This record still has inventory units. Remove the incorrect units from Inventory first.');
        if ($balanceQty !== 0) throw new RuntimeException('This record still has inventory quantity. Adjust the quantity to zero before permanent cleanup.');
        if ($saleCount > 0) throw new RuntimeException('This record has completed sales history and cannot be permanently purged.');
        if ($transferCount > 0) throw new RuntimeException('This record still has transfer history and cannot be permanently purged.');

        // At this point no physical stock, sale, or transfer depends on the record.
        // Remove non-protected operational leftovers so the incorrect product ID no
        // longer exists anywhere that can cause duplicate/variant conflicts.
        Database::query('DELETE FROM stock_movements WHERE product_id=?', [$productId]);
        Database::query('DELETE FROM inventory_balances WHERE product_id=?', [$productId]);
        if (Database::query("SHOW TABLES LIKE 'branch_product_prices'")->fetchColumn()) {
            Database::query('DELETE FROM branch_product_prices WHERE product_id=?', [$productId]);
        }
        Database::query('DELETE FROM products WHERE id=?', [$productId]);

        $pdo->commit();
        Security::audit('catalog.deleted_record_purged', 'product', $productId, [
            'product_type' => $product['product_type'] ?? null,
            'product_name' => $product['product_name'] ?? null,
            'brand_name' => $product['brand_name'] ?? null,
            'model_name' => $product['model_name'] ?? null,
            'specs' => [
                'ram' => $product['ram'] ?? null,
                'storage' => $product['storage'] ?? null,
                'connectivity' => $product['connectivity'] ?? null,
                'color' => $product['color'] ?? null,
            ],
            'removed_non_protected_movements' => $movementCount,
            'system_admin_user_id' => (int)(Auth::user()['id'] ?? 0),
        ]);
        flash('success', 'Deleted product/variant record permanently removed from the database.');
        redirect(app_url('system-admin-deleted-records'));
    }

    if ($action === 'purge_model') {
        $modelId = filter_var($_POST['model_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        if ($modelId <= 0) throw new RuntimeException('Archived model not found.');
        $model = Database::query(
            'SELECT pm.id,pm.name,pm.device_type,pm.is_active,b.name AS brand_name FROM product_models pm JOIN brands b ON b.id=pm.brand_id WHERE pm.id=? LIMIT 1 FOR UPDATE',
            [$modelId]
        )->fetch();
        if (!$model) throw new RuntimeException('This archived model no longer exists.');
        if ((int)$model['is_active'] === 1) throw new RuntimeException('Only archived models can be permanently deleted here.');
        $productCount = (int)Database::query('SELECT COUNT(*) FROM products WHERE model_id=?', [$modelId])->fetchColumn();
        if ($productCount > 0) throw new RuntimeException('Clean all deleted variants under this model before deleting the model itself.');

        Database::query('DELETE FROM product_models WHERE id=?', [$modelId]);
        $pdo->commit();
        Security::audit('catalog.archived_model_purged', 'product_model', $modelId, [
            'brand_name' => $model['brand_name'] ?? null,
            'model_name' => $model['name'] ?? null,
            'device_type' => $model['device_type'] ?? null,
            'system_admin_user_id' => (int)(Auth::user()['id'] ?? 0),
        ]);
        flash('success', 'Unused archived model permanently deleted from the database.');
        redirect(app_url('system-admin-deleted-records'));
    }

    throw new RuntimeException('Invalid deleted-record cleanup request.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    Security::reportException($e, 'system_admin_deleted_records_action');
    flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'Unable to permanently clean this record right now.');
    redirect(app_url('system-admin-deleted-records'));
}
