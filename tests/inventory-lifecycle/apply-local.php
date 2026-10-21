<?php
require __DIR__.'/../../core/Database.php';
$config=require __DIR__.'/../../config/database.php';
if(!in_array($config['host'],['127.0.0.1','localhost'],true)||$config['name']!=='malbcoff_pos')throw new RuntimeException('Refusing migration: expected local malbcoff_pos database.');
$p=Database::connection();$backup=__DIR__.'/../../backups/inventory-before-P3_006-20261020.sql';
if(!is_file($backup)){
 $out="-- Local backup before P3_006, units + their movement history. No hosting access.\n";
 foreach(['inventory_units','stock_movements'] as $table){
  $out.=$p->query("SHOW CREATE TABLE `$table`")->fetch()['Create Table'].";\n";
  foreach($p->query("SELECT * FROM `$table`")->fetchAll() as $row){
   $values=array_map(static fn($v)=>$v===null?'NULL':$p->quote((string)$v),array_values($row));
   $out.='INSERT INTO `'.$table.'` (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',$values).");\n";
  }
 }
 if(file_put_contents($backup,$out)===false)throw new RuntimeException('Backup failed.');
}
$before=(int)$p->query("SELECT COUNT(*) FROM inventory_units WHERE status='available'")->fetchColumn();
$p->exec(file_get_contents(__DIR__.'/../../deploy/migrations.sql'));
$after=(int)$p->query("SELECT COUNT(*) FROM inventory_units WHERE status='available'")->fetchColumn();
if($after!==$before)throw new RuntimeException('Unexpected available stock change.');
echo 'Local migration applied. Available units unchanged: '.$after.'. Backfilled deleted rows: '.$p->query("SELECT COUNT(*) FROM inventory_units WHERE status='deleted'")->fetchColumn()."\n";
echo 'Backup SHA256: '.hash_file('sha256',$backup)."\n";
