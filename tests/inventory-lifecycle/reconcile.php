<?php
putenv('DB_NAME=malbcoff_fix_20261020_v2');ini_set('session.save_path',__DIR__.'/sessions');
require __DIR__.'/../../bootstrap.php';require __DIR__.'/../../core/StockMonitoringReport.php';
$_SESSION['user']=Database::query("SELECT * FROM users WHERE email='lifecycle-owner@test.invalid'")->fetch();
$checks=0;function check_result($v,$m){global $checks;if(!$v)throw new RuntimeException($m);$checks++;echo "PASS $m\n";}
$f=json_decode(file_get_contents(__DIR__.'/fixtures.json'),true);
foreach([$f['b1'],$f['b2'],null] as $branch){
 $params=['q'=>'Lifecycle'];if($branch)$params['branch']=$branch;
 $r=StockMonitoringReport::load($params);
 $a=StockMonitoringReport::load($params+['tab'=>'accessories']);
 $t=$r['overall_totals'];check_result($t['opening']+$t['stock_in']+$t['transfer_in']-$t['transfer_out']-$t['sold']+$t['other']===$t['ending'],'opening + in - out - sold + Other = ending / '.($branch??'all'));
 check_result($r['totals']['ending']+$a['totals']['ending']===$t['ending'],'Units + Accessories = combined ending / '.($branch??'all'));
 $branchSql=$branch?' AND iu.branch_id='.(int)$branch:'';$balanceSql=$branch?' AND ib.branch_id='.(int)$branch:'';
 $cat=inventory_catalog_product_sql('p');
 $live=(int)Database::query("SELECT COUNT(*) FROM inventory_units iu JOIN products p ON p.id=iu.product_id WHERE iu.status='available' AND $cat AND p.id IN (".implode(',',$r['product_ids']?:[0]).") $branchSql")->fetchColumn();
 $live+=(int)Database::query("SELECT COALESCE(SUM(ib.quantity),0) FROM inventory_balances ib JOIN products p ON p.id=ib.product_id WHERE $cat AND p.id IN (".implode(',',$r['product_ids']?:[0]).") $balanceSql")->fetchColumn();
 check_result($live===$t['ending'],'monitoring ending = actual available catalog stock / '.($branch??'all'));
 $sales=StockMonitoringReport::soldByType(date('Y-m-d 00:00:00'),date('Y-m-d 00:00:00',strtotime('+1 day')),$branch,null,'Lifecycle');
 check_result($sales['units']['qty']===$t['sold_units']&&$sales['accessories']['qty']===$t['sold_accessories'],'sales-item quantities match movement sold totals / '.($branch??'all'));
}
$_GET=[];ob_start();require __DIR__.'/../../pages/dashboard.php';ob_end_clean();
check_result($dbError===null,'Dashboard loads without database error');
$d=StockMonitoringReport::drawer(['kind'=>'overall','basis'=>'live']);check_result($d['total']===$stats['total_inventory'],'Dashboard live drawer matches current inventory card');
$dashboardStats=$stats;
$_GET=['from'=>date('Y-m-d'),'to'=>date('Y-m-d')];ob_start();require __DIR__.'/../../pages/sales-records.php';ob_end_clean();
check_result($dbError===null,'Sales Records loads without database error');
check_result(abs($dashboardStats['sales_today']-$totalSales)<0.001,'Dashboard sales amount = Sales Records total');
check_result($dashboardStats['units_sold_today']===$soldSplit['units']['qty']&&$dashboardStats['accessories_sold_today']===$soldSplit['accessories']['qty'],'Dashboard sold quantities = Sales Records');
ob_start();require __DIR__.'/../../pages/stock-movement.php';$movementHtml=ob_get_clean();check_result(str_contains($movementHtml,'Removed from inventory'),'Stock Movement shows removal label in history');
echo "ALL $checks RECONCILIATION CHECKS PASSED\n";
