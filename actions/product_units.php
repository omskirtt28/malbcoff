<?php
require __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error'=>'Please sign in again.']);
    exit;
}

$productId = filter_input(INPUT_GET, 'product_id', FILTER_VALIDATE_INT) ?: 0;
$requestedBranchId = filter_input(INPUT_GET, 'branch_id', FILTER_VALIDATE_INT) ?: 0;
$branchId = $requestedBranchId;
if (!Auth::isOwner()) {
    $ownBranchId = Auth::branchId() ?: 0;
    if ($requestedBranchId > 0 && $requestedBranchId !== $ownBranchId) {
        Security::audit('inventory.unit_details_denied', 'product', $productId, ['requested_branch_id' => $requestedBranchId]);
        http_response_code(403);
        echo json_encode(['error'=>'Detailed unit identifiers are only available for your assigned branch.']);
        exit;
    }
    $branchId = $ownBranchId;
}
if ($productId <= 0) {
    http_response_code(422);
    echo json_encode(['error'=>'Invalid product.']);
    exit;
}

$params = ['product'=>$productId];
$where = "iu.product_id=:product AND iu.status='available'";
if ($branchId > 0) {
    $where .= ' AND iu.branch_id=:branch';
    $params['branch'] = $branchId;
}

try {
    $transferTablesReady = (bool)Database::query("SHOW TABLES LIKE 'inventory_transfers'")->fetchColumn()
        && (bool)Database::query("SHOW TABLES LIKE 'inventory_transfer_units'")->fetchColumn();

    $transferSelect = $transferTablesReady
        ? ",\n                (SELECT t.received_at\n                 FROM inventory_transfer_units itu\n                 JOIN inventory_transfers t ON t.id=itu.transfer_id\n                 WHERE itu.unit_id=iu.id\n                   AND t.destination_branch_id=iu.branch_id\n                   AND t.status='received'\n                 ORDER BY t.received_at DESC,t.id DESC LIMIT 1) received_at,\n                (SELECT t.receiver_name\n                 FROM inventory_transfer_units itu\n                 JOIN inventory_transfers t ON t.id=itu.transfer_id\n                 WHERE itu.unit_id=iu.id\n                   AND t.destination_branch_id=iu.branch_id\n                   AND t.status='received'\n                 ORDER BY t.received_at DESC,t.id DESC LIMIT 1) receiver_name,\n                (SELECT t.reference_no\n                 FROM inventory_transfer_units itu\n                 JOIN inventory_transfers t ON t.id=itu.transfer_id\n                 WHERE itu.unit_id=iu.id\n                   AND t.destination_branch_id=iu.branch_id\n                   AND t.status='received'\n                 ORDER BY t.received_at DESC,t.id DESC LIMIT 1) transfer_reference"
        : ", NULL received_at, NULL receiver_name, NULL transfer_reference";

    $rows = Database::query(
        "SELECT iu.id unit_id,COALESCE(NULLIF(iu.serial_no,''),NULLIF(iu.imei,'')) identifier,
                CASE WHEN iu.serial_no IS NOT NULL AND iu.serial_no<>'' THEN 'Serial Number' ELSE 'IMEI 1' END identifier_type,
                iu.imei,iu.imei2,iu.serial_no,iu.status,iu.condition_type,iu.condition_grade,iu.battery_health,
                b.id branch_id,b.name branch_name,iu.created_at,
                COALESCE(
                    (SELECT MIN(sm_origin.created_at)
                     FROM stock_movements sm_origin
                     WHERE sm_origin.unit_id=iu.id
                       AND sm_origin.movement_type='stock_in'
                       AND sm_origin.quantity>0),
                    iu.created_at
                ) original_stock_in,
                COALESCE(
                    (SELECT MAX(sm_branch.created_at)
                     FROM stock_movements sm_branch
                     WHERE sm_branch.unit_id=iu.id
                       AND sm_branch.branch_id=iu.branch_id
                       AND sm_branch.movement_type IN ('stock_in','transfer_in')
                       AND sm_branch.quantity>0),
                    iu.created_at
                ) branch_stocked_in_at
                {$transferSelect}
         FROM inventory_units iu
         JOIN branches b ON b.id=iu.branch_id AND b.is_active=1
         WHERE {$where}
         ORDER BY b.id,branch_stocked_in_at DESC,iu.id DESC",
        $params
    )->fetchAll();

    foreach ($rows as &$row) {
        foreach (['original_stock_in','branch_stocked_in_at','received_at'] as $key) {
            $value = trim((string)($row[$key] ?? ''));
            $row[$key.'_display'] = $value !== '' && strtotime($value) !== false
                ? date('M d, Y • h:i A', strtotime($value))
                : '—';
        }
    }
    unset($row);

    echo json_encode(['rows'=>$rows], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>'Unable to load available units right now.']);
}
