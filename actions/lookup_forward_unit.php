<?php
require __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error'=>'Please sign in again.']);
    exit;
}
$role=(string)(Auth::user()['role']??'');
$branchId=Auth::branchId()?:0;
if (!in_array($role,['branch_manager','inventory'],true) || $branchId<=0) {
    http_response_code(403);
    echo json_encode(['error'=>'Only assigned branch staff can look up units for forwarding.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD']!=='GET') {
    http_response_code(405);
    echo json_encode(['error'=>'Invalid request method.']);
    exit;
}
$value=preg_replace('/\s+/','',trim((string)($_GET['identifier']??'')))??'';
$value=strtoupper($value);
if ($value==='' || strlen($value)>120 || preg_match('/[\x00-\x1f\x7f]/',$value)) {
    http_response_code(422);
    echo json_encode(['error'=>'Enter the complete IMEI or Serial Number.']);
    exit;
}
try {
    $rows=Database::query(
        "SELECT iu.id unit_id,iu.product_id,iu.branch_id,iu.imei,iu.imei2,iu.serial_no,
                p.product_type,p.product_name,p.ram,p.storage,p.connectivity,p.color,
                b.name brand_name,pm.name model_name,br.name branch_name,
                (SELECT COUNT(*) FROM inventory_units available
                 WHERE available.product_id=iu.product_id AND available.branch_id=iu.branch_id
                   AND available.status='available') available_units
         FROM inventory_units iu
         JOIN products p ON p.id=iu.product_id AND p.is_active=1
         JOIN branches br ON br.id=iu.branch_id AND br.is_active=1
         JOIN product_models pm ON pm.id=p.model_id AND pm.is_active=1
         LEFT JOIN brands b ON b.id=p.brand_id
         WHERE iu.branch_id=? AND iu.status='available' AND p.product_type IN ('phone','tablet')
           AND (iu.imei=? OR iu.imei2=? OR iu.serial_no=?)
         LIMIT 2",[$branchId,$value,$value,$value]
    )->fetchAll();
    if (!$rows) {
        http_response_code(404);
        echo json_encode(['error'=>'No available unit with that IMEI / Serial Number was found in your branch.']);
        exit;
    }
    if (count($rows)!==1) {
        http_response_code(409);
        echo json_encode(['error'=>'That identifier matches more than one unit. Ask the inventory administrator to resolve the duplicate.']);
        exit;
    }
    $row=$rows[0];
    echo json_encode(['unit_id'=>(int)$row['unit_id'],'identifier'=>$value,'product'=>[
        'productId'=>(int)$row['product_id'],'productType'=>(string)$row['product_type'],
        'sourceBranchId'=>(int)$row['branch_id'],'sourceBranch'=>(string)$row['branch_name'],
        'available'=>(int)$row['available_units'],'product'=>malbcoff_product_name($row),
        'productName'=>(string)$row['model_name'],'brand'=>(string)($row['brand_name']??''),
        'model'=>(string)$row['model_name'],'specs'=>malbcoff_product_specs($row)
    ]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>'Unable to find the unit right now. Please try again.']);
}
