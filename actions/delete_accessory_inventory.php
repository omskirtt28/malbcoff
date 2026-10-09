<?php
require __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error'=>'Please sign in again.']);
    exit;
}
$role = (string)(Auth::user()['role'] ?? '');
if (!Auth::actorIsSystemAdmin() && $role !== 'branch_manager') {
    http_response_code(403);
    echo json_encode(['error'=>'Only the Branch Manager or System Admin can delete inventory.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error'=>'Invalid request method.']);
    exit;
}
$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) $payload = $_POST;
if (!Csrf::verify($payload['_csrf'] ?? null)) {
    http_response_code(419);
    echo json_encode(['error'=>'Your session expired. Refresh and try again.']);
    exit;
}
$productId = filter_var($payload['product_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$branchId = filter_var($payload['branch_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$expectedQuantity = filter_var($payload['expected_quantity'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$reason = is_string($payload['reason'] ?? null) ? trim($payload['reason']) : '';
$reason = preg_replace('/\s+/', ' ', $reason) ?? $reason;
if ($productId <= 0 || $branchId <= 0 || $expectedQuantity <= 0 || mb_strlen($reason) < 3 || mb_strlen($reason) > 180 || ($payload['confirmed'] ?? false) !== true) {
    http_response_code(422);
    echo json_encode(['error'=>'Review the available quantity, enter a reason (3–180 characters), and confirm deletion.']);
    exit;
}
// Enter Account retains the effective Branch Manager's own-branch restriction.
if (!Auth::isSystemAdmin() && $branchId !== (int)(Auth::branchId() ?: 0)) {
    http_response_code(403);
    echo json_encode(['error'=>'You can only delete available inventory from your own branch.']);
    exit;
}
$pdo = Database::connection();
try {
    $pdo->beginTransaction();
    $product = Database::query("SELECT id,product_name,product_type FROM products WHERE id=? AND is_active=1 LIMIT 1 FOR UPDATE", [$productId])->fetch();
    if (!$product || $product['product_type'] !== 'accessory') throw new RuntimeException('Select an active accessory product.');
    $branch = Database::query('SELECT id,name FROM branches WHERE id=? AND is_active=1 LIMIT 1 FOR UPDATE', [$branchId])->fetch();
    if (!$branch) throw new RuntimeException('Branch not found or inactive.');
    $balance = Database::query('SELECT id,quantity FROM inventory_balances WHERE product_id=? AND branch_id=? LIMIT 1 FOR UPDATE', [$productId,$branchId])->fetch();
    if (!$balance || (int)$balance['quantity'] <= 0) throw new RuntimeException('This available inventory row has already been removed. Refresh Inventory.');
    $quantity = (int)$balance['quantity'];
    if ($quantity !== $expectedQuantity) throw new RuntimeException('The stock quantity changed. Refresh Inventory and review the current quantity before deleting.');
    $actorId = (int)(Auth::actorId() ?: Auth::user()['id']);
    $reference = 'DELETE-ACC-' . (int)$balance['id'] . '-' . bin2hex(random_bytes(6));
    // Preserve transactions and record the quantity removal, then physically
    // delete only this branch's balance. Receiving can create a fresh balance.
    Database::query('INSERT INTO stock_movements (product_id,branch_id,movement_type,quantity,reference_no,notes,created_by) VALUES (?,?,?,?,?,?,?)', [$productId,$branchId,'adjustment',-$quantity,$reference,'Accessory inventory row deleted | '.$reason,$actorId]);
    $deleted = Database::query('DELETE FROM inventory_balances WHERE id=? AND product_id=? AND branch_id=? AND quantity=?', [(int)$balance['id'],$productId,$branchId,$quantity]);
    if ($deleted->rowCount() !== 1) throw new RuntimeException('Inventory changed. Refresh and try again.');
    $pdo->commit();
    Security::audit('inventory.accessory_balance_permanently_deleted','product',$productId,['branch_id'=>$branchId,'balance_id'=>(int)$balance['id'],'deleted_quantity'=>$quantity,'reason'=>$reason,'reference_no'=>$reference,'actor_user_id'=>$actorId,'effective_role'=>$role]);
    flash('success','Accessory inventory deleted from this branch. You can receive the product again.');
    echo json_encode(['success'=>true,'deleted_quantity'=>$quantity]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $status = $e instanceof RuntimeException ? 422 : 500;
    http_response_code($status);
    if ($status === 500) Security::reportException($e,'delete_accessory_inventory');
    echo json_encode(['error'=>$status === 500 ? 'Unable to delete inventory right now.' : $e->getMessage()]);
}
