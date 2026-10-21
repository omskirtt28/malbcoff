<?php
require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../core/Idempotency.php';
require_once __DIR__ . '/../core/InventoryDeletion.php';

if (!Auth::check()) { if (Idempotency::wantsJson()) Idempotency::send(401, ['error' => 'Please sign in again.']); redirect(app_url('login')); }
if (!Auth::isSystemAdmin() || Auth::isImpersonating()) {
    if (Idempotency::wantsJson()) Idempotency::send(403, ['error' => 'System Admin access required.']);
    http_response_code(403);
    exit('System Admin access required.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { if (Idempotency::wantsJson()) Idempotency::send(405, ['error' => 'Use POST.']); redirect(app_url('system-admin-deleted-records')); }
$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) $payload = $_POST;
if (!Csrf::verify($payload['_csrf'] ?? null)) {
    if (Idempotency::wantsJson()) Idempotency::csrfFailure();
    flash('error', 'Your session expired. Refresh the page and try again.');
    redirect(app_url('system-admin-deleted-records'));
}

if (in_array($payload['action'] ?? '', ['purge_units','preview_no_sales','purge_all_no_sales'], true)) {
    $pdo = Database::connection();
    try {
        InventoryDeletion::requireReady();
        if (!InventoryDeletion::snapshotsReady()) throw new RuntimeException('Inventory cleanup needs the latest update. Ask the System Admin to install it.');
        $deleteAll = $payload['action'] === 'purge_all_no_sales';
        if ($payload['action'] === 'preview_no_sales') {
            $preview = InventoryDeletion::previewNoSales();
            unset($preview['ids']);
            Idempotency::send(200, ['success' => true] + $preview);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($payload['unit_ids'] ?? [])), static fn($id) => $id > 0)));
        if (!$deleteAll && (!$ids || count($ids) > 50)) throw new RuntimeException('Select between 1 and 50 deleted units on this page.');
        if (empty($payload['confirmed'])) throw new RuntimeException('Confirm the number of units before continuing.');
        if (($deleteAll || count($ids) > 1 || !empty($payload['bulk'])) && ($payload['confirmation'] ?? '') !== 'DELETE') throw new RuntimeException('Type DELETE to confirm the selected units.');
        $actorId = (int)Auth::user()['id'];
        $key = Idempotency::key($payload);
        $fingerprint = Idempotency::fingerprint($payload);
        Idempotency::replayJsonAndExit($key, 'inventory_purge', $actorId, $fingerprint);
        InventoryDeletion::requireReady();
        $pdo->beginTransaction();
        if ($key && !Idempotency::claim($key, 'inventory_purge', $actorId, $fingerprint)) {
            $pdo->rollBack();
            Idempotency::replayAfterLostClaim($key, 'inventory_purge', $actorId, $fingerprint);
        }
        if ($deleteAll) {
            $preview = InventoryDeletion::previewNoSales(true);
            if (!hash_equals($preview['preview_token'], (string)($payload['preview_token'] ?? '')) || $preview['count'] !== (int)($payload['preview_count'] ?? -1)) {
                throw new RuntimeException('The eligible units changed. Close this dialog and preview again. Nothing was deleted.');
            }
            $ids = $preview['ids'];
        }
        $result = ['success' => true] + InventoryDeletion::purge($ids, $actorId);
        if ($key) Idempotency::complete($key, $result);
        $pdo->commit();
        Idempotency::send(200, $result);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        Security::reportException($e, 'inventory_purge');
        $businessError = $e instanceof RuntimeException && !($e instanceof PDOException);
        Idempotency::send($businessError ? 422 : 500, ['error' => $businessError ? $e->getMessage() : 'Unable to clean these units right now.']);
    }
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
