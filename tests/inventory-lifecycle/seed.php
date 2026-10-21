<?php
putenv('DB_NAME=malbcoff_fix_20261020_v2');
require __DIR__.'/../../core/Database.php';
$p=Database::connection();
function insert_row($t,$values){$cols=array_keys($values);Database::query('INSERT INTO '.$t.' (`'.implode('`,`',$cols).'`) VALUES ('.implode(',',array_fill(0,count($cols),'?')).')',array_values($values));return (int)Database::connection()->lastInsertId();}
function clone_row($t,$id,$overrides){$r=Database::query("SELECT * FROM $t WHERE id=?",[$id])->fetch();unset($r['id']);return insert_row($t,array_replace($r,$overrides));}
$bids=$p->query('SELECT id FROM branches WHERE is_active=1 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);[$b1,$b2]=$bids;
$uid=0;
foreach(['owner','system_admin','branch_manager','inventory','cashier'] as $role){$email='lifecycle-'.$role.'@test.invalid';$id=Database::query('SELECT id FROM users WHERE email=?',[$email])->fetchColumn();if(!$id)$id=insert_row('users',['branch_id'=>in_array($role,['owner','system_admin'])?null:$b1,'name'=>'Lifecycle '.$role,'email'=>$email,'role'=>$role,'password_hash'=>password_hash(bin2hex(random_bytes(10)),PASSWORD_DEFAULT),'is_active'=>1]);if($role==='branch_manager')$uid=$id;}
if(is_file(__DIR__.'/fixtures.json')){echo "Fixtures already exist.\n";exit;}
$brand=insert_row('brands',['name'=>'Lifecycle Test Brand','is_active'=>1]);
$source=(int)$p->query("SELECT id FROM products WHERE product_type='phone' AND is_active=1 LIMIT 1")->fetchColumn();
$products=[];
foreach(['Delete','SaleLast','TransferLast','Never','CatalogRemoved','ZeroOld'] as $label){$model=insert_row('product_models',['brand_id'=>$brand,'name'=>'Lifecycle '.$label,'device_type'=>'phone','is_active'=>1]);$products[$label]=clone_row('products',$source,['brand_id'=>$brand,'model_id'=>$model,'is_active'=>$label==='CatalogRemoved'?0:1,'catalog_deleted_at'=>null,'selling_price'=>100]);}
$yesterday=date('Y-m-d 10:00:00',strtotime('-1 day'));
function unit($pid,$bid,$uid,$identifier,$status='available'){return insert_row('inventory_units',['product_id'=>$pid,'branch_id'=>$bid,'created_by'=>$uid,'imei'=>$identifier,'imei2'=>$identifier.'2','serial_no'=>'SN-'.$identifier,'status'=>$status]);}
function move($pid,$bid,$uid,$unit,$type,$qty,$time=null){$r=['product_id'=>$pid,'branch_id'=>$bid,'created_by'=>$uid,'unit_id'=>$unit,'movement_type'=>$type,'quantity'=>$qty,'reference_no'=>'FIXTURE-'.bin2hex(random_bytes(5))];if($time)$r['created_at']=$time;return insert_row('stock_movements',$r);}
$units=[];
foreach(['plain','history'] as $kind){$id=unit($products['Delete'],$b1,$uid,'FIX-'.$kind);$units[$kind]=$id;move($products['Delete'],$b1,$uid,$id,'stock_in',1);}
// Real sales and transfer references on an available returned unit.
$sale=insert_row('sales',['sale_no'=>'LIFECYCLE-HISTORY','branch_id'=>$b1,'created_by'=>$uid,'subtotal'=>100,'total'=>100,'payment_method'=>'cash','amount_received'=>100]);
insert_row('sale_items',['sale_id'=>$sale,'unit_id'=>$units['history'],'product_id'=>$products['Delete'],'item_name_snapshot'=>'Lifecycle Delete','identifier_snapshot'=>'FIX-history','quantity'=>1,'unit_price'=>100,'line_total'=>100]);
move($products['Delete'],$b1,$uid,$units['history'],'sale',-1);move($products['Delete'],$b1,$uid,$units['history'],'return',1);
$transfer=insert_row('inventory_transfers',['reference_no'=>'LIFECYCLE-OLD-TRANSFER','product_id'=>$products['Delete'],'source_branch_id'=>$b2,'destination_branch_id'=>$b1,'quantity'=>1,'status'=>'received','forwarded_by'=>$uid]);
insert_row('inventory_transfer_units',['transfer_id'=>$transfer,'unit_id'=>$units['history']]);
foreach(['SaleLast','TransferLast','CatalogRemoved'] as $label){$units[$label]=unit($products[$label],$b1,$uid,'FIX-'.$label);move($products[$label],$b1,$uid,$units[$label],'stock_in',1,$yesterday);}
$units['ZeroOld']=unit($products['ZeroOld'],$b1,$uid,'FIX-ZeroOld','sold');move($products['ZeroOld'],$b1,$uid,$units['ZeroOld'],'stock_in',1,$yesterday);move($products['ZeroOld'],$b1,$uid,$units['ZeroOld'],'sale',-1,$yesterday);
$units['no_refs']=unit($products['Delete'],$b1,$uid,'FIX-no-refs','deleted');Database::query('UPDATE inventory_units SET deleted_at=NOW(),deleted_by=? WHERE id=?',[$uid,$units['no_refs']]);
for($n=0;$n<30;$n++){ $id=unit($products['Delete'],$b1,$uid,'FIX-bulk-'.$n,'deleted');Database::query('UPDATE inventory_units SET deleted_at=NOW(),deleted_by=? WHERE id=?',[$uid,$id]); }
file_put_contents(__DIR__.'/fixtures.json',json_encode(compact('b1','b2','products','units','brand')));echo "Created lifecycle fixtures.\n";
