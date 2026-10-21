<?php
putenv('DB_NAME=malbcoff_fix_20261020_v2');
ini_set('session.save_path',__DIR__.'/sessions');
require __DIR__.'/../../bootstrap.php';
require __DIR__.'/../../core/StockMonitoringReport.php';
$originalUser=$_SESSION['user']??null;
$_SESSION['user']=Database::query("SELECT * FROM users WHERE email='lifecycle-owner@test.invalid'")->fetch();
$f=json_decode(file_get_contents(__DIR__.'/fix8c2-fixtures.json'),true);
$units=Database::query('SELECT * FROM inventory_units WHERE product_id=? ORDER BY id',[$f['product']])->fetchAll();
$transfers=Database::query('SELECT * FROM inventory_transfers WHERE product_id=? ORDER BY id',[$f['product']])->fetchAll();
$lines=Database::query('SELECT itu.* FROM inventory_transfer_units itu JOIN inventory_transfers t ON t.id=itu.transfer_id WHERE t.product_id=? ORDER BY itu.id',[$f['product']])->fetchAll();
$movements=Database::query('SELECT * FROM stock_movements WHERE product_id=? ORDER BY id',[$f['product']])->fetchAll();
$audits=Database::query("SELECT * FROM inventory_unit_delete_audits WHERE unit_id IN (".implode(',',array_map('intval',$f['units'])).") ORDER BY id")->fetchAll();
$totals=[];
foreach([null,...$f['branches']] as $branch) {
    $params=['q'=>'Fix8C2'];if($branch)$params['branch']=$branch;
    $report=StockMonitoringReport::load($params);
    $totals[$branch??'all']=$report['overall_totals'];
}
$buckets=Database::query('SELECT product_id,branch_id,DATE(created_at) date,movement_type,SUM(quantity) qty,COUNT(*) n FROM stock_movements GROUP BY product_id,branch_id,DATE(created_at),movement_type ORDER BY product_id,branch_id,date,movement_type')->fetchAll();
$sale=Database::query('SELECT * FROM sales WHERE id=?',[$f['sale']])->fetch();
$saleItems=Database::query('SELECT * FROM sale_items WHERE sale_id=?',[$f['sale']])->fetchAll();
$voids=Database::query('SELECT * FROM inventory_movement_voids WHERE unit_id=?',[$f['units']['void_only']])->fetchAll();
$dashboardLive=(int)Database::query("SELECT COUNT(*) FROM inventory_units WHERE status='available'")->fetchColumn();
$_SESSION['user']=$originalUser;
echo json_encode(compact('units','transfers','lines','movements','audits','totals','buckets','sale','saleItems','voids','dashboardLive'));
