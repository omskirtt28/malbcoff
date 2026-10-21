<?php
// Isolated fixture DB only. Never mutates malbcoff_pos or hosting.
putenv('DB_NAME=malbcoff_fix_20261020_v2');
require __DIR__.'/../../core/Database.php';
$config = require __DIR__.'/../../config/database.php';
if (!in_array($config['host'], ['localhost','127.0.0.1'], true) || $config['name'] !== 'malbcoff_fix_20261020_v2') throw new RuntimeException('Local isolated tests only');
$p = Database::connection();
for ($i=1;$i<=2;$i++) { $p->exec(file_get_contents(__DIR__.'/../../database/migrations/P3_007_inventory_delete_snapshots.sql')); echo "Migration pass $i OK\n"; }
foreach (['inventory_units','inventory_transfer_units','stock_movements'] as $table) echo $p->query("SHOW CREATE TABLE $table")->fetch()['Create Table']."\n";
echo "Identifier columns and indexes across tables:\n";
echo json_encode($p->query("SELECT c.TABLE_NAME,c.COLUMN_NAME,s.INDEX_NAME,s.NON_UNIQUE FROM information_schema.COLUMNS c LEFT JOIN information_schema.STATISTICS s ON s.TABLE_SCHEMA=c.TABLE_SCHEMA AND s.TABLE_NAME=c.TABLE_NAME AND s.COLUMN_NAME=c.COLUMN_NAME WHERE c.TABLE_SCHEMA=DATABASE() AND (c.COLUMN_NAME LIKE '%imei%' OR c.COLUMN_NAME LIKE '%serial%' OR c.COLUMN_NAME='identifier_snapshot')")->fetchAll())."\n";
if (is_file(__DIR__.'/fix8c2-fixtures.json')) exit("Fixtures already exist; remove only this generated manifest to seed a new run.\n");
function add8($table, $data) { Database::query('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($data)).'`) VALUES ('.implode(',',array_fill(0,count($data),'?')).')', array_values($data)); return (int)Database::connection()->lastInsertId(); }
$actor = (int)$p->query("SELECT id FROM users WHERE email='lifecycle-branch_manager@test.invalid'")->fetchColumn();
$admin = (int)$p->query("SELECT id FROM users WHERE email='lifecycle-system_admin@test.invalid'")->fetchColumn();
if (!$actor || !$admin) throw new RuntimeException('Run existing lifecycle fixture seed first');
$branches = $p->query('SELECT id FROM branches WHERE is_active=1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
if (count($branches)<4) throw new RuntimeException('Four active fixture branches required');
$brand = add8('brands', ['name'=>'Fix8C2 '.date('His'), 'is_active'=>1]);
$model = add8('product_models', ['brand_id'=>$brand,'name'=>'GALAXY TAB A11+ Fix8C2','device_type'=>'tablet','is_active'=>1]);
$template = $p->query("SELECT * FROM products WHERE product_type='tablet' AND is_active=1 LIMIT 1")->fetch();
if (!$template) $template=$p->query("SELECT * FROM products WHERE product_type='phone' AND is_active=1 LIMIT 1")->fetch();
unset($template['id']);
$product = add8('products', array_replace($template,['brand_id'=>$brand,'model_id'=>$model,'product_type'=>'tablet','connectivity'=>'5G','is_active'=>1,'catalog_deleted_at'=>null]));
$units=[];$transfers=[];$identifiers=[];
$cases=['single','bulk1','bulk2','all1','all2','all3','movement_only','void_only','sale','available'];
foreach ($cases as $n=>$case) {
    // Valid Luhn IMEIs; IMEI2 + serial exercise release of every live identifier column.
    $base='865123'.str_pad((string)(time()%1000000),6,'0',STR_PAD_LEFT).str_pad((string)$n,2,'0',STR_PAD_LEFT);
    $sum=0;foreach(str_split($base) as $i=>$digit){$v=(int)$digit;if($i%2===1){$v*=2;if($v>9)$v-=9;}$sum+=$v;}
    $imei=$base.(string)((10-$sum%10)%10);
    $status=in_array($case,['all1','bulk1','sale'],true)?'archived':($case==='available'?'available':'deleted');
    $u=['product_id'=>$product,'branch_id'=>(int)$branches[1],'created_by'=>$actor,'imei'=>$imei,'imei2'=>null,'serial_no'=>'FIX8C2-'.$imei,'status'=>$status,'deleted_at'=>date('Y-m-d H:i:s'),'deleted_by'=>$actor,'deleted_by_name'=>'Original fixture remover'];
    if($status==='archived'){$u['archived_imei']=$u['imei'];$u['archived_serial_no']=$u['serial_no'];$u['imei']=$u['serial_no']=null;$u['archived_at']=date('Y-m-d H:i:s');$u['archived_by']=$admin;}
    $id=add8('inventory_units',$u);$units[$case]=$id;$identifiers[$case]=$imei;
    $movement=function($branch,$type,$qty,$ref)use($product,$id,$actor){return add8('stock_movements',['product_id'=>$product,'unit_id'=>$id,'branch_id'=>$branch,'movement_type'=>$type,'quantity'=>$qty,'reference_no'=>$ref,'created_by'=>$actor]);};
    $movement($branches[0],'stock_in',1,'FIX8C2-STOCK-'.$id);
    if($case!=='movement_only') {
        $t=add8('inventory_transfers',['reference_no'=>'FIX8C2-TRANSFER-'.$id,'product_id'=>$product,'source_branch_id'=>$branches[0],'destination_branch_id'=>$branches[1],'quantity'=>1,'status'=>$case==='all2'?'pending':'received','forwarded_by'=>$actor,'received_by'=>$case==='all2'?null:$actor]);
        add8('inventory_transfer_units',['transfer_id'=>$t,'unit_id'=>$id]);$transfers[$case]=$t;
        $movement($branches[0],'transfer_out',-1,'FIX8C2-TRANSFER-'.$id);
        // Pending historical transfer deliberately retained; no fabricated receipt during cleanup.
        if($case!=='all2')$movement($branches[1],'transfer_in',1,'FIX8C2-TRANSFER-'.$id);
    } else Database::query('UPDATE inventory_units SET branch_id=? WHERE id=?',[$branches[0],$id]);
    if ($case!=='available') $outgoing=$movement($case==='movement_only'?$branches[0]:$branches[1],'adjustment',-1,'REMOVE-'.$id);
    if ($case==='void_only') add8('inventory_movement_voids',['movement_id'=>$outgoing,'unit_id'=>$id,'branch_id'=>$branches[1],'product_id'=>$product,'restored_quantity'=>1,'voided_amount'=>0,'reason'=>'Fixture history only','voided_by'=>$admin]);
    if ($case==='sale') {
        $sale=add8('sales',['sale_no'=>'FIX8C2-SALE-'.$id,'branch_id'=>$branches[1],'created_by'=>$actor,'subtotal'=>100,'total'=>100,'payment_method'=>'cash','amount_received'=>100]);
        add8('sale_items',['sale_id'=>$sale,'unit_id'=>$id,'product_id'=>$product,'item_name_snapshot'=>'GALAXY TAB A11+ Fix8C2','identifier_snapshot'=>$imei,'quantity'=>1,'unit_price'=>100,'line_total'=>100]);
    }
}
file_put_contents(__DIR__.'/fix8c2-fixtures.json',json_encode(compact('product','brand','units','identifiers','transfers','branches','sale')));
echo "Fix8C2 fixtures created.\n";
