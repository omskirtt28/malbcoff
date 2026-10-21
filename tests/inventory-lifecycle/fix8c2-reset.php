<?php
// Restore ONLY this test run's generated units from their test audit, after an interrupted run.
putenv('DB_NAME=malbcoff_fix_20261020_v2');
require __DIR__.'/../../core/Database.php';
$config=require __DIR__.'/../../config/database.php';
if (!in_array($config['host'],['localhost','127.0.0.1'],true) || $config['name']!=='malbcoff_fix_20261020_v2') throw new RuntimeException('Isolated local DB only');
$f=json_decode(file_get_contents(__DIR__.'/fix8c2-fixtures.json'),true);
$p=Database::connection();$p->beginTransaction();
$columns=array_flip($p->query('SHOW COLUMNS FROM inventory_units')->fetchAll(PDO::FETCH_COLUMN));
foreach($f['units'] as $id){
 if(Database::query('SELECT id FROM inventory_units WHERE id=?',[$id])->fetchColumn())continue;
 $a=Database::query("SELECT * FROM inventory_unit_delete_audits WHERE unit_id=? AND operation='permanent_delete' ORDER BY id DESC LIMIT 1",[$id])->fetch();
 if(!$a)throw new RuntimeException('Missing fixture audit');
 $u=array_intersect_key(json_decode($a['snapshot_json'],true)['unit'],$columns);
 Database::query('INSERT INTO inventory_units (`'.implode('`,`',array_keys($u)).'`) VALUES ('.implode(',',array_fill(0,count($u),'?')).')',array_values($u));
 foreach(['inventory_transfer_units','stock_movements'] as $t)Database::query("UPDATE $t SET unit_id=deleted_unit_id,deleted_unit_id=NULL,unit_snapshot=NULL WHERE deleted_unit_id=?",[$id]);
 Database::query('DELETE FROM inventory_unit_delete_audits WHERE id=?',[$a['id']]);
}
$id=$f['units']['void_only'];
Database::query("UPDATE inventory_units SET imei=COALESCE(imei,archived_imei),serial_no=COALESCE(serial_no,archived_serial_no),status='deleted',archived_imei=NULL,archived_serial_no=NULL,archived_at=NULL,archived_by=NULL WHERE id=?",[$id]);
Database::query("DELETE FROM inventory_unit_delete_audits WHERE unit_id=? AND operation='archive'",[$id]);
$p->commit();echo "Restored interrupted fixture deletions only.\n";
