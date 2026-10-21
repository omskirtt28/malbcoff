<?php
require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../core/Idempotency.php';
require_once __DIR__ . '/../core/InventoryDeletion.php';
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
    Idempotency::csrfFailure();
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

$actorId = Auth::actorId() ?: (int)(Auth::user()['id'] ?? 0);
$key = Idempotency::key($payload);
$fingerprint = Idempotency::fingerprint($payload);
Idempotency::replayJsonAndExit($key, 'inventory_soft_delete', $actorId, $fingerprint);
$pdo = Database::connection();
try {
    InventoryDeletion::requireReady();
    $pdo->beginTransaction();
    if ($key && !Idempotency::claim($key, 'inventory_soft_delete', $actorId, $fingerprint)) {
        $pdo->rollBack();
        Idempotency::replayAfterLostClaim($key, 'inventory_soft_delete', $actorId, $fingerprint);
    }

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
        throw new RuntimeException('Unit removal is only available for serialized phones and tablets. Use Adjust for accessory quantities.');
    }

    $branch = Database::query('SELECT id,name,code FROM branches WHERE id=? AND is_active=1 LIMIT 1 FOR UPDATE', [$branchId])->fetch();
    if (!$branch) throw new RuntimeException('Branch not found.');

    $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
    $params = array_merge([$productId, $branchId], $unitIds);
    $units = Database::query(
        "SELECT *
         FROM inventory_units
         WHERE product_id=? AND branch_id=? AND id IN ($placeholders)
         FOR UPDATE",
        $params
    )->fetchAll();

    if (count($units) !== count($unitIds)) {
        throw new RuntimeException('One or more selected units no longer belong to this product and branch. Refresh Inventory and try again.');
    }

    foreach ($units as $unit) {
        InventoryDeletion::softDelete($unit, $actorId, trim((string)($payload['reason'] ?? '')));
    }

    $remaining = (int)Database::query(
        "SELECT COUNT(*) FROM inventory_units WHERE product_id=? AND branch_id=? AND status='available'",
        [$productId, $branchId]
    )->fetchColumn();

    $result = ['success' => true, 'message' => count($unitIds).' inventory unit(s) removed. The System Admin can release their identifiers in Deleted Records.', 'deleted_count' => count($unitIds), 'branch_available' => $remaining];
    if ($key) Idempotency::complete($key, $result);
    $pdo->commit();
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $status = $e instanceof RuntimeException && !($e instanceof PDOException) ? 422 : 500;
    http_response_code($status);
    echo json_encode(['error' => $status === 500 ? 'Unable to delete the inventory unit right now.' : $e->getMessage()]);
}
