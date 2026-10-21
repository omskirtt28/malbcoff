<?php
require __DIR__.'/../../core/Database.php';
$config=require __DIR__.'/../../config/database.php';
if(!in_array($config['host'],['localhost','127.0.0.1'],true)||$config['name']!=='malbcoff_pos')throw new RuntimeException('Expected local malbcoff_pos only');
$p=Database::connection();
$tables=['inventory_transfer_units','stock_movements'];
$backup=__DIR__.'/../../backups/inventory-before-P3_007-'.date('Ymd-His').'.sql';
$out="-- Local pre-P3_007 backup. No hosting access. Restore after reviewing foreign keys.\n";
foreach($tables as $table){
 $out.=$p->query("SHOW CREATE TABLE `$table`")->fetch()['Create Table'].";\n";
 foreach($p->query("SELECT * FROM `$table`")->fetchAll() as $row){$values=array_map(static fn($v)=>$v===null?'NULL':$p->quote((string)$v),array_values($row));$out.='INSERT INTO `'.$table.'` (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',$values).");\n";}
}
if(file_put_contents($backup,$out)===false)throw new RuntimeException('Backup failed');
$state=static fn()=>[
 'units'=>$p->query('SELECT id,status,imei,imei2,serial_no FROM inventory_units ORDER BY id')->fetchAll(),
 'transfers'=>$p->query('SELECT * FROM inventory_transfers ORDER BY id')->fetchAll(),
 'ledger'=>$p->query('SELECT product_id,branch_id,movement_type,SUM(quantity) qty,COUNT(*) n FROM stock_movements GROUP BY product_id,branch_id,movement_type ORDER BY product_id,branch_id,movement_type')->fetchAll(),
];
$before=$state();$p->exec(file_get_contents(__DIR__.'/../../database/migrations/P3_007_inventory_delete_snapshots.sql'));
if($before!==$state())throw new RuntimeException('Unexpected data changes');
echo 'Local schema migration applied; unit rows/identifiers, transfers and ledger counts unchanged. Backup: '.basename($backup)."\n";
