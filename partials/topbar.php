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
    <button class="sidebar-toggle" type="button" data-sidebar-toggle aria-label="Toggle navigation">☰</button>
    <div class="topbar-spacer"></div>
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
        <div class="branch-chip"><?= icon('branch') ?><span><?= e($scopeName) ?></span></div>
    <?php endif; ?>
    <div class="user-menu">
        <div class="avatar"><?= e(strtoupper(substr($user['name'] ?? 'U', 0, 1))) ?></div>
        <div class="user-copy"><strong><?= e($user['name'] ?? 'User') ?></strong><span><?= e(role_label($user['role'])) ?></span></div>
    </div>
</header>
