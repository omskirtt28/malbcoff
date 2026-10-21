<?php
require __DIR__.'/../../core/Database.php';
$config=require __DIR__.'/../../config/database.php';
if(!in_array($config['host'],['127.0.0.1','localhost'],true))throw new RuntimeException('Tests require local database');
$p=Database::connection();
$test='malbcoff_fix_20261020_v2';
$exists=$p->query("SHOW DATABASES LIKE '$test'")->fetchColumn();
if(!$exists){
 $p->exec("CREATE DATABASE `$test` CHARACTER SET utf8mb4");
 $p->exec('SET FOREIGN_KEY_CHECKS=0');
 $tables=$p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
 foreach($tables as $t){$create=$p->query("SHOW CREATE TABLE `$t`")->fetch()['Create Table'];$create=preg_replace('/^CREATE TABLE `'.preg_quote($t,'/').'`/',"CREATE TABLE `$test`.`$t`",$create);$p->exec($create);}
 foreach($tables as $t)$p->exec("INSERT INTO `$test`.`$t` SELECT * FROM `{$config['name']}`.`$t`");
 $p->exec('SET FOREIGN_KEY_CHECKS=1');
 echo 'Cloned '.count($tables)." local tables into isolated database.\n";
}
$p->exec("USE `$test`");
for($i=0;$i<2;$i++){$p->exec(file_get_contents(__DIR__.'/../../database/migrations/P3_006_inventory_soft_delete.sql'));echo 'Migration pass '.($i+1)." succeeded.\n";}
echo json_encode($p->query("SELECT status,COUNT(*) n FROM inventory_units GROUP BY status")->fetchAll())."\n";
