<?php
$user = Auth::user() ?? [];
$isOwner = Auth::isOwner();
$branchId = Auth::branchId() ?: 0;
$statusFilter = $_GET['status'] ?? '';
if (!in_array($statusFilter, ['', 'pending', 'received'], true)) $statusFilter = '';

$ready = false;
$rows = [];
$pendingIncoming = 0;
$pendingOutgoing = 0;
$receivedCount = 0;
$setupError = null;

try {
    $ready = (bool)Database::query("SHOW TABLES LIKE 'inventory_transfers'")->fetchColumn()
        && (bool)Database::query("SHOW TABLES LIKE 'inventory_transfer_units'")->fetchColumn();
    if ($ready) {
        $params = [];
        $where = ['1=1'];
        if (!$isOwner) {
            // PDO native prepared statements do not allow the same named placeholder
            // to be reused more than once in a statement. Keep separate placeholders
            // for source and destination branch scoping.
            $where[] = '(t.source_branch_id=:scope_source_branch OR t.destination_branch_id=:scope_destination_branch)';
            $params['scope_source_branch'] = $branchId;
            $params['scope_destination_branch'] = $branchId;
        }
        if ($statusFilter !== '') {
            $where[] = 't.status=:status';
            $params['status'] = $statusFilter;
        }

        $rows = Database::query(
            "SELECT t.*,sb.name source_branch,db.name destination_branch,
                    fu.name forwarded_by_name,ru.name received_by_user_name,
                    p.product_type,p.product_name,p.ram,p.storage,p.connectivity,p.color,
                    br.name brand_name,pm.name model_name
             FROM inventory_transfers t
             JOIN branches sb ON sb.id=t.source_branch_id
             JOIN branches db ON db.id=t.destination_branch_id
             JOIN users fu ON fu.id=t.forwarded_by
             LEFT JOIN users ru ON ru.id=t.received_by
             JOIN products p ON p.id=t.product_id
             LEFT JOIN brands br ON br.id=p.brand_id
             LEFT JOIN product_models pm ON pm.id=p.model_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY CASE WHEN t.status='pending' THEN 0 ELSE 1 END,t.forwarded_at DESC,t.id DESC
             LIMIT 250",
            $params
        )->fetchAll();

        if ($isOwner) {
            $pendingIncoming = (int)Database::query("SELECT COUNT(*) FROM inventory_transfers WHERE status='pending'")->fetchColumn();
            $pendingOutgoing = $pendingIncoming;
            $receivedCount = (int)Database::query("SELECT COUNT(*) FROM inventory_transfers WHERE status='received'")->fetchColumn();
        } else {
            $pendingIncoming = (int)Database::query(
                "SELECT COUNT(*) FROM inventory_transfers WHERE destination_branch_id=? AND status='pending'",
                [$branchId]
            )->fetchColumn();
            $pendingOutgoing = (int)Database::query(
                "SELECT COUNT(*) FROM inventory_transfers WHERE source_branch_id=? AND status='pending'",
                [$branchId]
            )->fetchColumn();
            $receivedCount = (int)Database::query(
                "SELECT COUNT(*) FROM inventory_transfers WHERE (source_branch_id=? OR destination_branch_id=?) AND status='received'",
                [$branchId, $branchId]
            )->fetchColumn();
        }
    }
} catch (Throwable $e) {
    $setupError = 'Unable to load branch transfers right now.';
}

function transfer_product_label(array $row): string {
    if (($row['product_type'] ?? '') === 'accessory') return (string)($row['product_name'] ?? 'Accessory');
    $label = trim((string)($row['brand_name'] ?? '') . ' ' . (string)($row['model_name'] ?? ''));
    return $label !== '' ? $label : (string)($row['product_name'] ?? 'Device');
}
function transfer_product_specs(array $row): string {
    $parts=[];
    foreach (['ram','storage','connectivity','color'] as $key) {
        $value=trim((string)($row[$key] ?? ''));
        if ($value!=='') $parts[]=$value;
    }
    return $parts ? implode(' • ',$parts) : (($row['product_type'] ?? '') === 'accessory' ? 'Accessory' : '—');
}
?>
<section class="page-heading transfer-page-heading">
    <div>
        <span class="eyebrow">INVENTORY CUSTODY</span>
        <h1>Branch Transfers</h1>
        <p><?= $isOwner ? 'Track forwarded inventory and receiving proof across all branches.' : 'Receive incoming stock and review inventory forwarded between branches.' ?></p>
    </div>
    <?php if (!$isOwner): ?>
        <a class="btn btn-outline" href="index.php?page=inventory"><?= icon('inventory') ?> Open Inventory</a>
    <?php endif; ?>
</section>

<?php if (!$ready): ?>
    <div class="alert alert-info">
        <strong>Branch receiving setup is not installed yet.</strong><br>
        Run <code>database/P2_005_branch_transfer_receiving.sql</code> in phpMyAdmin once, then refresh this page.
    </div>
<?php elseif ($setupError): ?>
    <div class="alert alert-error"><?= e($setupError) ?></div>
<?php else: ?>
    <section class="transfer-summary-grid">
        <article class="card transfer-summary-card <?= !$isOwner && $pendingIncoming > 0 ? 'attention' : '' ?>">
            <span><?= $isOwner ? 'Pending Transfers' : 'To Receive' ?></span>
            <strong><?= (int)$pendingIncoming ?></strong>
            <small><?= $isOwner ? 'Waiting for destination confirmation' : 'Incoming transfers waiting for your branch' ?></small>
        </article>
        <article class="card transfer-summary-card">
            <span><?= $isOwner ? 'In Transit' : 'Forwarded Out' ?></span>
            <strong><?= (int)$pendingOutgoing ?></strong>
            <small><?= $isOwner ? 'Inventory currently between branches' : 'Transfers awaiting destination receipt' ?></small>
        </article>
        <article class="card transfer-summary-card">
            <span>Received</span>
            <strong><?= (int)$receivedCount ?></strong>
            <small>Transfers with completed receiving proof</small>
        </article>
    </section>

    <div class="tab-row transfer-tabs">
        <a class="tab <?= $statusFilter===''?'active':'' ?>" href="index.php?page=branch-transfers">All</a>
        <a class="tab <?= $statusFilter==='pending'?'active':'' ?>" href="index.php?page=branch-transfers&status=pending">Pending Receipt</a>
        <a class="tab <?= $statusFilter==='received'?'active':'' ?>" href="index.php?page=branch-transfers&status=received">Received</a>
    </div>

    <section class="card table-card transfer-table-card">
        <div class="card-header">
            <div>
                <h2>Transfer Records</h2>
                <p><?= count($rows) ?> record<?= count($rows)===1?'':'s' ?> shown.</p>
            </div>
        </div>
        <div class="table-wrap">
            <table class="data-table transfer-table">
                <thead>
                    <tr>
                        <th>Reference / Date</th>
                        <th>From → To</th>
                        <th>Product</th>
                        <th>Qty</th>
                        <th>Forwarded By</th>
                        <th>Status</th>
                        <th>Receiving Proof</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="8"><div class="empty-state"><div class="empty-icon"><?= icon('movement') ?></div><strong>No branch transfers found</strong><span>Forwarded inventory will appear here.</span></div></td></tr>
                <?php else: foreach ($rows as $row):
                    $isIncoming = !$isOwner && (int)$row['destination_branch_id'] === $branchId;
                    $canReceive = $isIncoming && $row['status'] === 'pending';
                ?>
                    <tr class="<?= $canReceive ? 'transfer-row-awaiting' : '' ?>">
                        <td data-label="Reference / Date">
                            <strong><?= e($row['reference_no']) ?></strong>
                            <span class="table-subtext"><?= e(date('M d, Y • h:i A', strtotime($row['forwarded_at']))) ?></span>
                        </td>
                        <td data-label="Route">
                            <strong><?= e($row['source_branch']) ?> → <?= e($row['destination_branch']) ?></strong>
                            <?php if (!$isOwner): ?><span class="table-subtext"><?= $isIncoming ? 'Incoming to your branch' : 'Forwarded by your branch' ?></span><?php endif; ?>
                        </td>
                        <td data-label="Product">
                            <strong><?= e(transfer_product_label($row)) ?></strong>
                            <span class="table-subtext"><?= e(transfer_product_specs($row)) ?></span>
                        </td>
                        <td data-label="Qty"><strong><?= (int)$row['quantity'] ?></strong></td>
                        <td data-label="Forwarded By">
                            <?= e($row['forwarded_by_name']) ?>
                            <span class="table-subtext"><?= e($row['source_branch']) ?></span>
                        </td>
                        <td data-label="Status">
                            <span class="transfer-status <?= e($row['status']) ?>"><?= $row['status']==='pending'?'Pending Receipt':'Received' ?></span>
                        </td>
                        <td data-label="Receiving Proof">
                            <?php if ($row['status']==='received'): ?>
                                <strong><?= e($row['receiver_name'] ?: '—') ?></strong>
                                <span class="table-subtext"><?= $row['received_at'] ? e(date('M d, Y • h:i A', strtotime($row['received_at']))) : '—' ?></span>
                            <?php else: ?>
                                <span class="table-subtext">Waiting for <?= e($row['destination_branch']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Action">
                            <button type="button" class="btn <?= $canReceive ? 'btn-primary' : 'btn-outline' ?> btn-sm" data-transfer-open data-transfer-id="<?= (int)$row['id'] ?>">
                                <?= icon($canReceive ? 'stock' : 'eye') ?> <?= $canReceive ? 'Receive' : 'View' ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php if ($ready): ?>
<div class="modal transfer-receive-modal" id="transferReceiveModal" hidden aria-hidden="true">
    <button type="button" class="modal-backdrop" data-transfer-close aria-label="Close transfer details"></button>
    <div class="modal-dialog transfer-receive-dialog" role="dialog" aria-modal="true" aria-labelledby="transferReceiveTitle">
        <form id="transferReceiveForm" novalidate>
            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="transfer_id" value="">
            <div class="modal-header transfer-receive-header">
                <div>
                    <span class="eyebrow">BRANCH TRANSFER</span>
                    <h2 id="transferReceiveTitle">Receive Inventory</h2>
                </div>
                <button type="button" class="icon-button" data-transfer-close aria-label="Close">×</button>
            </div>
            <div class="modal-body transfer-receive-body">
                <div class="transfer-detail-loading" data-transfer-loading>Loading transfer details…</div>
                <div data-transfer-content hidden>
                    <div class="transfer-reference-strip">
                        <div><span>Reference</span><strong data-transfer-reference>—</strong></div>
                        <span class="transfer-status pending" data-transfer-status>Pending Receipt</span>
                    </div>
                    <div class="transfer-route-card">
                        <div><span>From</span><strong data-transfer-source>—</strong></div>
                        <div class="transfer-route-arrow">→</div>
                        <div><span>To</span><strong data-transfer-destination>—</strong></div>
                    </div>
                    <div class="transfer-product-card">
                        <div><span>Product</span><strong data-transfer-product>—</strong><small data-transfer-specs>—</small></div>
                        <div><span>Quantity</span><strong data-transfer-quantity>—</strong></div>
                    </div>
                    <div class="transfer-unit-proof" data-transfer-unit-wrap hidden>
                        <div class="transfer-section-heading"><div><strong>Transferred Unit(s)</strong><span>Exact IMEI / serial numbers included in this transfer.</span></div></div>
                        <div class="transfer-unit-list" data-transfer-units></div>
                    </div>
                    <div class="transfer-meta-grid">
                        <div><span>Forwarded By</span><strong data-transfer-forwarded-by>—</strong><small data-transfer-forwarded-at>—</small></div>
                        <div><span>Notes</span><strong data-transfer-notes>—</strong></div>
                    </div>
                    <label class="field transfer-receiver-field" data-transfer-receiver-field>
                        <span>Receiver Name <b>*</b></span>
                        <input type="text" name="receiver_name" maxlength="120" autocomplete="name" placeholder="Enter the name of the person who received the inventory">
                        <small>Required as proof of receiving for this branch transfer.</small>
                        <small class="forward-field-error" data-transfer-receiver-error hidden></small>
                    </label>
                    <div class="transfer-received-proof" data-transfer-received-proof hidden>
                        <span>Proof of Receiving</span>
                        <strong data-transfer-receiver-name>—</strong>
                        <small><span data-transfer-received-by>—</span> • <span data-transfer-received-at>—</span></small>
                    </div>
                    <div class="alert alert-error" data-transfer-error hidden></div>
                </div>
            </div>
            <div class="modal-actions transfer-receive-actions">
                <button type="button" class="btn btn-secondary" data-transfer-close>Close</button>
                <button type="submit" class="btn btn-primary" data-transfer-submit hidden><?= icon('stock') ?> Confirm Receive</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
