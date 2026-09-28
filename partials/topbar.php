<?php
$user = Auth::user();
$isSystemAdminControl = Auth::isSystemAdmin() && !Auth::isImpersonating();
$branches = [];
try { $branches = Database::query('SELECT id,name FROM branches WHERE is_active=1 ORDER BY id')->fetchAll(); } catch (Throwable $e) { $branches = []; }
$scopeBranchId = current_branch_scope();
$scopeName = $isSystemAdminControl ? 'System Control' : 'All Branches';
if (!$isSystemAdminControl && !Auth::isOwner()) $scopeName = $user['branch_name'] ?? 'Assigned Branch';
elseif (!$isSystemAdminControl && $scopeBranchId) {
    foreach ($branches as $branch) if ((int)$branch['id'] === $scopeBranchId) { $scopeName = $branch['name']; break; }
}
$imp = Auth::impersonationContext();
$actor = Auth::actorUser();
?>
<?php if (Auth::isImpersonating()): ?>
<div class="impersonation-banner" role="status">
    <div><?= icon('shield') ?><span><strong>SYSTEM ADMIN MODE</strong> Viewing as <?= e($user['name'] ?? 'User') ?><?= !empty($user['branch_name']) ? ' / ' . e($user['branch_name']) : '' ?> · <?= e(role_label($user['role'] ?? '')) ?></span></div>
    <form method="post" action="actions/impersonation.php">
        <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="end">
        <button class="btn btn-secondary btn-sm" type="submit">Return to System Admin</button>
    </form>
</div>
<?php endif; ?>
<header class="topbar">
    <button class="sidebar-toggle" type="button" data-sidebar-toggle aria-label="Open navigation"><?= icon('menu') ?></button>
    <a class="mobile-brand" href="index.php?page=<?= $isSystemAdminControl ? 'system-admin-dashboard' : 'dashboard' ?>" aria-label="Malbcoff Trading dashboard"><span class="brand-mark small">M</span><span class="mobile-brand-copy"><strong><?= e($app['name']) ?></strong><small><?= e($isSystemAdminControl ? 'System Administration' : $app['subtitle']) ?></small></span></a>

    <?php if (!$isSystemAdminControl): ?>
    <label class="topbar-search" aria-label="Global inventory search"><?= icon('search') ?><input type="search" data-global-search autocomplete="off" placeholder="Search products, models, IMEI, serial, barcode…"><kbd>Enter</kbd></label>
    <?php else: ?><div class="topbar-spacer"></div><?php endif; ?>

    <div class="topbar-actions">
        <?php if (!empty($app['maintenance_mode']) && (Auth::isOwner() || Auth::actorIsSystemAdmin())): ?><span class="maintenance-owner-chip"><?= icon('alert') ?> Maintenance Mode</span><?php endif; ?>
        <?php if (!$isSystemAdminControl && Auth::isOwner() && !in_array((string)($page ?? ''), ['products','users'], true)): ?>
            <div class="branch-switcher"><?= icon('branch') ?><select aria-label="Branch scope" onchange="window.location='<?= e(owner_branch_filter_url($page, null)) ?>' + (this.value ? '&branch=' + this.value : '')"><option value="">All Branches</option><?php foreach ($branches as $branch): ?><option value="<?= (int)$branch['id'] ?>" <?= $scopeBranchId === (int)$branch['id'] ? 'selected' : '' ?>><?= e($branch['name']) ?></option><?php endforeach; ?></select></div>
        <?php else: ?><div class="branch-chip topbar-branch-chip"><?= icon($isSystemAdminControl ? 'shield' : 'branch') ?><span><?= e($scopeName) ?></span></div><?php endif; ?>

        <details class="notification-dropdown">
            <summary class="topbar-icon-button" aria-label="Notifications" title="Notifications"><?= icon('bell') ?></summary>
            <div class="notification-dropdown-menu"><strong>Notifications</strong><span>No new notifications.</span></div>
        </details>

        <details class="user-dropdown"><summary class="user-menu" aria-label="User menu"><div class="avatar"><?= e(strtoupper(substr($user['name'] ?? 'U', 0, 1))) ?></div><div class="user-copy"><strong><?= e($user['name'] ?? 'User') ?></strong><span><?= e(role_label($user['role'] ?? '')) ?></span></div><?= icon('chevron', 'user-chevron') ?></summary>
            <div class="user-dropdown-menu"><div class="user-dropdown-meta"><strong><?= e($user['name'] ?? 'User') ?></strong><span><?= e($scopeName) ?> • <?= e(role_label($user['role'] ?? '')) ?></span></div>
                <?php if (Auth::isImpersonating()): ?><form method="post" action="actions/impersonation.php" class="dropdown-return-form"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="end"><button type="submit"><?= icon('shield') ?><span>Return to System Admin</span></button></form><?php else: ?><a href="change-password.php"><?= icon('shield') ?><span>Change Password</span></a><?php endif; ?>
                <a href="logout.php"><?= icon('logout') ?><span>Sign Out</span></a></div>
        </details>
    </div>
</header>
