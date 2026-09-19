<?php
require __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Please sign in again.']);
    exit;
}
if (!Auth::isOwner()) {
    http_response_code(403);
    echo json_encode(['error' => 'Only the Owner can remove available units from inventory.']);
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
$branchId = filter_var($payload['branch_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$rawIds = is_array($payload['unit_ids'] ?? null) ? $payload['unit_ids'] : [];
$unitIds = array_values(array_unique(array_filter(array_map(static fn($v) => (int)$v, $rawIds), static fn($v) => $v > 0)));
$reason = strtolower(trim((string)($payload['reason'] ?? '')));
$notes = trim((string)($payload['notes'] ?? ''));
$notes = preg_replace('/\s+/', ' ', $notes) ?? $notes;
if (mb_strlen($notes) > 180) $notes = mb_substr($notes, 0, 180);

$reasons = [
    'stock_correction' => ['label' => 'Stock Correction', 'status' => 'adjusted_out'],
    'damaged' => ['label' => 'Damaged', 'status' => 'defective'],
    'missing' => ['label' => 'Missing', 'status' => 'adjusted_out'],
    'return_supplier' => ['label' => 'Return to Supplier', 'status' => 'returned'],
    'other' => ['label' => 'Other', 'status' => 'adjusted_out'],
];

if ($productId <= 0 || $branchId <= 0) {
    http_response_code(422);
    echo json_encode(['error' => 'Select a valid variant and branch.']);
    exit;
}
if (!$unitIds || count($unitIds) > 100) {
    http_response_code(422);
    echo json_encode(['error' => 'Select at least one available unit.']);
    exit;
}
if (!isset($reasons[$reason])) {
    http_response_code(422);
    echo json_encode(['error' => 'Select a reason for the inventory adjustment.']);
    exit;
}
if ($reason === 'other' && $notes === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Add a short note when using Other as the reason.']);
    exit;
}

$pdo = Database::connection();
try {
    $statusColumn = Database::query("SHOW COLUMNS FROM inventory_units LIKE 'status'")->fetch();
    if (!$statusColumn || !str_contains((string)$statusColumn['Type'], "'adjusted_out'")) {
        throw new RuntimeException('Run database/P2_007_owner_inventory_adjustment.sql before using inventory adjustments.');
    }

    $pdo->beginTransaction();

    $product = Database::query(
        "SELECT p.id FROM products p WHERE p.id=? AND p.product_type IN ('phone','tablet') LIMIT 1 FOR UPDATE",
        [$productId]
    )->fetch();
    if (!$product) throw new RuntimeException('Variant not found.');

    $branch = Database::query('SELECT id,name,code FROM branches WHERE id=? AND is_active=1 LIMIT 1 FOR UPDATE', [$branchId])->fetch();
    if (!$branch) throw new RuntimeException('Branch not found.');

    $marks = implode(',', array_fill(0, count($unitIds), '?'));
    $params = array_merge($unitIds, [$productId, $branchId]);
    $rows = Database::query(
        "SELECT id,status,serial_no,imei
         FROM inventory_units
         WHERE id IN ($marks) AND product_id=? AND branch_id=?
         FOR UPDATE",
        $params
    )->fetchAll();

    if (count($rows) !== count($unitIds)) {
        throw new RuntimeException('One or more selected units no longer belong to this branch or variant. Refresh and try again.');
    }
    foreach ($rows as $row) {
        if (($row['status'] ?? '') !== 'available') {
            throw new RuntimeException('One or more selected units are no longer available. Refresh and try again.');
        }
    }

    $reference = 'ADJ-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2)));
    $targetStatus = $reasons[$reason]['status'];
    $reasonLabel = $reasons[$reason]['label'];
    $movementNote = $reasonLabel . ($notes !== '' ? ' — ' . $notes : '');
    $userId = (int)(Auth::user()['id'] ?? 0);

    foreach ($rows as $row) {
        $updated = Database::query(
            "UPDATE inventory_units SET status=? WHERE id=? AND product_id=? AND branch_id=? AND status='available'",
            [$targetStatus, (int)$row['id'], $productId, $branchId]
        );
        if ($updated->rowCount() !== 1) {
            throw new RuntimeException('Inventory changed while you were adjusting it. Refresh and try again.');
        }
        Database::query(
            'INSERT INTO stock_movements (product_id,unit_id,branch_id,movement_type,quantity,reference_no,notes,created_by) VALUES (?,?,?,?,?,?,?,?)',
            [$productId, (int)$row['id'], $branchId, 'adjustment', -1, $reference, $movementNote, $userId]
        );
    }

    $remaining = (int)Database::query(
        "SELECT COUNT(*) FROM inventory_units WHERE product_id=? AND status='available'",
        [$productId]
    )->fetchColumn();
    $branchRemaining = (int)Database::query(
        "SELECT COUNT(*) FROM inventory_units WHERE product_id=? AND branch_id=? AND status='available'",
        [$productId, $branchId]
    )->fetchColumn();

    $pdo->commit();
    echo json_encode([
        'success' => true,
        'message' => count($rows) . ' unit' . (count($rows) === 1 ? '' : 's') . ' removed from available stock.',
        'reference_no' => $reference,
        'total_available' => $remaining,
        'branch_available' => $branchRemaining,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $status = $e instanceof RuntimeException ? 422 : 500;
    http_response_code($status);
    echo json_encode(['error' => $status === 500 ? 'Unable to save the inventory adjustment right now.' : $e->getMessage()]);
}
