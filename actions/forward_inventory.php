<?php
require __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Please sign in again.']);
    exit;
}

$user = Auth::user() ?? [];
$role = (string)($user['role'] ?? '');
$sourceBranchId = Auth::branchId() ?: 0;
if (!in_array($role, ['branch_manager','inventory'], true) || $sourceBranchId <= 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Only assigned branch staff can forward inventory.']);
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

$productId = filter_var($payload['product_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$destinationBranchId = filter_var($payload['destination_branch_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$quantity = filter_var($payload['quantity'] ?? 1, FILTER_VALIDATE_INT) ?: 0;
$rawUnitIds = is_array($payload['unit_ids'] ?? null) ? $payload['unit_ids'] : [];
$unitIds = array_values(array_unique(array_filter(array_map(static fn($v) => (int)$v, $rawUnitIds), static fn($v) => $v > 0)));
$notes = trim((string)($payload['notes'] ?? ''));
$notes = preg_replace('/\s+/', ' ', $notes) ?? $notes;
if (mb_strlen($notes) > 180) $notes = mb_substr($notes, 0, 180);

if ($productId <= 0 || $destinationBranchId <= 0) {
    http_response_code(422);
    echo json_encode(['error' => 'Select a valid item and destination branch.']);
    exit;
}
if ($destinationBranchId === $sourceBranchId) {
    http_response_code(422);
    echo json_encode(['error' => 'Choose another branch as the destination.']);
    exit;
}

$pdo = Database::connection();
try {
    $pdo->beginTransaction();

    $sourceBranch = Database::query('SELECT id,name,code FROM branches WHERE id=? AND is_active=1 LIMIT 1 FOR UPDATE', [$sourceBranchId])->fetch();
    $destinationBranch = Database::query('SELECT id,name,code FROM branches WHERE id=? AND is_active=1 LIMIT 1 FOR UPDATE', [$destinationBranchId])->fetch();
    if (!$sourceBranch || !$destinationBranch) throw new RuntimeException('Source or destination branch is not available.');

    $product = Database::query(
        "SELECT id,product_type,product_name FROM products WHERE id=? AND is_active=1 LIMIT 1 FOR UPDATE",
        [$productId]
    )->fetch();
    if (!$product) throw new RuntimeException('Product not found.');

    $userId = (int)($user['id'] ?? 0);
    $reference = 'TRF-' . date('Ymd-His') . '-' . strtoupper((string)$sourceBranch['code']) . '-' . strtoupper((string)$destinationBranch['code']) . '-' . strtoupper(bin2hex(random_bytes(2)));
    $baseNote = 'Forwarded from ' . $sourceBranch['name'] . ' to ' . $destinationBranch['name'];
    $movementNote = $baseNote . ($notes !== '' ? ' — ' . $notes : '');
    $moved = 0;

    if (($product['product_type'] ?? '') === 'accessory') {
        if ($quantity <= 0 || $quantity > 9999) throw new RuntimeException('Enter a valid quantity to forward.');

        $sourceBalance = Database::query(
            'SELECT id,quantity FROM inventory_balances WHERE product_id=? AND branch_id=? LIMIT 1 FOR UPDATE',
            [$productId, $sourceBranchId]
        )->fetch();
        if (!$sourceBalance || (int)$sourceBalance['quantity'] < $quantity) {
            throw new RuntimeException('Not enough available stock in your branch. Refresh and try again.');
        }

        Database::query(
            'UPDATE inventory_balances SET quantity=quantity-? WHERE product_id=? AND branch_id=? AND quantity>=?',
            [$quantity, $productId, $sourceBranchId, $quantity]
        );
        Database::query(
            'INSERT INTO inventory_balances (product_id,branch_id,quantity) VALUES (?,?,?) ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity)',
            [$productId, $destinationBranchId, $quantity]
        );
        Database::query(
            'INSERT INTO stock_movements (product_id,branch_id,movement_type,quantity,reference_no,notes,created_by) VALUES (?,?,?,?,?,?,?)',
            [$productId, $sourceBranchId, 'transfer_out', -$quantity, $reference, $movementNote, $userId]
        );
        Database::query(
            'INSERT INTO stock_movements (product_id,branch_id,movement_type,quantity,reference_no,notes,created_by) VALUES (?,?,?,?,?,?,?)',
            [$productId, $destinationBranchId, 'transfer_in', $quantity, $reference, $movementNote, $userId]
        );
        $moved = $quantity;
    } else {
        if (!$unitIds || count($unitIds) > 100) throw new RuntimeException('Select at least one available unit to forward.');

        $marks = implode(',', array_fill(0, count($unitIds), '?'));
        $params = array_merge($unitIds, [$productId, $sourceBranchId]);
        $units = Database::query(
            "SELECT id,status FROM inventory_units WHERE id IN ($marks) AND product_id=? AND branch_id=? FOR UPDATE",
            $params
        )->fetchAll();
        if (count($units) !== count($unitIds)) throw new RuntimeException('One or more selected units no longer belong to your branch. Refresh and try again.');
        foreach ($units as $unit) {
            if (($unit['status'] ?? '') !== 'available') throw new RuntimeException('One or more selected units are no longer available.');
        }

        foreach ($units as $unit) {
            $unitId = (int)$unit['id'];
            $updated = Database::query(
                "UPDATE inventory_units SET branch_id=? WHERE id=? AND product_id=? AND branch_id=? AND status='available'",
                [$destinationBranchId, $unitId, $productId, $sourceBranchId]
            );
            if ($updated->rowCount() !== 1) throw new RuntimeException('Inventory changed while forwarding. Refresh and try again.');

            Database::query(
                'INSERT INTO stock_movements (product_id,unit_id,branch_id,movement_type,quantity,reference_no,notes,created_by) VALUES (?,?,?,?,?,?,?,?)',
                [$productId, $unitId, $sourceBranchId, 'transfer_out', -1, $reference, $movementNote, $userId]
            );
            Database::query(
                'INSERT INTO stock_movements (product_id,unit_id,branch_id,movement_type,quantity,reference_no,notes,created_by) VALUES (?,?,?,?,?,?,?,?)',
                [$productId, $unitId, $destinationBranchId, 'transfer_in', 1, $reference, $movementNote, $userId]
            );
        }
        $moved = count($units);
    }

    $pdo->commit();
    echo json_encode([
        'success' => true,
        'message' => $moved . ' unit' . ($moved === 1 ? '' : 's') . ' forwarded to ' . $destinationBranch['name'] . '.',
        'reference_no' => $reference,
        'destination_branch' => $destinationBranch['name'],
        'quantity' => $moved,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $status = $e instanceof RuntimeException ? 422 : 500;
    http_response_code($status);
    echo json_encode(['error' => $status === 500 ? 'Unable to forward inventory right now.' : $e->getMessage()]);
}
