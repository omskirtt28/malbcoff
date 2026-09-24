<?php
$user = Auth::user();
$branches = [];
try {
    $branches = Database::query('SELECT id, name FROM branches WHERE is_active = 1 ORDER BY id')->fetchAll();
} catch (Throwable $e) {
    $branches = [];
}
$scopeBranchId = current_branch_scope();
$scopeName = 'All Branches';
if (!Auth::isOwner()) {
    $scopeName = $user['branch_name'] ?? 'Assigned Branch';
} elseif ($scopeBranchId) {
    foreach ($branches as $branch) {
        if ((int)$branch['id'] === $scopeBranchId) {
            $scopeName = $branch['name'];
            break;
        }
    }
}
?>
<header class="topbar">
    <button class="sidebar-toggle" type="button" data-sidebar-toggle aria-label="Open navigation"><?= icon('menu') ?></button>
    <a class="mobile-brand" href="index.php?page=dashboard" aria-label="Malbcoff Trading dashboard">
        <span class="brand-mark small">M</span>
        <span class="mobile-brand-copy"><strong><?= e($app['name']) ?></strong><small><?= e($app['subtitle']) ?></small></span>
    </a>

    <label class="topbar-search" aria-label="Global inventory search">
        <?= icon('search') ?>
        <input type="search" data-global-search autocomplete="off" placeholder="Search products, models, IMEI, serial, barcode…">
        <kbd>Enter</kbd>
    </label>

    <div class="topbar-actions">
        <?php if (Auth::isOwner()): ?>
            <div class="branch-switcher">
                <?= icon('branch') ?>
                <select aria-label="Branch scope" onchange="window.location='<?= e(owner_branch_filter_url($page, null)) ?>' + (this.value ? '&branch=' + this.value : '')">
                    <option value="">All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int)$branch['id'] ?>" <?= $scopeBranchId === (int)$branch['id'] ? 'selected' : '' ?>><?= e($branch['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php else: ?>
            <div class="branch-chip topbar-branch-chip"><?= icon('branch') ?><span><?= e($scopeName) ?></span></div>
        <?php endif; ?>

        <details class="notification-dropdown">
            <summary class="topbar-icon-button" aria-label="Notifications" title="Notifications"><?= icon('bell') ?></summary>
            <div class="notification-dropdown-menu">
                <strong>Notifications</strong>
                <span>No new notifications.</span>
            </div>
        </details>

        <details class="user-dropdown">
            <summary class="user-menu" aria-label="User menu">
                <div class="avatar"><?= e(strtoupper(substr($user['name'] ?? 'U', 0, 1))) ?></div>
                <div class="user-copy"><strong><?= e($user['name'] ?? 'User') ?></strong><span><?= e(role_label($user['role'])) ?></span></div>
                <?= icon('chevron', 'user-chevron') ?>
            </summary>
            <div class="user-dropdown-menu">
                <div class="user-dropdown-meta">
                    <strong><?= e($user['name'] ?? 'User') ?></strong>
                    <span><?= e($scopeName) ?> • <?= e(role_label($user['role'])) ?></span>
                </div>
                <a href="logout.php"><?= icon('logout') ?><span>Sign Out</span></a>
            </div>
        </details>
    </div>
</header>
