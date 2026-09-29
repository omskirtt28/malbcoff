<?php
$mobileUser = Auth::user();
$isSystemAdminControl = Auth::isSystemAdmin() && !Auth::isImpersonating();
if ($isSystemAdminControl) {
    $mobileItems = [
        ['system-admin-dashboard','dashboard','Home'],
        ['system-admin-users','users','Accounts'],
        ['system-admin-history','movement','History'],
        ['system-admin-data-reset','trash','Data Reset'],
        ['security-logs','shield','Logs'],
    ];
    $primary = array_slice($mobileItems, 0, 4);
    $secondary = array_slice($mobileItems, 4);
    $mobileContext = 'System Control';
    $mobilePendingTransferCount = 0;
} else {
    $mobileItems = [['dashboard','dashboard','Home']];
    if (!Auth::isOwner() && pos_role_allowed()) $mobileItems[] = ['pos','pos','POS'];
    if (Auth::isOwner() || in_array($mobileUser['role'], ['branch_manager','cashier'], true)) $mobileItems[] = ['sales-records','receipt','Sales'];
    if (!Auth::isOwner()) $mobileItems[] = ['products','products','Products'];
    $mobileItems[] = ['inventory','inventory','Inventory'];
    if (Auth::isOwner() || in_array((string)($mobileUser['role'] ?? ''), ['branch_manager','inventory'], true)) $mobileItems[] = ['stock-monitoring','receipt','Stock Monitoring'];
    if (!Auth::isOwner() && in_array((string)($mobileUser['role'] ?? ''), ['branch_manager','inventory'], true)) $mobileItems[] = ['stock-in','stock','Receive Stock'];
    if (Auth::isOwner() || in_array((string)($mobileUser['role'] ?? ''), ['branch_manager','inventory'], true)) $mobileItems[] = ['branch-transfers','movement','Transfers'];
    if (Auth::isOwner() || in_array($mobileUser['role'], ['branch_manager','inventory'], true)) $mobileItems[] = ['stock-movement','movement','Stock Movement'];
    if (Auth::isOwner() || ($mobileUser['role'] ?? '') === 'branch_manager') $mobileItems[] = ['users','users','Users'];
    $primarySlugs = Auth::isOwner() ? ['dashboard','sales-records','inventory'] : (pos_role_allowed() ? ['dashboard','pos','sales-records','products'] : ['dashboard','products','inventory','stock-in']);
    $primary=[];$secondary=[];foreach($mobileItems as $item){if(in_array($item[0],$primarySlugs,true))$primary[]=$item;else$secondary[]=$item;}
    $mobileContext = Auth::isOwner() ? 'Owner View' : ($mobileUser['branch_name'] ?? 'Assigned Branch');
    $mobilePendingTransferCount=0;
    if (!Auth::isOwner() && in_array((string)($mobileUser['role'] ?? ''), ['branch_manager','inventory'], true) && (Auth::branchId() ?: 0) > 0) {
        try { if (Database::query("SHOW TABLES LIKE 'inventory_transfers'")->fetchColumn()) $mobilePendingTransferCount=(int)Database::query("SELECT COUNT(*) FROM inventory_transfers WHERE destination_branch_id=? AND status='pending'",[Auth::branchId()])->fetchColumn(); } catch(Throwable $e){}
    }
}
?>
<nav class="mobile-bottom-nav" aria-label="Mobile primary navigation">
<?php foreach ($primary as [$slug,$iconName,$label]): $active=$page===$slug||($slug==='products'&&$page==='add-item'); ?>
<a href="<?= e(app_url($slug)) ?>" class="mobile-nav-item <?= $active?'active':'' ?>"><?= icon($iconName) ?><span><?= e($label) ?></span></a>
<?php endforeach; ?>
<?php if ($secondary): ?><button type="button" class="mobile-nav-item <?= in_array($page,array_column($secondary,0),true)?'active':'' ?>" data-mobile-more-open><?= icon('more') ?><span>More</span></button><?php endif; ?>
</nav>
<?php if ($secondary): ?>
<div class="mobile-more-sheet" data-mobile-more hidden><button class="mobile-more-backdrop" type="button" data-mobile-more-close aria-label="Close menu"></button><section class="mobile-more-panel" role="dialog" aria-modal="true" aria-label="More navigation"><header><div><span class="eyebrow">NAVIGATION</span><strong>More</strong><small><?= e($mobileContext) ?></small></div><button class="icon-button" type="button" data-mobile-more-close aria-label="Close menu"><?= icon('close') ?></button></header><div class="mobile-more-links"><?php foreach($secondary as [$slug,$iconName,$label]):$active=$page===$slug||($slug==='products'&&$page==='add-item');?><a href="<?= e(app_url($slug)) ?>" class="<?= $active?'active':'' ?>"><?= icon($iconName) ?><span><?= e($label) ?></span><?php if($slug==='branch-transfers'&&$mobilePendingTransferCount>0):?><b class="nav-count"><?= $mobilePendingTransferCount>99?'99+':(int)$mobilePendingTransferCount ?></b><?php endif;?><?= icon('chevron') ?></a><?php endforeach;?></div><a class="mobile-more-signout" href="<?= e(app_url('logout')) ?>"><?= icon('logout') ?><span>Sign Out</span></a></section></div>
<?php endif; ?>
