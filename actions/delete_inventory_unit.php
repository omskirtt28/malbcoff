<?php
require __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Please sign in again.']);
    exit;
}

$effectiveRole = (string)(Auth::user()['role'] ?? '');
$canDeleteInventory = Auth::actorIsSystemAdmin() || $effectiveRole === 'branch_manager';
if (!$canDeleteInventory) {
    Security::audit('inventory.delete_denied', 'inventory', null, ['reason' => 'branch_manager_or_system_admin_required']);
    http_response_code(403);
    echo json_encode(['error' => 'Only the Branch Manager or System Admin can delete incorrect available inventory units.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Invalid request method.']);
    exit;
}

$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) $payload = $_POST;

if (!Csrf::verify($payload['_csrf'] ?? null)) {
    http_response_code(419);
    echo json_encode(['error' => 'Your session expired. Refresh the page and try again.']);
    exit;
}

if (($payload['confirmed'] ?? false) !== true && (string)($payload['confirmed'] ?? '') !== '1') {
    http_response_code(422);
    echo json_encode(['error' => 'Review and confirm the inventory deletion before saving.']);
    exit;
}

$productId = filter_var($payload['product_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$branchId = filter_var($payload['branch_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$rawIds = is_array($payload['unit_ids'] ?? null) ? $payload['unit_ids'] : [];
$unitIds = array_values(array_unique(array_filter(array_map(static fn($value) => (int)$value, $rawIds), static fn($value) => $value > 0)));

if ($productId <= 0 || $branchId <= 0 || !$unitIds) {
    http_response_code(422);
    echo json_encode(['error' => 'Select at least one valid available inventory unit to delete.']);
    exit;
}

if (count($unitIds) > 50) {
    http_response_code(422);
    echo json_encode(['error' => 'Delete at most 50 incorrect units at a time.']);
    exit;
}

// Branch Managers are limited to their assigned branch. A System Admin who is
// impersonating a Branch Manager intentionally inherits that same branch scope.
if ($effectiveRole === 'branch_manager') {
    $targetBranchId = Auth::branchId() ?: 0;
    if ($targetBranchId <= 0 || $branchId !== $targetBranchId) {
        Security::audit('inventory.delete_denied', 'product', $productId, [
            'reason' => 'branch_scope',
            'requested_branch_id' => $branchId,
            'target_branch_id' => $targetBranchId,
        ]);
        http_response_code(403);
        echo json_encode(['error' => 'You can only delete incorrect inventory from your own branch.']);
        exit;
    }
}

$pdo = Database::connection();
try {
    $pdo->beginTransaction();

    $product = Database::query(
        "SELECT p.id,p.product_type,p.product_name,p.ram,p.storage,p.connectivity,p.color,
                b.name AS brand_name,pm.name AS model_name
         FROM products p
         LEFT JOIN brands b ON b.id=p.brand_id
         LEFT JOIN product_models pm ON pm.id=p.model_id
         WHERE p.id=? AND p.is_active=1
         LIMIT 1 FOR UPDATE",
        [$productId]
    )->fetch();
    if (!$product) throw new RuntimeException('Product not found or no longer active.');
    if (!in_array((string)$product['product_type'], ['phone','tablet'], true)) {
        throw new RuntimeException('Permanent unit deletion is only available for serialized phones and tablets. Use Adjust for accessory quantities.');
    }

    $branch = Database::query('SELECT id,name,code FROM branches WHERE id=? AND is_active=1 LIMIT 1 FOR UPDATE', [$branchId])->fetch();
    if (!$branch) throw new RuntimeException('Branch not found.');

    $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
    $params = array_merge([$productId, $branchId], $unitIds);
    $units = Database::query(
        "SELECT id,imei,imei2,serial_no,status,created_at
         FROM inventory_units
         WHERE product_id=? AND branch_id=? AND id IN ($placeholders)
         FOR UPDATE",
        $params
    )->fetchAll();

    if (count($units) !== count($unitIds)) {
        throw new RuntimeException('One or more selected units no longer belong to this product and branch. Refresh Inventory and try again.');
    }

    $snapshots = [];
    foreach ($units as $unit) {
        $unitId = (int)$unit['id'];
        if ((string)$unit['status'] !== 'available') {
            throw new RuntimeException('Only currently available units can be permanently deleted.');
        }

        if (Database::query('SELECT 1 FROM sale_items WHERE unit_id=? LIMIT 1', [$unitId])->fetchColumn()) {
            throw new RuntimeException('A selected unit already has sales history and cannot be permanently deleted.');
        }
        if (Database::query('SELECT 1 FROM inventory_transfer_units WHERE unit_id=? LIMIT 1', [$unitId])->fetchColumn()) {
            throw new RuntimeException('A selected unit already has transfer history and cannot be permanently deleted.');
        }

        $movements = Database::query(
            'SELECT id,movement_type,quantity,reference_no,notes,created_at FROM stock_movements WHERE unit_id=? ORDER BY id ASC FOR UPDATE',
            [$unitId]
        )->fetchAll();

        foreach ($movements as $movement) {
            $movementType = (string)$movement['movement_type'];
            $movementQty = (int)$movement['quantity'];
            $safeCorrection = $movementType === 'adjustment' && $movementQty === 0;
            $safeOriginalStockIn = $movementType === 'stock_in' && $movementQty > 0;
            if (!$safeCorrection && !$safeOriginalStockIn) {
                throw new RuntimeException('A selected unit already has stock, transfer, sale, return, or adjustment history that must be preserved. It cannot be permanently deleted.');
            }
        }

        $snapshots[] = [
            'unit_id' => $unitId,
            'imei' => $unit['imei'] ?: null,
            'imei2' => $unit['imei2'] ?: null,
            'serial_no' => $unit['serial_no'] ?: null,
            'received_at' => $unit['created_at'] ?? null,
            'movement_references' => array_values(array_filter(array_map(static fn($row) => $row['reference_no'] ?? null, $movements))),
        ];
    }

    // Remove only the movement rows that belong to these incorrect, never-used
    // units. This releases their IMEI/Serial unique values for correct receiving.
    Database::query("DELETE FROM stock_movements WHERE unit_id IN ($placeholders)", $unitIds);

    $deleteParams = array_merge([$productId, $branchId], $unitIds);
    $deleted = Database::query(
        "DELETE FROM inventory_units
         WHERE product_id=? AND branch_id=? AND status='available' AND id IN ($placeholders)",
        $deleteParams
    );
    if ($deleted->rowCount() !== count($unitIds)) {
        throw new RuntimeException('Inventory changed while the deletion was being saved. Refresh and try again.');
    }

    $remaining = (int)Database::query(
        "SELECT COUNT(*) FROM inventory_units WHERE product_id=? AND branch_id=? AND status='available'",
        [$productId, $branchId]
    )->fetchColumn();

    $pdo->commit();

    $actorId = Auth::actorId() ?: (int)(Auth::user()['id'] ?? 0);
    Security::audit('inventory.units_permanently_deleted', 'product', $productId, [
        'branch_id' => $branchId,
        'branch_name' => $branch['name'] ?? null,
        'deleted_count' => count($unitIds),
        'remaining_available' => $remaining,
        'deleted_units' => $snapshots,
        'actor_user_id' => $actorId,
        'effective_role' => $effectiveRole,
        'purpose' => 'incorrect_inventory_entry_cleanup',
    ]);

    $message = count($unitIds) === 1
        ? 'Incorrect inventory unit permanently deleted.'
        : count($unitIds) . ' incorrect inventory units permanently deleted.';
    flash('success', $message . ' The deleted IMEI/Serial can now be received under the correct variant.');

    echo json_encode([
        'success' => true,
        'message' => $message,
        'deleted_count' => count($unitIds),
        'branch_available' => $remaining,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $status = $e instanceof RuntimeException ? 422 : 500;
    http_response_code($status);
    echo json_encode(['error' => $status === 500 ? 'Unable to delete the inventory unit right now.' : $e->getMessage()]);
}
