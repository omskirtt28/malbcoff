<?php
$scope=current_branch_scope();
$whereUnit=$scope?' WHERE iu.branch_id=:branch ':'';$whereBalance=$scope?' WHERE ib.branch_id=:branch ':'';$params=$scope?['branch'=>$scope]:[];
$stats=['total_inventory'=>0,'phones'=>0,'tablets'=>0,'preloved'=>0,'accessories'=>0,'stock_in_today'=>0,'stock_out_today'=>0];
$recent=[];$branchSummary=[];$dbError=null;
try{
    $unitTotal=(int)Database::query("SELECT COUNT(*) FROM inventory_units iu JOIN products p ON p.id=iu.product_id {$whereUnit} ".($whereUnit?'AND':'WHERE')." iu.status='available'",$params)->fetchColumn();
    $accessoryTotal=(int)Database::query("SELECT COALESCE(SUM(ib.quantity),0) FROM inventory_balances ib JOIN products p ON p.id=ib.product_id {$whereBalance} ".($whereBalance?'AND':'WHERE')." p.product_type='accessory'",$params)->fetchColumn();
    $deviceCounts=Database::query("SELECT p.product_type,COUNT(*) qty FROM inventory_units iu JOIN products p ON p.id=iu.product_id {$whereUnit} ".($whereUnit?'AND':'WHERE')." iu.status='available' GROUP BY p.product_type",$params)->fetchAll();
    foreach($deviceCounts as $row){if($row['product_type']==='phone')$stats['phones']=(int)$row['qty'];if($row['product_type']==='tablet')$stats['tablets']=(int)$row['qty'];}
    $stats['preloved']=(int)Database::query("SELECT COUNT(*) FROM inventory_units iu {$whereUnit} ".($whereUnit?'AND':'WHERE')." iu.status='available' AND iu.condition_type='preloved'",$params)->fetchColumn();
    $stats['accessories']=$accessoryTotal;$stats['total_inventory']=$unitTotal+$accessoryTotal;

    $movementWhere=$scope?' AND sm.branch_id=:branch':'';
    $stats['stock_in_today']=(int)Database::query("SELECT COALESCE(SUM(CASE WHEN quantity>0 THEN quantity ELSE 0 END),0) FROM stock_movements sm WHERE movement_type IN ('stock_in','transfer_in','return') AND DATE(created_at)=CURDATE() {$movementWhere}",$params)->fetchColumn();
    $stats['stock_out_today']=abs((int)Database::query("SELECT COALESCE(SUM(CASE WHEN quantity<0 THEN quantity ELSE 0 END),0) FROM stock_movements sm WHERE movement_type IN ('stock_out','sale','transfer_out','defective') AND DATE(created_at)=CURDATE() {$movementWhere}",$params)->fetchColumn());

    $recent=Database::query(
        "SELECT sm.created_at,sm.movement_type,sm.quantity,b.name branch_name,COALESCE(CONCAT(br.name,' ',pm.name),p.product_name) product_label,p.ram,p.storage,p.connectivity,p.color
         FROM stock_movements sm JOIN products p ON p.id=sm.product_id LEFT JOIN brands br ON br.id=p.brand_id LEFT JOIN product_models pm ON pm.id=p.model_id JOIN branches b ON b.id=sm.branch_id
         WHERE 1=1 {$movementWhere} ORDER BY sm.created_at DESC LIMIT 6",$params)->fetchAll();

    if(Auth::isOwner())$branchSummary=Database::query("SELECT b.id,b.name,COALESCE((SELECT COUNT(*) FROM inventory_units iu WHERE iu.branch_id=b.id AND iu.status='available'),0)+COALESCE((SELECT SUM(ib.quantity) FROM inventory_balances ib WHERE ib.branch_id=b.id),0) qty FROM branches b WHERE b.is_active=1 ORDER BY b.id")->fetchAll();
}catch(Throwable $e){$dbError='Dashboard data will appear after the P1-004 migration is applied.';}

function dashboard_specs(array $row):string{$parts=[];foreach(['ram','storage','connectivity','color'] as $key)if(!empty($row[$key]))$parts[]=$row[$key];return implode(' • ',$parts);}
?>
<section class="page-heading"><div><span class="eyebrow"><?= Auth::isOwner()?'OWNER VIEW':'BRANCH VIEW' ?></span><h1><?= Auth::isOwner()?'Owner Dashboard':'Branch Dashboard' ?></h1><p><?= Auth::isOwner()?'Monitor inventory and stock activity across all four branches.':'A quick view of your branch inventory and recent stock activity.' ?></p></div><a class="btn btn-primary" href="index.php?page=stock-in"><?= icon('stock') ?> Stock In</a></section>
<?php if($dbError): ?><div class="alert alert-info"><?= e($dbError) ?></div><?php endif; ?>
<div class="quick-search card compact"><?= icon('search') ?><input type="search" placeholder="Quick search products, models, IMEI, serial, barcode…" data-global-search></div>
<div class="kpi-grid owner">
<article class="kpi-card tint-blue"><div class="kpi-icon"><?= icon('inventory') ?></div><span>Total Inventory</span><strong><?= number_format($stats['total_inventory']) ?></strong><small>Units</small></article>
<article class="kpi-card tint-green"><div class="kpi-icon"><?= icon('phone') ?></div><span>Phones Available</span><strong><?= number_format($stats['phones']) ?></strong><small>Units</small></article>
<article class="kpi-card tint-purple"><div class="kpi-icon"><?= icon('tablet') ?></div><span>Tablets Available</span><strong><?= number_format($stats['tablets']) ?></strong><small>Units</small></article>
<article class="kpi-card tint-violet"><div class="kpi-icon"><?= icon('accessory') ?></div><span>Accessories Stock</span><strong><?= number_format($stats['accessories']) ?></strong><small>Units</small></article>
<article class="kpi-card tint-amber"><div class="kpi-icon"><?= icon('tag') ?></div><span>Pre-Loved Units</span><strong><?= number_format($stats['preloved']) ?></strong><small>Condition</small></article>
<article class="kpi-card tint-red"><div class="kpi-icon"><?= icon('stock') ?></div><span>Stock In Today</span><strong><?= number_format($stats['stock_in_today']) ?></strong><small>Units</small></article>
</div>
<div class="dashboard-grid">
<section class="card"><div class="card-header"><div><h2>Recent Stock Activity</h2><p>Latest inventory movements.</p></div><a href="index.php?page=stock-movement">View All</a></div><div class="activity-list">
<?php if(!$recent): ?><div class="empty-state"><div class="empty-icon"><?= icon('movement') ?></div><strong>No stock activity yet</strong><span>Your latest Stock In / Out records will appear here.</span></div>
<?php else: foreach($recent as $item): ?><div class="activity-row"><span class="activity-dot <?= $item['quantity']>=0?'in':'out' ?>"></span><div class="activity-main"><strong><?= e(movement_label($item['movement_type'])) ?> · <?= e($item['product_label']) ?></strong><span><?= e(dashboard_specs($item)) ?><?= dashboard_specs($item)?' · ':'' ?><?= e($item['branch_name']) ?></span></div><div class="activity-meta"><strong class="<?= $item['quantity']>=0?'text-success':'text-danger' ?>"><?= $item['quantity']>=0?'+':'' ?><?= (int)$item['quantity'] ?></strong><span><?= e(date('M d, h:i A',strtotime($item['created_at']))) ?></span></div></div><?php endforeach; endif; ?>
</div></section>
<section class="card"><div class="card-header"><div><h2><?= Auth::isOwner()?'Branch Inventory':'Stock by Item Type' ?></h2><p><?= Auth::isOwner()?'Current available units per branch.':'Current inventory distribution.' ?></p></div></div>
<?php if(Auth::isOwner()): ?><div class="branch-bars"><?php $max=1;foreach($branchSummary as $row)$max=max($max,(int)$row['qty']);foreach($branchSummary as $row):$width=max(4,round(((int)$row['qty']/$max)*100)); ?><div class="branch-bar-row"><div><strong><?= e($row['name']) ?></strong><span><?= number_format((int)$row['qty']) ?> units</span></div><div class="bar-track"><span style="width:<?= $width ?>%"></span></div></div><?php endforeach; ?></div>
<?php else: $total=max(1,$stats['phones']+$stats['tablets']+$stats['accessories']); ?><div class="category-summary"><div class="donut" style="--phone:<?= round(($stats['phones']/$total)*360) ?>deg;--pre:<?= round((($stats['phones']+$stats['tablets'])/$total)*360) ?>deg"><div><strong><?= number_format($stats['total_inventory']) ?></strong><span>Total Units</span></div></div><div class="legend-list"><span><i class="legend blue"></i> Phones <strong><?= number_format($stats['phones']) ?></strong></span><span><i class="legend green"></i> Tablets <strong><?= number_format($stats['tablets']) ?></strong></span><span><i class="legend purple"></i> Accessories <strong><?= number_format($stats['accessories']) ?></strong></span><span><i class="legend"></i> Pre-Loved <strong><?= number_format($stats['preloved']) ?></strong></span></div></div><?php endif; ?>
</section></div>
