<?php
putenv('DB_NAME=malbcoff_fix_20261020_v2');
require __DIR__.'/../../core/Database.php';
$config=require __DIR__.'/../../config/database.php';
if (!in_array($config['host'],['localhost','127.0.0.1'],true) || $config['name']!=='malbcoff_fix_20261020_v2') throw new RuntimeException('Isolated local DB only');
$f=json_decode(file_get_contents(__DIR__.'/fix8c2-fixtures.json'),true);
if(isset($f['extra']))exit("Extra fixtures already seeded.\n");
$p=Database::connection();$actor=(int)$p->query("SELECT id FROM users WHERE email='lifecycle-branch_manager@test.invalid'")->fetchColumn();
$extra=[];
for($i=0;$i<55;$i++){
 Database::query("INSERT INTO inventory_units (product_id,branch_id,created_by,status,serial_no,deleted_at,deleted_by,deleted_by_name) VALUES (?,?,?,'deleted',?,NOW(),?,'Original extra remover')",[$f['product'],$f['branches'][0],$actor,'FIX8C2-EXTRA-'.$i,$actor]);
 $extra[]=(int)$p->lastInsertId();
}
// Exercise a fresh sales archive, rather than an already archived row.
$saleUnit=$f['units']['sale'];
Database::query("UPDATE inventory_units SET imei=archived_imei,serial_no=archived_serial_no,imei2='865123456789013',archived_imei=NULL,archived_serial_no=NULL,status='deleted',archived_at=NULL,archived_by=NULL WHERE id=?",[$saleUnit]);
$f['extra']=$extra;file_put_contents(__DIR__.'/fix8c2-fixtures.json',json_encode($f));
echo "55 extra deleted units and fresh sales archive scenario seeded.\n";
