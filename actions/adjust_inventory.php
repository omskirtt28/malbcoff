<?php
require __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Please sign in again.']);
    exit;
}

$effectiveRole = (string)(Auth::user()['role'] ?? '');
$canAdjustInventory = Auth::actorIsSystemAdmin() || $effectiveRole === 'branch_manager';
if (!$canAdjustInventory) {
    Security::audit('inventory.adjustment_denied', 'inventory', null, ['reason' => 'branch_manager_or_system_admin_required']);
    http_response_code(403);
    echo json_encode(['error' => 'Only the Branch Manager or System Admin can correct available stock.']);
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
    echo json_encode(['error' => 'Review and confirm the stock adjustment before saving.']);
    exit;
}

$productId = filter_var($payload['product_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$branchId = filter_var($payload['branch_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$rawIds = is_array($payload['unit_ids'] ?? null) ? $payload['unit_ids'] : [];
$unitIds = array_values(array_unique(array_filter(array_map(static fn($v) => (int)$v, $rawIds), static fn($v) => $v > 0)));
$quantity = filter_var($payload['quantity'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$direction = strtolower(trim((string)($payload['direction'] ?? 'decrease')));
$direction = in_array($direction, ['increase','decrease'], true) ? $direction : 'decrease';
$actionMode = strtolower(trim((string)($payload['action_mode'] ?? 'remove_units')));
$actionMode = in_array($actionMode, ['quantity_adjustment','remove_units','correct_identifier'], true) ? $actionMode : 'remove_units';
$correctionUnitId = filter_var($payload['unit_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$correctedImei = normalize_adjust_identifier($payload['imei'] ?? '');
$correctedImei2 = normalize_adjust_identifier($payload['imei2'] ?? '');
$correctedSerial = normalize_adjust_identifier($payload['serial_no'] ?? '');
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
    echo json_encode(['error' => 'Select a valid product and branch.']);
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
if ($direction === 'increase' && !in_array($reason, ['stock_correction','other'], true)) {
    http_response_code(422);
    echo json_encode(['error' => 'Use Stock Correction or Other when increasing accessory stock.']);
    exit;
}

// Branch Managers are always limited to their assigned branch. A System Admin
// impersonating a Branch Manager inherits the same branch scope. A direct
// System Admin session may correct any branch.
if ($effectiveRole === 'branch_manager') {
    $targetBranchId = Auth::branchId() ?: 0;
    if ($targetBranchId <= 0 || $branchId !== $targetBranchId) {
        Security::audit('inventory.adjustment_denied', 'product', $productId, [
            'reason' => 'branch_scope',
            'requested_branch_id' => $branchId,
            'target_branch_id' => $targetBranchId,
        ]);
        http_response_code(403);
        echo json_encode(['error' => 'You can only adjust inventory assigned to your branch.']);
        exit;
    }
}

$pdo = Database::connection();
try {
    $pdo->beginTransaction();

    $product = Database::query(
        "SELECT p.id,p.product_type,p.product_name,p.ram,p.storage,p.connectivity,p.color,p.cost_price,p.selling_price,
                b.name brand_name,pm.name model_name
         FROM products p
         LEFT JOIN brands b ON b.id=p.brand_id
         LEFT JOIN product_models pm ON pm.id=p.model_id
         WHERE p.id=? AND p.is_active=1
         LIMIT 1 FOR UPDATE",
        [$productId]
    )->fetch();
    if (!$product) throw new RuntimeException('Product not found or no longer active.');

    $branch = Database::query('SELECT id,name,code FROM branches WHERE id=? AND is_active=1 LIMIT 1 FOR UPDATE', [$branchId])->fetch();
    if (!$branch) throw new RuntimeException('Branch not found.');

    $reference = 'ADJ-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2)));
    $reasonLabel = $reasons[$reason]['label'];
    $directionLabel = $direction === 'increase' ? 'Increase' : 'Decrease';
    $movementNote = $directionLabel . ' — ' . $reasonLabel . ($notes !== '' ? ' — ' . $notes : '');
    $actorId = Auth::actorId() ?: (int)(Auth::user()['id'] ?? 0);
    $effectiveUserId = (int)(Auth::user()['id'] ?? 0);
    $changed = 0;
    $identifierCorrected = false;
    $correctionChanges = [];
    $correctedUnitId = 0;
    $productType = (string)$product['product_type'];

    if ($productType === 'accessory') {
        if ($quantity < 1 || $quantity > 100000) {
            throw new RuntimeException('Enter a valid adjustment quantity.');
        }

        if ($direction === 'increase') {
            Database::query(
                'INSERT INTO inventory_balances (product_id,branch_id,quantity) VALUES (?,?,?) ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity)',
                [$productId, $branchId, $quantity]
            );
            Database::query(
                'INSERT INTO stock_movements (product_id,branch_id,movement_type,quantity,reference_no,notes,unit_cost,unit_selling_price,created_by) VALUES (?,?,?,?,?,?,?,?,?)',
                [$productId,$branchId,'adjustment',$quantity,$reference,$movementNote,(float)($product['cost_price']??0),branch_selling_price($productId,$branchId,(float)($product['selling_price']??0)),$actorId]
            );
            $changed = $quantity;
        } else {
            $balance = Database::query(
                'SELECT id,quantity FROM inventory_balances WHERE product_id=? AND branch_id=? LIMIT 1 FOR UPDATE',
                [$productId, $branchId]
            )->fetch();
            $available = (int)($balance['quantity'] ?? 0);
            if (!$balance || $available < $quantity) {
                throw new RuntimeException('Adjustment quantity is higher than the current available stock. Refresh and try again.');
            }

            $updated = Database::query(
                'UPDATE inventory_balances SET quantity=quantity-? WHERE id=? AND quantity>=?',
                [$quantity, (int)$balance['id'], $quantity]
            );
            if ($updated->rowCount() !== 1) {
                throw new RuntimeException('Inventory changed while you were adjusting it. Refresh and try again.');
            }

            Database::query(
                'INSERT INTO stock_movements (product_id,branch_id,movement_type,quantity,reference_no,notes,unit_cost,unit_selling_price,created_by) VALUES (?,?,?,?,?,?,?,?,?)',
                [$productId,$branchId,'adjustment',-$quantity,$reference,$movementNote,(float)($product['cost_price']??0),branch_selling_price($productId,$branchId,(float)($product['selling_price']??0)),$actorId]
            );
            $changed = $quantity;
        }
    } elseif (in_array($productType, ['phone','tablet'], true)) {
        if ($actionMode === 'correct_identifier') {
            if ($correctionUnitId <= 0) {
                throw new RuntimeException('Select one available device unit to correct.');
            }

            $row = Database::query(
                "SELECT id,status,serial_no,imei,imei2
                 FROM inventory_units
                 WHERE id=? AND product_id=? AND branch_id=?
                 LIMIT 1 FOR UPDATE",
                [$correctionUnitId, $productId, $branchId]
            )->fetch();

            if (!$row) {
                throw new RuntimeException('The selected unit no longer belongs to this branch or product. Refresh and try again.');
            }
            if (($row['status'] ?? '') !== 'available') {
                throw new RuntimeException('Only an available unit can have its IMEI or Serial Number corrected.');
            }
            if ($correctedImei === '' && $correctedImei2 === '' && $correctedSerial === '') {
                throw new RuntimeException('Enter at least one IMEI or Serial Number for the selected unit.');
            }

            $newIdentifiers = array_values(array_filter([$correctedImei, $correctedImei2, $correctedSerial], static fn($value) => $value !== ''));
            if (count($newIdentifiers) !== count(array_unique($newIdentifiers))) {
                throw new RuntimeException('IMEI 1, IMEI 2, and Serial Number must not duplicate each other.');
            }

            $oldImei = normalize_adjust_identifier($row['imei'] ?? '');
            $oldImei2 = normalize_adjust_identifier($row['imei2'] ?? '');
            $oldSerial = normalize_adjust_identifier($row['serial_no'] ?? '');
            if ($oldImei === $correctedImei && $oldImei2 === $correctedImei2 && $oldSerial === $correctedSerial) {
                throw new RuntimeException('No identifier change was detected.');
            }

            foreach (['IMEI 1' => $correctedImei, 'IMEI 2' => $correctedImei2, 'Serial Number' => $correctedSerial] as $label => $value) {
                if ($value === '') continue;
                $duplicateId = Database::query(
                    "SELECT id
                     FROM inventory_units
                     WHERE id<>?
                       AND (imei=? OR imei2=? OR serial_no=?)
                     LIMIT 1 FOR UPDATE",
                    [$correctionUnitId, $value, $value, $value]
                )->fetchColumn();
                if ($duplicateId) {
                    throw new RuntimeException($label . ' is already assigned to another inventory unit.');
                }
            }

            $updated = Database::query(
                "UPDATE inventory_units
                 SET imei=?, imei2=?, serial_no=?
                 WHERE id=? AND product_id=? AND branch_id=? AND status='available'",
                [
                    $correctedImei !== '' ? $correctedImei : null,
                    $correctedImei2 !== '' ? $correctedImei2 : null,
                    $correctedSerial !== '' ? $correctedSerial : null,
                    $correctionUnitId,
                    $productId,
                    $branchId,
                ]
            );
            if ($updated->rowCount() !== 1) {
                throw new RuntimeException('Inventory changed while you were correcting the identifier. Refresh and try again.');
            }

            if ($oldImei !== $correctedImei) $correctionChanges[] = 'IMEI 1: ' . ($oldImei !== '' ? $oldImei : '—') . ' -> ' . ($correctedImei !== '' ? $correctedImei : '—');
            if ($oldImei2 !== $correctedImei2) $correctionChanges[] = 'IMEI 2: ' . ($oldImei2 !== '' ? $oldImei2 : '—') . ' -> ' . ($correctedImei2 !== '' ? $correctedImei2 : '—');
            if ($oldSerial !== $correctedSerial) $correctionChanges[] = 'Serial: ' . ($oldSerial !== '' ? $oldSerial : '—') . ' -> ' . ($correctedSerial !== '' ? $correctedSerial : '—');

            $correctionNote = 'Identifier Correction — ' . $reasonLabel;
            if ($notes !== '') $correctionNote .= ' — ' . $notes;
            if ($correctionChanges) $correctionNote .= ' — ' . implode(' | ', $correctionChanges);

            Database::query(
                'INSERT INTO stock_movements (product_id,unit_id,branch_id,movement_type,quantity,reference_no,notes,created_by) VALUES (?,?,?,?,?,?,?,?)',
                [$productId,$correctionUnitId,$branchId,'adjustment',0,$reference,$correctionNote,$actorId]
            );

            $identifierCorrected = true;
            $correctedUnitId = $correctionUnitId;
            $changed = 0;
        } else {
            if ($direction !== 'decrease') {
                throw new RuntimeException('Use Receive Stock when adding a serialized phone or tablet so its IMEI/Serial is recorded.');
            }

            $statusColumn = Database::query("SHOW COLUMNS FROM inventory_units LIKE 'status'")->fetch();
            if (!$statusColumn || !str_contains((string)$statusColumn['Type'], "'adjusted_out'")) {
                throw new RuntimeException('Inventory adjustment setup is incomplete. Contact the system administrator.');
            }
            if (!$unitIds || count($unitIds) > 100) {
                throw new RuntimeException('Select at least one available device unit.');
            }

            $marks = implode(',', array_fill(0, count($unitIds), '?'));
            $params = array_merge($unitIds, [$productId, $branchId]);
            $rows = Database::query(
                "SELECT id,status,serial_no,imei,imei2
                 FROM inventory_units
                 WHERE id IN ($marks) AND product_id=? AND branch_id=?
                 FOR UPDATE",
                $params
            )->fetchAll();

            if (count($rows) !== count($unitIds)) {
                throw new RuntimeException('One or more selected units no longer belong to this branch or product. Refresh and try again.');
            }
            foreach ($rows as $row) {
                if (($row['status'] ?? '') !== 'available') {
                    throw new RuntimeException('One or more selected units are no longer available. Refresh and try again.');
                }
            }

            $targetStatus = $reasons[$reason]['status'];
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
                    [$productId,(int)$row['id'],$branchId,'adjustment',-1,$reference,$movementNote,$actorId]
                );
            }
            $changed = count($rows);
        }
    } else {
        throw new RuntimeException('This item type cannot be adjusted from Inventory.');
    }

    $branchRemaining = $productType === 'accessory'
        ? (int)Database::query('SELECT COALESCE(quantity,0) FROM inventory_balances WHERE product_id=? AND branch_id=? LIMIT 1', [$productId,$branchId])->fetchColumn()
        : (int)Database::query("SELECT COUNT(*) FROM inventory_units WHERE product_id=? AND branch_id=? AND status='available'", [$productId,$branchId])->fetchColumn();

    $pdo->commit();

    Security::audit($identifierCorrected ? 'inventory.identifier_corrected' : 'inventory.adjusted', 'product', $productId, [
        'reference_no' => $reference,
        'branch_id' => $branchId,
        'action_mode' => $identifierCorrected ? 'correct_identifier' : ($productType === 'accessory' ? 'quantity_adjustment' : 'remove_units'),
        'direction' => $identifierCorrected ? 'none' : $direction,
        'quantity' => $changed,
        'unit_id' => $identifierCorrected ? $correctedUnitId : null,
        'identifier_changes' => $identifierCorrected ? $correctionChanges : null,
        'reason' => $reason,
        'actor_user_id' => $actorId,
        'effective_user_id' => $effectiveUserId,
        'effective_role' => $effectiveRole,
    ]);

    if ($identifierCorrected) {
        $message = 'Device IMEI / Serial corrected. Available stock quantity was not changed.';
    } else {
        $verb = $direction === 'increase' ? 'added to' : 'removed from';
        $message = $changed . ' unit' . ($changed === 1 ? '' : 's') . ' ' . $verb . ' available stock.';
    }
    flash('success', $message . ' Reference: ' . $reference);

    echo json_encode([
        'success' => true,
        'message' => $message,
        'reference_no' => $reference,
        'branch_available' => $branchRemaining,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $status = $e instanceof RuntimeException ? 422 : 500;
    http_response_code($status);
    echo json_encode(['error' => $status === 500 ? 'Unable to save the inventory adjustment right now.' : $e->getMessage()]);
}

function normalize_adjust_identifier(mixed $value): string
{
    $normalized = preg_replace('/\s+/', '', trim((string)$value)) ?? '';
    return function_exists('mb_strtoupper') ? mb_strtoupper($normalized, 'UTF-8') : strtoupper($normalized);
}
