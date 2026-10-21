<?php
putenv('DB_NAME=malbcoff_admin_profile_20261021');
require __DIR__.'/../../core/Database.php';
$config=require __DIR__.'/../../config/database.php';
if(!in_array($config['host'],['127.0.0.1','localhost'],true)||$config['name']!=='malbcoff_admin_profile_20261021')throw new RuntimeException('Isolated local data only');
$fixtures=json_decode(file_get_contents(__DIR__.'/fixtures.json'),true);
$pdo=Database::connection();$checks=[];
function check_history($condition,$label){global $checks;if(!$condition)throw new RuntimeException($label);$checks[]=$label;}
foreach($fixtures['history'] as $table=>$rows){
    foreach($rows as $row){$current=Database::query('SELECT * FROM '.$table.' WHERE id=?',[$row['id']])->fetch();check_history($current===$row,$table.' original row unchanged');}
}
$actor=$fixtures['users']['cashier'];$name=Database::query('SELECT name FROM users WHERE id=?',[$actor])->fetchColumn();
$pdo->beginTransaction();
try{
    Database::query('INSERT INTO sales (sale_no,branch_id,created_by,subtotal,total,payment_method,amount_received) VALUES (?,?,?,100,100,\'cash\',100)',['AFTER-RENAME-'.time(),$fixtures['b1'],$actor]);
    $sale=$pdo->lastInsertId();
    check_history(Database::query('SELECT cashier_name FROM sales WHERE id=?',[$sale])->fetchColumn()===$name,'new sale takes current name snapshot');
    Database::query('INSERT INTO stock_movements (product_id,branch_id,movement_type,quantity,reference_no,created_by) VALUES (?,?,\'adjustment\',1,\'AFTER-RENAME\',?)',[$fixtures['history']['inventory_units'][0]['product_id'],$fixtures['b1'],$actor]);
    check_history(Database::query('SELECT created_by_name FROM stock_movements WHERE id=?',[$pdo->lastInsertId()])->fetchColumn()===$name,'new movement takes current name snapshot');
    Database::query('INSERT INTO inventory_transfers (reference_no,product_id,source_branch_id,destination_branch_id,quantity,status,forwarded_by,received_by) VALUES (?,?,?,?,1,\'received\',?,?)',['AFTER-RENAME-'.time(),$fixtures['history']['inventory_units'][0]['product_id'],$fixtures['b1'],$fixtures['b2'],$actor,$actor]);
    $transfer=Database::query('SELECT forwarded_by_snapshot,received_by_snapshot FROM inventory_transfers WHERE id=?',[$pdo->lastInsertId()])->fetch();
    check_history($transfer['forwarded_by_snapshot']===$name&&$transfer['received_by_snapshot']===$name,'new transfer takes both current name snapshots');
}finally{$pdo->rollBack();}
$old=file_get_contents(__DIR__.'/../../output/admin-profile-update/pre-task/actions/user_management.php');
$shared=file_get_contents(__DIR__.'/../../core/UserManagement.php');
check_history(substr($old,strpos($old,'function clean_user_name'))===substr($shared,strpos($shared,'function clean_user_name')),'Owner validator bodies preserved byte for byte');
echo json_encode(['passed'=>count($checks),'evidence'=>$checks],JSON_PRETTY_PRINT)."\n";
