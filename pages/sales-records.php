<?php
$salesRecordsRole = (string)(Auth::user()['role'] ?? '');
$isOwner = Auth::isOwner();
$isBranchSalesUser = in_array($salesRecordsRole, ['branch_manager', 'cashier'], true);
if (!$isOwner && !$isBranchSalesUser) {
    echo '<div class="alert alert-error">You do not have access to Sales Records.</div>';
    return;
}

$scopeBranchId = $isOwner ? null : Auth::branchId();
if (!$isOwner && !$scopeBranchId) {
    echo '<div class="alert alert-error">Your account is not assigned to an active branch.</div>';
    return;
}

function sales_records_valid_date(?string $value, string $fallback): string
{
    $value = trim((string)$value);
    if ($value === '') return $fallback;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? $value : $fallback;
}

function sales_records_payment_label(string $method): string
{
    return match ($method) {
        'gcash' => 'GCash',
        'maya' => 'Maya',
        'card' => 'Card',
        'bank_transfer' => 'Bank Transfer',
        default => 'Cash',
    };
}

function sales_records_url(array $state, array $changes = []): string
{
    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') unset($state[$key]);
        else $state[$key] = $value;
    }
    return 'index.php?' . http_build_query($state);
}

$defaultFrom = date('Y-m-01');
$defaultTo = date('Y-m-d');
$from = sales_records_valid_date($_GET['from'] ?? null, $defaultFrom);
$to = sales_records_valid_date($_GET['to'] ?? null, $defaultTo);
if ($from > $to) [$from, $to] = [$to, $from];

$branchId = $isOwner ? (filter_input(INPUT_GET, 'branch', FILTER_VALIDATE_INT) ?: 0) : (int)$scopeBranchId;
$payment = trim((string)($_GET['payment'] ?? ''));
$allowedPayments = ['cash', 'gcash', 'maya', 'card', 'bank_transfer'];
if (!in_array($payment, $allowedPayments, true)) $payment = '';
$search = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($search) > 100) $search = mb_substr($search, 0, 100);
$pageNo = max(1, filter_input(INPUT_GET, 'p', FILTER_VALIDATE_INT) ?: 1);
$perPage = 40;

$fromBoundary = (new DateTimeImmutable($from))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
$toBoundary = (new DateTimeImmutable($to))->modify('+1 day')->setTime(0, 0, 0)->format('Y-m-d H:i:s');

$branches = [];
$branchSummary = [];
$records = [];
$selectedSale = null;
$selectedItems = [];
$dbError = null;
$totalRecords = 0;
$totalSales = 0.0;
$totalTransactions = 0;
$totalItems = 0;

try {
    $salesReady = (bool)Database::query("SHOW TABLES LIKE 'sales'")->fetchColumn();
    $saleItemsReady = (bool)Database::query("SHOW TABLES LIKE 'sale_items'")->fetchColumn();
    if (!$salesReady || !$saleItemsReady) {
        throw new RuntimeException('Sales records are not ready yet. Run the POS database migration first.');
    }

    if ($isOwner) {
        $branches = Database::query('SELECT id,name,code FROM branches WHERE is_active=1 ORDER BY id')->fetchAll();
        $validBranchIds = array_map(static fn(array $row): int => (int)$row['id'], $branches);
        if ($branchId && !in_array($branchId, $validBranchIds, true)) $branchId = 0;

        // Owner can compare every active branch over the same date/payment period.
        $summaryJoin = "s.branch_id=b.id AND s.status='completed' AND s.created_at>=:summary_from AND s.created_at<:summary_to";
        $summaryParams = ['summary_from' => $fromBoundary, 'summary_to' => $toBoundary];
        if ($payment !== '') {
            $summaryJoin .= ' AND s.payment_method=:summary_payment';
            $summaryParams['summary_payment'] = $payment;
        }
        $branchSummary = Database::query(
            "SELECT b.id,b.name,b.code,COUNT(s.id) transactions,COALESCE(SUM(s.total),0) total_sales
             FROM branches b
             LEFT JOIN sales s ON {$summaryJoin}
             WHERE b.is_active=1
             GROUP BY b.id,b.name,b.code
             ORDER BY b.id",
            $summaryParams
        )->fetchAll();
    } else {
        $branch = Database::query('SELECT id,name,code FROM branches WHERE id=? AND is_active=1 LIMIT 1', [$branchId])->fetch();
        if (!$branch) throw new RuntimeException('Your assigned branch is not active or could not be found.');
        $branches = [$branch];
    }

    $where = ["s.status='completed'", 's.created_at>=:from_date', 's.created_at<:to_date'];
    $params = ['from_date' => $fromBoundary, 'to_date' => $toBoundary];
    if ($branchId) {
        $where[] = 's.branch_id=:branch_id';
        $params['branch_id'] = $branchId;
    }
    if ($payment !== '') {
        $where[] = 's.payment_method=:payment_method';
        $params['payment_method'] = $payment;
    }
    if ($search !== '') {
        $where[] = '(s.sale_no LIKE :q_sale OR b.name LIKE :q_branch OR u.name LIKE :q_cashier OR COALESCE(s.payment_reference,\'\') LIKE :q_reference)';
        $like = '%' . $search . '%';
        $params['q_sale'] = $like;
        $params['q_branch'] = $like;
        $params['q_cashier'] = $like;
        $params['q_reference'] = $like;
    }
    $whereSql = implode(' AND ', $where);

    $totals = Database::query(
        "SELECT COUNT(*) transactions,COALESCE(SUM(s.total),0) total_sales
         FROM sales s
         JOIN branches b ON b.id=s.branch_id
         JOIN users u ON u.id=s.created_by
         WHERE {$whereSql}",
        $params
    )->fetch();

    // Item quantity is calculated separately so the report stays compatible with native PDO prepares.
    $totalTransactions = (int)($totals['transactions'] ?? 0);
    $totalSales = (float)($totals['total_sales'] ?? 0);

    $itemWhere = ["sx.status='completed'", 'sx.created_at>=:item_from', 'sx.created_at<:item_to'];
    $itemParams = ['item_from' => $fromBoundary, 'item_to' => $toBoundary];
    if ($branchId) {
        $itemWhere[] = 'sx.branch_id=:item_branch';
        $itemParams['item_branch'] = $branchId;
    }
    if ($payment !== '') {
        $itemWhere[] = 'sx.payment_method=:item_payment';
        $itemParams['item_payment'] = $payment;
    }
    if ($search !== '') {
        $itemWhere[] = '(sx.sale_no LIKE :item_q_sale OR bx.name LIKE :item_q_branch OR ux.name LIKE :item_q_cashier OR COALESCE(sx.payment_reference,\'\') LIKE :item_q_reference)';
        $like = '%' . $search . '%';
        $itemParams['item_q_sale'] = $like;
        $itemParams['item_q_branch'] = $like;
        $itemParams['item_q_cashier'] = $like;
        $itemParams['item_q_reference'] = $like;
    }
    $totalItems = (int)Database::query(
        'SELECT COALESCE(SUM(si.quantity),0) FROM sale_items si JOIN sales sx ON sx.id=si.sale_id JOIN branches bx ON bx.id=sx.branch_id JOIN users ux ON ux.id=sx.created_by WHERE ' . implode(' AND ', $itemWhere),
        $itemParams
    )->fetchColumn();

    $totalRecords = $totalTransactions;
    $totalPages = max(1, (int)ceil($totalRecords / $perPage));
    if ($pageNo > $totalPages) $pageNo = $totalPages;
    $offset = ($pageNo - 1) * $perPage;

    $recordSql = "SELECT s.id,s.sale_no,s.total,s.payment_method,s.payment_reference,s.amount_received,s.change_due,s.created_at,
                         b.name branch_name,b.code branch_code,u.name cashier_name,
                         (SELECT COALESCE(SUM(si.quantity),0) FROM sale_items si WHERE si.sale_id=s.id) item_count
                  FROM sales s
                  JOIN branches b ON b.id=s.branch_id
                  JOIN users u ON u.id=s.created_by
                  WHERE {$whereSql}
                  ORDER BY s.created_at DESC,s.id DESC
                  LIMIT {$perPage} OFFSET {$offset}";
    $records = Database::query($recordSql, $params)->fetchAll();

    $saleId = filter_input(INPUT_GET, 'sale', FILTER_VALIDATE_INT) ?: 0;
    if ($saleId) {
        if ($isOwner) {
            $selectedSale = Database::query(
                "SELECT s.*,b.name branch_name,b.code branch_code,u.name cashier_name
                 FROM sales s JOIN branches b ON b.id=s.branch_id JOIN users u ON u.id=s.created_by
                 WHERE s.id=? AND s.status='completed' LIMIT 1",
                [$saleId]
            )->fetch() ?: null;
        } else {
            $selectedSale = Database::query(
                "SELECT s.*,b.name branch_name,b.code branch_code,u.name cashier_name
                 FROM sales s JOIN branches b ON b.id=s.branch_id JOIN users u ON u.id=s.created_by
                 WHERE s.id=? AND s.branch_id=? AND s.status='completed' LIMIT 1",
                [$saleId, $branchId]
            )->fetch() ?: null;
        }
        if ($selectedSale) {
            $selectedItems = Database::query(
                'SELECT item_name_snapshot,specs_snapshot,identifier_snapshot,quantity,unit_price,line_total FROM sale_items WHERE sale_id=? ORDER BY id',
                [$saleId]
            )->fetchAll();
        }
    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
    $totalPages = 1;
}

$baseState = [
    'page' => 'sales-records',
    'from' => $from,
    'to' => $to,
];
if ($isOwner && $branchId) $baseState['branch'] = $branchId;
if ($payment !== '') $baseState['payment'] = $payment;
if ($search !== '') $baseState['q'] = $search;

$selectedBranchName = $isOwner ? 'All Branches' : 'Assigned Branch';
foreach ($branches as $branch) {
    if ((int)$branch['id'] === $branchId) {
        $selectedBranchName = (string)$branch['name'];
        break;
    }
}
?>

<section class="page-heading sales-records-heading">
    <div>
        <span class="eyebrow"><?= $isOwner ? 'OWNER SALES MONITORING' : 'BRANCH SALES MONITORING' ?></span>
        <h1><?= $isOwner ? 'Sales Records' : 'My Branch Sales' ?></h1>
        <p><?= $isOwner
            ? 'Every completed POS transaction is recorded under the branch that made the sale. Review all branch totals and open each transaction for item-level details.'
            : 'Review completed POS transactions recorded under ' . e($selectedBranchName) . '. Sales from other branches are not accessible from this account.' ?></p>
    </div>
    <?php if (!$isOwner && pos_role_allowed()): ?><a class="btn btn-primary" href="index.php?page=pos"><?= icon('pos') ?> Open POS</a><?php endif; ?>
</section>

<?php if ($dbError): ?>
    <div class="alert alert-error"><?= e($dbError) ?></div>
<?php else: ?>

<form class="card sales-filter-card" method="get" action="index.php">
    <input type="hidden" name="page" value="sales-records">
    <label class="field sales-search-field"><span>Search Record</span><div class="sales-search-input"><?= icon('search') ?><input type="search" name="q" value="<?= e($search) ?>" placeholder="Sale no., cashier, reference…"></div></label>
    <?php if ($isOwner): ?><label class="field"><span>Branch</span><select name="branch"><option value="">All Branches</option><?php foreach ($branches as $branch): ?><option value="<?= (int)$branch['id'] ?>" <?= $branchId === (int)$branch['id'] ? 'selected' : '' ?>><?= e($branch['name']) ?></option><?php endforeach; ?></select></label><?php else: ?><label class="field"><span>Branch</span><input type="text" value="<?= e($selectedBranchName) ?>" readonly aria-label="Assigned branch"></label><?php endif; ?>
    <label class="field"><span>Payment</span><select name="payment"><option value="">All Payments</option><?php foreach ($allowedPayments as $method): ?><option value="<?= e($method) ?>" <?= $payment === $method ? 'selected' : '' ?>><?= e(sales_records_payment_label($method)) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Date From</span><input type="date" name="from" value="<?= e($from) ?>"></label>
    <label class="field"><span>Date To</span><input type="date" name="to" value="<?= e($to) ?>"></label>
    <button class="btn btn-primary sales-filter-apply" type="submit">Apply</button>
    <a class="btn btn-secondary sales-filter-reset" href="index.php?page=sales-records">Reset</a>
</form>

<div class="sales-kpi-grid">
    <article class="sales-kpi-card"><span>Filtered Sales</span><strong><?= peso($totalSales) ?></strong><small><?= e($selectedBranchName) ?> · <?= e(date('M d', strtotime($from))) ?>–<?= e(date('M d, Y', strtotime($to))) ?></small></article>
    <article class="sales-kpi-card"><span>Transactions</span><strong><?= number_format($totalTransactions) ?></strong><small>Completed POS sales</small></article>
    <article class="sales-kpi-card"><span>Items Sold</span><strong><?= number_format($totalItems) ?></strong><small>Total quantity in completed sales</small></article>
</div>

<?php if ($isOwner): ?>
<section class="card sales-branch-section">
    <div class="card-header"><div><h2>Sales by Branch</h2><p>Branch totals for the selected date range<?= $payment !== '' ? ' and payment method' : '' ?>.</p></div><span class="mini-chip"><?= count($branchSummary) ?> branches</span></div>
    <div class="sales-branch-grid">
        <?php foreach ($branchSummary as $row):
            $isActive = $branchId === (int)$row['id'];
            $linkState = $baseState;
            unset($linkState['sale'], $linkState['p']);
            $link = sales_records_url($linkState, ['branch' => $isActive ? null : (int)$row['id']]);
        ?>
        <a class="sales-branch-card <?= $isActive ? 'active' : '' ?>" href="<?= e($link) ?>">
            <div class="sales-branch-card-top"><span class="sales-branch-icon"><?= icon('branch') ?></span><span class="sales-branch-code"><?= e($row['code']) ?></span></div>
            <strong><?= e($row['name']) ?></strong>
            <b><?= peso($row['total_sales']) ?></b>
            <small><?= number_format((int)$row['transactions']) ?> transaction<?= (int)$row['transactions'] === 1 ? '' : 's' ?></small>
        </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($selectedSale): ?>
<section class="card sales-detail-card" id="sale-detail">
    <div class="sales-detail-header">
        <div><span class="eyebrow">TRANSACTION DETAILS</span><h2><?= e($selectedSale['sale_no']) ?></h2><p><?= e($selectedSale['branch_name']) ?> · <?= e(date('M d, Y • h:i A', strtotime($selectedSale['created_at']))) ?> · <?= e($selectedSale['cashier_name']) ?></p></div>
        <a class="btn btn-secondary btn-sm" href="<?= e(sales_records_url($baseState, ['sale' => null])) ?>">Close Details</a>
    </div>
    <div class="sales-detail-summary">
        <div><span>Payment</span><strong><?= e(sales_records_payment_label($selectedSale['payment_method'])) ?></strong></div>
        <div><span>Reference</span><strong><?= e($selectedSale['payment_reference'] ?: '—') ?></strong></div>
        <div><span>Amount Received</span><strong><?= $selectedSale['amount_received'] !== null ? peso($selectedSale['amount_received']) : '—' ?></strong></div>
        <div><span>Change</span><strong><?= peso($selectedSale['change_due']) ?></strong></div>
        <div class="sales-detail-total"><span>Total</span><strong><?= peso($selectedSale['total']) ?></strong></div>
    </div>
    <div class="table-wrap sales-detail-table-wrap">
        <table class="data-table sales-detail-table">
            <thead><tr><th>Item</th><th>Identifier</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr></thead>
            <tbody>
            <?php foreach ($selectedItems as $item): ?>
                <tr>
                    <td><strong><?= e($item['item_name_snapshot']) ?></strong><span class="sales-cell-sub"><?= e($item['specs_snapshot'] ?: '—') ?></span></td>
                    <td><?= e($item['identifier_snapshot'] ?: '—') ?></td>
                    <td><?= number_format((int)$item['quantity']) ?></td>
                    <td><?= peso($item['unit_price']) ?></td>
                    <td><strong><?= peso($item['line_total']) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<section class="card sales-records-table-card">
    <div class="card-header"><div><h2>Transaction Records</h2><p><?= number_format($totalRecords) ?> completed sale<?= $totalRecords === 1 ? '' : 's' ?> found.</p></div><span class="mini-chip">Latest first</span></div>
    <div class="table-wrap sales-records-table-wrap">
        <table class="data-table sales-records-table">
            <thead><tr><th>Date & Time</th><th>Sale No.</th><th>Branch</th><th>Cashier</th><th>Items</th><th>Payment</th><th>Total</th><th></th></tr></thead>
            <tbody>
            <?php if (!$records): ?>
                <tr><td colspan="8"><div class="empty-state small"><div class="empty-icon"><?= icon('receipt') ?></div><strong>No sales found</strong><span>Try a different date range, payment method or search term.</span></div></td></tr>
            <?php else: foreach ($records as $sale): ?>
                <tr>
                    <td><strong><?= e(date('M d, Y', strtotime($sale['created_at']))) ?></strong><span class="sales-cell-sub"><?= e(date('h:i A', strtotime($sale['created_at']))) ?></span></td>
                    <td><strong><?= e($sale['sale_no']) ?></strong><?php if ($sale['payment_reference']): ?><span class="sales-cell-sub">Ref: <?= e($sale['payment_reference']) ?></span><?php endif; ?></td>
                    <td><span class="sales-table-branch"><?= icon('branch') ?><?= e($sale['branch_name']) ?></span></td>
                    <td><?= e($sale['cashier_name']) ?></td>
                    <td><?= number_format((int)$sale['item_count']) ?></td>
                    <td><span class="sales-payment-pill <?= e($sale['payment_method']) ?>"><?= e(sales_records_payment_label($sale['payment_method'])) ?></span></td>
                    <td><strong class="sales-table-total"><?= peso($sale['total']) ?></strong></td>
                    <td><a class="btn btn-secondary btn-sm" href="<?= e(sales_records_url($baseState, ['sale' => (int)$sale['id'], 'p' => $pageNo])) ?>#sale-detail"><?= icon('eye') ?> View</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (($totalPages ?? 1) > 1): ?>
    <div class="sales-pagination">
        <span>Page <?= number_format($pageNo) ?> of <?= number_format($totalPages) ?></span>
        <div>
            <?php if ($pageNo > 1): ?><a class="btn btn-secondary btn-sm" href="<?= e(sales_records_url($baseState, ['p' => $pageNo - 1])) ?>">Previous</a><?php endif; ?>
            <?php if ($pageNo < $totalPages): ?><a class="btn btn-secondary btn-sm" href="<?= e(sales_records_url($baseState, ['p' => $pageNo + 1])) ?>">Next</a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>
