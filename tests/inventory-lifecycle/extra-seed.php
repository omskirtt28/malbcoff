<?php
putenv('DB_NAME=malbcoff_fix_20261020_v2');require __DIR__.'/../../core/Database.php';
$f=json_decode(file_get_contents(__DIR__.'/fixtures.json'),true);$b=$f['b1'];
$uid=(int)Database::query("SELECT id FROM users WHERE email='lifecycle-branch_manager@test.invalid'")->fetchColumn();
$serial='490154203237518';
Database::query("INSERT INTO inventory_units(product_id,branch_id,imei,status,created_by) VALUES (?,?,?,'available',?)",[$f['products']['Delete'],$b,$serial,$uid]);$f['units']['numeric']=(int)Database::connection()->lastInsertId();
Database::query("INSERT INTO stock_movements(product_id,branch_id,unit_id,movement_type,quantity,created_by) VALUES (?,?,?,'stock_in',1,?)",[$f['products']['Delete'],$b,$f['units']['numeric'],$uid]);
$r=Database::query("SELECT * FROM products WHERE product_type='accessory' AND is_active=1 LIMIT 1")->fetch();unset($r['id']);$r['product_name']='Lifecycle Cable';$r['barcode']='LIFECYCLE-CABLE';$r['selling_price']=100;$r['catalog_deleted_at']=null;$r['is_active']=1;$r['model_id']=null;$r['brand_id']=null;
Database::query('INSERT INTO products (`'.implode('`,`',array_keys($r)).'`) VALUES ('.implode(',',array_fill(0,count($r),'?')).')',array_values($r));$pid=(int)Database::connection()->lastInsertId();$f['products']['Cable']=$pid;
Database::query('INSERT INTO inventory_balances(product_id,branch_id,quantity) VALUES (?,?,1)',[$pid,$b]);
Database::query("INSERT INTO stock_movements(product_id,branch_id,movement_type,quantity,created_by) VALUES (?,?,'stock_in',1,?)",[$pid,$b,$uid]);
file_put_contents(__DIR__.'/fixtures.json',json_encode($f));echo "Added numeric IMEI and accessory fixtures.\n";
