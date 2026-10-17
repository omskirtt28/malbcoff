<?php
// Owner-only list of stock actions and offline sales (Patch 6c-2) that a branch phone could not complete (outbox "Needs action").
// Read-mostly: the owner reads the reason, fixes things with the branch, then marks the item Resolved.
$tab = (string)($_GET['tab'] ?? 'open') === 'resolved' ? 'resolved' : 'open';
$ready = outbox_reports_ready();
$rows = [];
$openCount = 0;
$resolvedCount = 0;
$loadError = '';
if ($ready) {
    try {
        $openCount = (int)Database::query("SELECT COUNT(*) FROM outbox_reports WHERE status='open'")->fetchColumn();
        $resolvedCount = (int)Database::query("SELECT COUNT(*) FROM outbox_reports WHERE status='resolved'")->fetchColumn();
        $rows = Database::query(
            "SELECT r.*,u.name user_name,b.name branch_name,ru.name resolved_by_name
             FROM outbox_reports r
             LEFT JOIN users u ON u.id=r.user_id
             LEFT JOIN branches b ON b.id=r.branch_id
             LEFT JOIN users ru ON ru.id=r.resolved_by
             WHERE r.status=?
             ORDER BY " . ($tab === 'open' ? 'r.created_at ASC' : 'r.resolved_at DESC') . ",r.id DESC
             LIMIT 200",
            [$tab]
        )->fetchAll();
    } catch (Throwable $e) {
        $loadError = 'The list could not be loaded right now. Please refresh the page.';
    }
}
$fmt = static fn(?string $value): string => $value ? date('M d, Y g:i A', strtotime($value)) : '—';
?>
<section class="page-heading">
    <div>
        <span class="eyebrow">OWNER VIEW</span>
        <h1>Needs Action</h1>
        <p>Stock actions and sales that a branch phone saved offline but the server could not complete. Nothing here changed any stock, and a refused sale was not recorded at all. Talk to the branch, then mark the item as resolved.</p>
    </div>
</section>

<?php if (!$ready): ?>
    <div class="alert alert-info">This list is not set up yet. Ask your system administrator to import the latest database update (P3_004).</div>
<?php endif; ?>
<?php if ($loadError): ?><div class="alert alert-info"><?= e($loadError) ?></div><?php endif; ?>

<nav class="stock-monitoring-tabs needs-action-tabs" aria-label="Needs action tabs">
    <a href="<?= e(app_url('needs-action')) ?>" class="stock-monitoring-tab <?= $tab === 'open' ? 'is-active' : '' ?>" <?= $tab === 'open' ? 'aria-current="page"' : '' ?>>Open (<?= (int)$openCount ?>)</a>
    <a href="<?= e(app_url('needs-action', ['tab' => 'resolved'])) ?>" class="stock-monitoring-tab <?= $tab === 'resolved' ? 'is-active' : '' ?>" <?= $tab === 'resolved' ? 'aria-current="page"' : '' ?>>Resolved (<?= (int)$resolvedCount ?>)</a>
</nav>

<?php if (!$rows): ?>
    <section class="card needs-action-empty">
        <strong><?= $tab === 'open' ? 'Nothing needs action.' : 'No resolved items yet.' ?></strong>
        <p><?= $tab === 'open' ? 'Every saved action from the branch phones was completed.' : 'Items you mark as resolved will appear here.' ?></p>
    </section>
<?php else: ?>
<div class="needs-action-list">
    <?php foreach ($rows as $row): ?>
    <article class="card needs-action-item">
        <header class="needs-action-head">
            <div>
                <span class="needs-action-type"><?= e(outbox_action_label((string)$row['action_type'])) ?></span>
                <strong class="needs-action-summary"><?= e((string)$row['summary']) ?></strong>
            </div>
            <?php if ((int)$row['staff_discarded'] === 1): ?><span class="needs-action-chip">Discarded by staff</span><?php endif; ?>
        </header>
        <p class="needs-action-reason"><b>Why:</b> <?= e((string)$row['reason']) ?></p>
        <?php if (!empty($row['details'])): ?><p class="needs-action-details"><?= nl2br(e((string)$row['details'])) ?></p><?php endif; ?>
        <dl class="needs-action-meta">
            <div><dt>Branch</dt><dd><?= e((string)($row['branch_name'] ?? '—')) ?></dd></div>
            <div><dt><?= (string)$row['action_type'] === 'sale' ? 'Cashier' : 'Staff' ?></dt><dd><?= e((string)($row['user_name'] ?? '—')) ?></dd></div>
            <div><dt><?= (string)$row['action_type'] === 'sale' ? 'Sale made (device time)' : 'Saved on phone' ?></dt><dd><?= e($fmt($row['queued_at'] ?? null)) ?></dd></div>
            <div><dt>Reported</dt><dd><?= e($fmt($row['created_at'] ?? null)) ?></dd></div>
        </dl>
        <?php if ($tab === 'open'): ?>
        <form class="needs-action-resolve" method="post" action="actions/outbox_resolve.php">
            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
            <label class="field">
                <span>Note (optional)</span>
                <input type="text" name="note" maxlength="255" placeholder="What was done about it?" autocomplete="off">
            </label>
            <button class="btn btn-primary" type="submit">Mark as resolved</button>
        </form>
        <?php else: ?>
        <p class="needs-action-resolved">Resolved <?= e($fmt($row['resolved_at'] ?? null)) ?> by <?= e((string)($row['resolved_by_name'] ?? 'Owner')) ?><?= !empty($row['resolved_note']) ? ' — ' . e((string)$row['resolved_note']) : '' ?></p>
        <?php endif; ?>
    </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>
