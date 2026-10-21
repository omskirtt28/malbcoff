<?php
require __DIR__.'/../../core/Database.php';
$config = require __DIR__.'/../../config/database.php';
if (!in_array($config['host'], ['localhost','127.0.0.1'], true)) throw new RuntimeException('Local only');
$pdo = Database::connection();
$test = 'malbcoff_admin_profile_20261021';
if ($pdo->query("SHOW DATABASES LIKE '$test'")->fetchColumn()) exit("Isolated database already exists; no reset performed.\n");
$source = $config['name'];
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$triggers = $pdo->query('SHOW TRIGGERS')->fetchAll();
$pdo->exec("CREATE DATABASE `$test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$test`");
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($tables as $table) {
    $create = $pdo->query("SHOW CREATE TABLE `$source`.`$table`")->fetch()['Create Table'];
    $create = str_replace('`'.$source.'`.', '`'.$test.'`.', $create);
    $pdo->exec($create);
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
foreach ($triggers as $trigger) {
    $sql = 'CREATE TRIGGER `'.$trigger['Trigger'].'` '.$trigger['Timing'].' '.$trigger['Event'].' ON `'.$trigger['Table'].'` FOR EACH ROW '.$trigger['Statement'];
    $pdo->exec(str_replace('`'.$source.'`.', '`'.$test.'`.', $sql));
}
function fixture_add(string $table, array $data): int {
    Database::query('INSERT INTO `'.$table.'` (`'.implode('`,`', array_keys($data)).'`) VALUES ('.implode(',', array_fill(0,count($data),'?')).')', array_values($data));
    return (int)Database::connection()->lastInsertId();
}
$b1=fixture_add('branches',['name'=>'Test Central','code'=>'TEST-C','is_active'=>1]);
$b2=fixture_add('branches',['name'=>'Test North','code'=>'TEST-N','is_active'=>1]);
$b3=fixture_add('branches',['name'=>'Test Inactive','code'=>'TEST-I','is_active'=>0]);
$users=[];
foreach (['system_admin','owner','branch_manager','inventory','cashier'] as $role) {
    $users[$role]=fixture_add('users',['name'=>'Original '.$role,'email'=>$role.'@test.invalid','role'=>$role,'branch_id'=>in_array($role,['owner','system_admin'])?null:$b1,'is_active'=>1,'must_change_password'=>0,'password_hash'=>password_hash('FixturePassword123',PASSWORD_DEFAULT)]);
}
$brand=fixture_add('brands',['name'=>'Test Brand']);
$model=fixture_add('product_models',['brand_id'=>$brand,'name'=>'Test Phone','device_type'=>'phone','is_active'=>1]);
$product=fixture_add('products',['brand_id'=>$brand,'model_id'=>$model,'product_type'=>'phone','is_active'=>1]);
$unit=fixture_add('inventory_units',['product_id'=>$product,'branch_id'=>$b1,'serial_no'=>'TEST-UNIT','status'=>'sold','created_by'=>$users['cashier']]);
$sale=fixture_add('sales',['sale_no'=>'TEST-SALE','branch_id'=>$b1,'created_by'=>$users['cashier'],'subtotal'=>100,'total'=>100,'payment_method'=>'cash','amount_received'=>100]);
fixture_add('sale_items',['sale_id'=>$sale,'unit_id'=>$unit,'product_id'=>$product,'item_name_snapshot'=>'Test Phone','identifier_snapshot'=>'TEST-UNIT','quantity'=>1,'unit_price'=>100,'line_total'=>100]);
fixture_add('stock_movements',['product_id'=>$product,'unit_id'=>$unit,'branch_id'=>$b1,'movement_type'=>'sold','quantity'=>-1,'reference_no'=>'TEST-SALE','created_by'=>$users['cashier']]);
$transfer=fixture_add('inventory_transfers',['reference_no'=>'TEST-TRANSFER','product_id'=>$product,'source_branch_id'=>$b1,'destination_branch_id'=>$b2,'quantity'=>1,'status'=>'received','forwarded_by'=>$users['cashier'],'received_by'=>$users['cashier']]);
$snapshotTables=['sales','sale_items','stock_movements','inventory_transfers','inventory_units'];
$history=[]; foreach($snapshotTables as $table) $history[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();
file_put_contents(__DIR__.'/fixtures.json',json_encode(compact('users','b1','b2','b3','sale','unit','transfer','history')));
echo 'Created empty schema with '.count($tables).' tables and '.count($triggers)." triggers; seeded synthetic data only.\n";
echo json_encode($pdo->query("SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='branches' AND COLUMN_NAME='name') OR (TABLE_NAME='users' AND COLUMN_NAME IN ('name','role','email')))")->fetchAll())."\n";
