<?php
require __DIR__ . '/../bootstrap.php';

$returnParams=[];
$returnBranch=filter_var($_POST['return_branch']??null,FILTER_VALIDATE_INT)?:0;
if($returnBranch>0)$returnParams['branch']=$returnBranch;
$returnType=(string)($_POST['return_movement']??'');
if(in_array($returnType,['stock_in','stock_out','sale','transfer_in','transfer_out','adjustment','return','defective'],true))$returnParams['movement']=$returnType;
$returnUrl=app_url('stock-movement',$returnParams);
if(!Auth::check())redirect(app_url('login'));
if(!Auth::actorIsSystemAdmin()){
    http_response_code(403);
    flash('error','Only System Admin can void inventory movements.');redirect($returnUrl);
}
if($_SERVER['REQUEST_METHOD']!=='POST' || !Csrf::verify($_POST['_csrf']??null)){
    flash('error','Your session expired. Refresh Stock Movement and try again.');redirect($returnUrl);
}
$actorId=Auth::actorId()?:0;
$actor=Auth::fetchUser($actorId);
if(!$actor || ($actor['role']??'')!=='system_admin' || !(int)($actor['is_active']??0)){
    flash('error','An active System Admin account is required.');redirect($returnUrl);
}
$ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['movement_ids']??[])),static fn($id)=>$id>0)));
sort($ids,SORT_NUMERIC);
$reason=preg_replace('/\s+/',' ',trim((string)($_POST['reason']??'')))??'';
if(!$ids || count($ids)>100 || mb_strlen($reason)<3 || mb_strlen($reason)>180){
    flash('error','Select 1 to 100 outgoing records and enter a reason (3 to 180 characters).');redirect($returnUrl);
}
try{
    if(!inventory_void_schema_ready())throw new RuntimeException('Import database/P2_024_system_admin_inventory_void.sql before using Void.');
    $pdo=Database::connection();$pdo->beginTransaction();
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $movements=Database::query("SELECT * FROM stock_movements WHERE id IN ($marks) ORDER BY id FOR UPDATE",$ids)->fetchAll();
    if(count($movements)!==count($ids))throw new RuntimeException('A selected movement no longer exists. Nothing was voided.');
    $scope=current_branch_scope();
    $saleRows=[];$saleItems=[];
    foreach($movements as $movement){
        if($scope && (int)$movement['branch_id']!==(int)$scope)throw new RuntimeException('A selected record belongs to another branch.');
        if(!in_array($movement['movement_type'],['sale','stock_out','adjustment','defective'],true) || (int)$movement['quantity']>=0)throw new RuntimeException('Select an outgoing Sold, Stock Out, Adjustment or Defective record.');
        if(Database::query('SELECT id FROM inventory_movement_voids WHERE movement_id=?',[(int)$movement['id']])->fetchColumn())throw new RuntimeException('A selected record has already been voided. Nothing was added again.');
        if($movement['movement_type']==='sale'){
            $sale=Database::query('SELECT * FROM sales WHERE sale_no=? AND branch_id=? LIMIT 1 FOR UPDATE',[$movement['reference_no'],(int)$movement['branch_id']])->fetch();
            if(!$sale || $sale['status']!=='completed')throw new RuntimeException('The original completed sale could not be matched.');
            $matches=Database::query('SELECT * FROM sale_items WHERE sale_id=? AND product_id=? AND unit_id <=> ? AND quantity=? ORDER BY id LIMIT 2 FOR UPDATE',[(int)$sale['id'],(int)$movement['product_id'],$movement['unit_id']?:null,-(int)$movement['quantity']])->fetchAll();
            if(count($matches)!==1)throw new RuntimeException('The original sale item is missing or ambiguous. Ask System Admin to review this record.');
            $item=$matches[0];
            if(Database::query('SELECT id FROM inventory_movement_voids WHERE sale_item_id=?',[(int)$item['id']])->fetchColumn())throw new RuntimeException('That sale item has already been voided.');
            $saleRows[(int)$sale['id']]=$sale;
            $saleItems[(int)$movement['id']]=$item;
        }
    }
    // Do not guess how to allocate a legacy discount or manually changed total.
    foreach($saleRows as $saleId=>$sale){
        $sum=Database::query('SELECT COALESCE(SUM(si.line_total),0) FROM sale_items si WHERE si.sale_id=? AND '.inventory_active_sale_item_sql('si'),[$saleId])->fetchColumn();
        if((int)round((float)$sum*100)!==(int)round((float)$sale['total']*100) || (int)round((float)$sale['subtotal']*100)!==(int)round((float)$sale['total']*100))throw new RuntimeException('This receipt has an adjusted total. Review its discount/payment allocation before voiding individual items.');
    }
    $restored=0;
    foreach($movements as $movement){
        $movementId=(int)$movement['id'];$unitId=(int)($movement['unit_id']??0);
        $branchId=(int)$movement['branch_id'];$productId=(int)$movement['product_id'];$quantity=-(int)$movement['quantity'];
        $branch=Database::query('SELECT id FROM branches WHERE id=? AND is_active=1',[$branchId])->fetch();
        if(!$branch)throw new RuntimeException('The original inventory branch is inactive.');
        if(!Database::query('SELECT id FROM products WHERE id=? AND is_active=1',[$productId])->fetchColumn())throw new RuntimeException('Restore the archived product first so the returned stock can appear in Inventory.');
        if($unitId){
            $unit=Database::query('SELECT * FROM inventory_units WHERE id=? LIMIT 1 FOR UPDATE',[$unitId])->fetch();
            $expected=$unit['status']??'';
            $allowed=$movement['movement_type']==='sale'?['sold']:['adjusted_out','defective'];
            if(!$unit || (int)$unit['branch_id']!==$branchId || (int)$unit['product_id']!==$productId || !in_array($expected,$allowed,true) || $quantity!==1)throw new RuntimeException('A unit has moved or changed since this outgoing record. Nothing was restored.');
            $latest=(int)Database::query('SELECT MAX(id) FROM stock_movements WHERE unit_id=?',[$unitId])->fetchColumn();
            if($latest!==$movementId)throw new RuntimeException('A selected unit has later stock activity. Void its latest applicable record instead.');
            $updated=Database::query("UPDATE inventory_units SET status='available' WHERE id=? AND branch_id=? AND product_id=? AND status=?",[$unitId,$branchId,$productId,$expected]);
            if($updated->rowCount()!==1)throw new RuntimeException('The unit changed while processing. Refresh and try again.');
        }else{
            $product=Database::query('SELECT product_type FROM products WHERE id=?',[$productId])->fetch();
            if(!$product || $product['product_type']!=='accessory')throw new RuntimeException('The original device unit is missing. Nothing was restored.');
            Database::query('INSERT INTO inventory_balances (product_id,branch_id,quantity) VALUES (?,?,?) ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity)',[$productId,$branchId,$quantity]);
        }
        $item=$saleItems[$movementId]??null;
        Database::query('INSERT INTO inventory_movement_voids (movement_id,sale_item_id,sale_id,branch_id,product_id,unit_id,restored_quantity,voided_amount,reason,voided_by) VALUES (?,?,?,?,?,?,?,?,?,?)',[$movementId,$item?(int)$item['id']:null,$item?(int)$item['sale_id']:null,$branchId,$productId,$unitId?:null,$quantity,$item?$item['line_total']:0,$reason,$actorId]);
        Database::query('INSERT INTO stock_movements (product_id,unit_id,branch_id,movement_type,quantity,reference_no,notes,unit_cost,unit_selling_price,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',[$productId,$unitId?:null,$branchId,'adjustment',$quantity,'VOID-'.$movementId,'Void restoration of '.$movement['reference_no'].' — '.$reason,$movement['unit_cost']??0,$movement['unit_selling_price']??0,$actorId]);
        $restored+=$quantity;
    }
    foreach($saleRows as $saleId=>$sale){
        $remaining=Database::query('SELECT COUNT(*) remaining_items,COALESCE(SUM(si.line_total),0) remaining_total FROM sale_items si WHERE si.sale_id=? AND '.inventory_active_sale_item_sql('si'),[$saleId])->fetch();
        Database::query('UPDATE sales SET subtotal=?,total=?,status=? WHERE id=?',[$remaining['remaining_total'],$remaining['remaining_total'],(int)$remaining['remaining_items']===0?'voided':'completed',$saleId]);
    }
    $pdo->commit();
    Security::audit('inventory.movements_voided','stock_movement',implode(',',$ids),['movement_ids'=>$ids,'restored_quantity'=>$restored,'reason'=>$reason,'actor_id'=>$actorId]);
    flash('success','Voided '.count($ids).' selected record(s). '.$restored.' unit(s) restored to their original branch inventory.');
}catch(Throwable $e){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    flash('error',safe_exception_message($e,'Unable to void the selected records. No inventory changes were saved.'));
}
redirect($returnUrl);
