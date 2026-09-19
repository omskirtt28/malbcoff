<?php
require __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error'=>'Please sign in again.']);
    exit;
}

$productId = filter_input(INPUT_GET, 'product_id', FILTER_VALIDATE_INT) ?: 0;
$branchId = filter_input(INPUT_GET, 'branch_id', FILTER_VALIDATE_INT) ?: 0;
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
    $rows = Database::query(
        "SELECT COALESCE(NULLIF(iu.serial_no,''),NULLIF(iu.imei,'')) identifier,
                CASE WHEN iu.serial_no IS NOT NULL AND iu.serial_no<>'' THEN 'Serial Number' ELSE 'IMEI' END identifier_type,
                iu.imei,iu.serial_no,iu.status,iu.condition_type,iu.condition_grade,iu.battery_health,
                b.id branch_id,b.name branch_name,iu.created_at
         FROM inventory_units iu
         JOIN branches b ON b.id=iu.branch_id AND b.is_active=1
         WHERE {$where}
         ORDER BY b.id,iu.created_at DESC,iu.id DESC",
        $params
    )->fetchAll();
    echo json_encode(['rows'=>$rows], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>'Unable to load available units right now.']);
}
