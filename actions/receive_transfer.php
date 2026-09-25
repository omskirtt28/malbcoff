<?php
require __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Please sign in again.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Invalid request method.']);
    exit;
}

$user = Auth::user() ?? [];
$branchId = Auth::branchId() ?: 0;
if (Auth::isOwner() || $branchId <= 0 || !in_array((string)($user['role'] ?? ''), ['branch_manager','inventory'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Only the destination branch can receive this transfer.']);
    exit;
}

$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) $payload = $_POST;
if (!Csrf::verify($payload['_csrf'] ?? null)) {
    http_response_code(419);
    echo json_encode(['error' => 'Your session expired. Refresh the page and try again.']);
    exit;
}

$transferId = filter_var($payload['transfer_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$receiverName = trim((string)($payload['receiver_name'] ?? ''));
$receiverName = preg_replace('/\s+/', ' ', $receiverName) ?? $receiverName;
if ($transferId <= 0) {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid transfer.']);
    exit;
}
if ($receiverName === '' || mb_strlen($receiverName) < 2) {
    http_response_code(422);
    echo json_encode(['error' => 'Enter the name of the person who received the inventory.', 'field' => 'receiver_name']);
    exit;
}
if (mb_strlen($receiverName) > 120) $receiverName = mb_substr($receiverName, 0, 120);

$pdo = Database::connection();
try {
    $pdo->beginTransaction();

    $transfer = Database::query(
        "SELECT t.*,p.product_type,p.product_name,sb.name source_branch,db.name destination_branch
         FROM inventory_transfers t
         JOIN products p ON p.id=t.product_id
         JOIN branches sb ON sb.id=t.source_branch_id
         JOIN branches db ON db.id=t.destination_branch_id
         WHERE t.id=? LIMIT 1 FOR UPDATE",
        [$transferId]
    )->fetch();
    if (!$transfer) throw new RuntimeException('Transfer record not found.');
    if ((int)$transfer['destination_branch_id'] !== $branchId) throw new RuntimeException('This transfer belongs to another destination branch.');
    if (($transfer['status'] ?? '') !== 'pending') throw new RuntimeException('This transfer has already been received or is no longer pending.');

    $userId = (int)($user['id'] ?? 0);
    $quantity = (int)$transfer['quantity'];
    $reference = (string)$transfer['reference_no'];
    $movementNote = 'Received by ' . $receiverName . ' at ' . $transfer['destination_branch'] . ' from ' . $transfer['source_branch'];
    if (!empty($transfer['notes'])) $movementNote .= ' — ' . $transfer['notes'];

    if (($transfer['product_type'] ?? '') === 'accessory') {
        Database::query(
            'INSERT INTO inventory_balances (product_id,branch_id,quantity) VALUES (?,?,?) ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity)',
            [(int)$transfer['product_id'], $branchId, $quantity]
        );
        Database::query(
            'INSERT INTO stock_movements (product_id,branch_id,movement_type,quantity,reference_no,notes,created_by) VALUES (?,?,?,?,?,?,?)',
            [(int)$transfer['product_id'], $branchId, 'transfer_in', $quantity, $reference, $movementNote, $userId]
        );
    } else {
        $units = Database::query(
            "SELECT iu.id,iu.product_id,iu.branch_id,iu.status
             FROM inventory_transfer_units itu
             JOIN inventory_units iu ON iu.id=itu.unit_id
             WHERE itu.transfer_id=? FOR UPDATE",
            [$transferId]
        )->fetchAll();
        if (count($units) !== $quantity) throw new RuntimeException('Transfer unit details are incomplete. Contact the system administrator.');

        foreach ($units as $unit) {
            if ((int)$unit['branch_id'] !== (int)$transfer['source_branch_id'] || ($unit['status'] ?? '') !== 'transferred') {
                throw new RuntimeException('One or more units are no longer in transit. Refresh and try again.');
            }
            $updated = Database::query(
                "UPDATE inventory_units SET branch_id=?,status='available' WHERE id=? AND branch_id=? AND status='transferred'",
                [$branchId, (int)$unit['id'], (int)$transfer['source_branch_id']]
            );
            if ($updated->rowCount() !== 1) throw new RuntimeException('Inventory changed while receiving. Refresh and try again.');
            Database::query(
                'INSERT INTO stock_movements (product_id,unit_id,branch_id,movement_type,quantity,reference_no,notes,created_by) VALUES (?,?,?,?,?,?,?,?)',
                [(int)$transfer['product_id'], (int)$unit['id'], $branchId, 'transfer_in', 1, $reference, $movementNote, $userId]
            );
        }
    }

    $updated = Database::query(
        "UPDATE inventory_transfers SET status='received',receiver_name=?,received_by=?,received_at=NOW() WHERE id=? AND status='pending'",
        [$receiverName, $userId, $transferId]
    );
    if ($updated->rowCount() !== 1) throw new RuntimeException('This transfer has already been processed.');

    $pdo->commit();
    echo json_encode([
        'success' => true,
        'message' => 'Inventory received successfully.',
        'reference_no' => $reference,
        'receiver_name' => $receiverName,
        'quantity' => $quantity,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $status = $e instanceof RuntimeException ? 422 : 500;
    http_response_code($status);
    echo json_encode(['error' => $status === 500 ? 'Unable to receive this transfer right now.' : $e->getMessage()]);
}
