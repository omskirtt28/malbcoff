<?php
require __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
if(!Auth::check()){http_response_code(401);echo json_encode(['error'=>'Unauthorized']);exit;}
$productId=filter_input(INPUT_GET,'product_id',FILTER_VALIDATE_INT);
if(!$productId){http_response_code(422);echo json_encode(['error'=>'Invalid product']);exit;}
$params=['product'=>$productId];$where='iu.product_id=:product';
if(!Auth::isOwner()){$where.=' AND iu.branch_id=:branch';$params['branch']=Auth::branchId();}
try{
    $rows=Database::query("SELECT COALESCE(iu.serial_no,iu.imei) identifier,CASE WHEN iu.serial_no IS NOT NULL AND iu.serial_no<>'' THEN 'Serial Number' ELSE 'IMEI' END identifier_type,iu.imei,iu.serial_no,iu.status,iu.condition_type,iu.condition_grade,iu.battery_health,b.name branch_name,iu.created_at FROM inventory_units iu JOIN branches b ON b.id=iu.branch_id WHERE {$where} ORDER BY iu.created_at DESC",$params)->fetchAll();
    echo json_encode(['rows'=>$rows]);
}catch(Throwable $e){http_response_code(500);echo json_encode(['error'=>'Unable to load units']);}
