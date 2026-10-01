<?php
require_once __DIR__ . '/../core/StockMonitoringReport.php';

$stockMonitorError = '';
$report = null;
try {
    $report = StockMonitoringReport::load($_GET);
} catch (Throwable $e) {
    $stockMonitorError = 'Stock Monitoring data could not be loaded right now.';
}

$isOwner = (bool)($report['is_owner'] ?? Auth::isOwner());
$selectedDate = (string)($report['date'] ?? date('Y-m-d'));
$formattedDate = (string)($report['formatted_date'] ?? date('M d, Y'));
$branchId = $report['branch_id'] ?? null;
$brandId = $report['brand_id'] ?? null;
$search = (string)($report['search'] ?? '');
$branches = (array)($report['branches'] ?? []);
$displayBranches = (array)($report['display_branches'] ?? []);
$brands = (array)($report['brands'] ?? []);
$rows = (array)($report['rows'] ?? []);
$groups = (array)($report['groups'] ?? []);
$totals = (array)($report['totals'] ?? ['opening'=>0,'in'=>0,'out'=>0,'sold'=>0,'ending'=>0]);
$branchEnding = (array)($report['branch_ending'] ?? []);
$branchTotals = (array)($report['branch_totals'] ?? []);

$filterParams = ['date' => $selectedDate];
if ($isOwner && $branchId) $filterParams['branch'] = (int)$branchId;
if ($brandId) $filterParams['brand'] = (int)$brandId;
if ($search !== '') $filterParams['q'] = $search;
$exportUrl = app_url('stock-monitoring-export', $filterParams);
$resetUrl = app_url('stock-monitoring');
?>
<section class="page-heading stock-monitoring-heading">
    <div>
        <span class="eyebrow"><?= $isOwner ? 'REPORTS' : 'STOCK REPORT' ?></span>
        <h1><?= $isOwner ? 'Overall Stock Monitoring' : 'Stock Monitoring' ?></h1>
        <p><?= $isOwner
            ? 'View and compare daily stock levels across all branches.'
            : 'Monitor your branch’s daily opening, movements, and ending stock per model.' ?></p>
    </div>
    <span class="branch-chip stock-monitoring-scope"><?= icon('branch') ?><span><?= e($isOwner ? (string)($report['selected_branch_name'] ?? 'All Branches') : (string)(Auth::user()['branch_name'] ?? 'Assigned Branch')) ?></span></span>
</section>

<?php if ($stockMonitorError !== ''): ?>
    <div class="alert alert-error"><?= e($stockMonitorError) ?></div>
<?php endif; ?>

<?php if ($isOwner): ?>
<section class="stock-monitoring-kpis" aria-label="Overall stock monitoring key figures">
    <article class="stock-monitoring-kpi is-overall">
        <span class="stock-monitoring-kpi-icon"><?= icon('inventory') ?></span>
        <div><span>Overall Units</span><strong><?= number_format((int)($totals['ending'] ?? 0)) ?></strong><small>Total ending stock<?= $branchId ? ' · ' . e((string)($report['selected_branch_name'] ?? '')) : ' (all branches)' ?></small></div>
    </article>
    <article class="stock-monitoring-kpi is-in">
        <span class="stock-monitoring-kpi-icon"><?= icon('stock') ?></span>
        <div><span>IN Today</span><strong><?= number_format((int)($totals['in'] ?? 0)) ?></strong><small>Total units received · <?= e($formattedDate) ?></small></div>
    </article>
    <article class="stock-monitoring-kpi is-out">
        <span class="stock-monitoring-kpi-icon"><?= icon('movement') ?></span>
        <div><span>OUT Today</span><strong><?= number_format((int)($totals['out'] ?? 0)) ?></strong><small>Total units transferred out · <?= e($formattedDate) ?></small></div>
    </article>
    <article class="stock-monitoring-kpi is-sold">
        <span class="stock-monitoring-kpi-icon"><?= icon('cart') ?></span>
        <div><span>Sold Today</span><strong><?= number_format((int)($totals['sold'] ?? 0)) ?></strong><small>Total units sold (POS) · <?= e($formattedDate) ?></small></div>
    </article>
</section>
<?php endif; ?>

<form class="filter-card stock-monitoring-filter <?= $isOwner ? 'is-owner' : 'is-branch' ?>" method="get" action="<?= e(app_url('stock-monitoring')) ?>">
    <label>
        <span>Date</span>
        <input type="date" name="date" value="<?= e($selectedDate) ?>" max="<?= e(date('Y-m-d')) ?>">
    </label>

    <?php if ($isOwner): ?>
    <label>
        <span>Branch</span>
        <select name="branch">
            <option value="">All Branches</option>
            <?php foreach ($branches as $branch): ?>
                <option value="<?= (int)$branch['id'] ?>" <?= (int)$branchId === (int)$branch['id'] ? 'selected' : '' ?>><?= e((string)$branch['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php endif; ?>

    <label>
        <span>Brand</span>
        <select name="brand">
            <option value="">All Brands</option>
            <?php foreach ($brands as $brand): ?>
                <option value="<?= (int)$brand['id'] ?>" <?= (int)$brandId === (int)$brand['id'] ? 'selected' : '' ?>><?= e((string)$brand['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <a class="btn btn-outline stock-monitoring-export" href="<?= e($exportUrl) ?>">
        <?= icon('stock') ?><span>Export to Excel</span>
    </a>

    <label class="stock-monitoring-search-field">
        <span>Search model</span>
        <div class="search-box stock-monitoring-search">
            <?= icon('search') ?>
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search model (e.g. Redmi, X9D, Poco...)" autocomplete="off">
        </div>
    </label>

    <button class="btn btn-primary stock-monitoring-apply" type="submit">Apply</button>
    <a class="filter-reset stock-monitoring-reset" href="<?= e($resetUrl) ?>">Reset</a>
</form>

<section class="table-card stock-monitoring-card">
<?php if (!$rows): ?>
    <div class="empty-state stock-monitoring-empty">
        <?= icon('inventory') ?>
        <strong>No stock records found</strong>
        <span>Try another date, brand, branch, or model search.</span>
    </div>
<?php elseif ($isOwner): ?>
    <div class="table-scroll stock-monitoring-table-wrap">
        <table class="data-table stock-monitoring-table stock-monitoring-owner-table" data-stock-monitoring-table="owner">
            <thead>
                <tr>
                    <th>Model</th>
                    <?php foreach ($displayBranches as $branch): ?>
                        <th class="num"><?= e((string)$branch['name']) ?></th>
                    <?php endforeach; ?>
                    <th class="num stock-monitoring-overall-head">Overall Total</th>
                </tr>
            </thead>
            <?php foreach ($groups as $groupName => $groupRows):
                $groupKey = 'brand-' . substr(md5((string)$groupName), 0, 12);
                $groupBranchTotals = [];
                foreach ($displayBranches as $branch) $groupBranchTotals[(int)$branch['id']] = 0;
                $groupOverall = 0;
            ?>
            <tbody class="stock-monitoring-brand-group" data-stock-brand-group="<?= e($groupKey) ?>">
                <tr class="stock-monitoring-brand-row">
                    <th colspan="<?= count($displayBranches) + 2 ?>">
                        <button type="button" class="stock-monitoring-brand-toggle" data-stock-brand-toggle="<?= e($groupKey) ?>" aria-expanded="true">
                            <span class="stock-monitoring-brand-chevron"><?= icon('chevron') ?></span>
                            <?= brand_logo_html((string)$groupName, 'stock-monitoring-brand-logo') ?>
                            <span><?= e(strtoupper((string)$groupName)) ?></span>
                        </button>
                    </th>
                </tr>
                <?php foreach ($groupRows as $row):
                    $pid = (int)$row['product_id'];
                    $rowOverall = 0;
                ?>
                <tr class="stock-monitoring-model-row" data-stock-brand-item="<?= e($groupKey) ?>">
                    <td class="stock-monitoring-model-cell" data-label="Model"><?= e(StockMonitoringReport::displayModel($row)) ?></td>
                    <?php foreach ($displayBranches as $branch):
                        $bid = (int)$branch['id'];
                        $qty = (int)($branchEnding[$pid][$bid] ?? 0);
                        $groupBranchTotals[$bid] += $qty;
                        $rowOverall += $qty;
                    ?>
                        <td class="num" data-label="<?= e((string)$branch['name']) ?>"><?= number_format($qty) ?></td>
                    <?php endforeach; ?>
                    <td class="num stock-monitoring-overall-cell" data-label="Overall Total"><?= number_format($rowOverall) ?></td>
                </tr>
                <?php $groupOverall += $rowOverall; endforeach; ?>
                <tr class="stock-monitoring-total-row">
                    <td><?= e(strtoupper((string)$groupName)) ?> TOTAL</td>
                    <?php foreach ($displayBranches as $branch): ?>
                        <td class="num" data-label="<?= e((string)$branch['name']) ?>"><?= number_format((int)($groupBranchTotals[(int)$branch['id']] ?? 0)) ?></td>
                    <?php endforeach; ?>
                    <td class="num stock-monitoring-overall-cell" data-label="Overall Total"><?= number_format($groupOverall) ?></td>
                </tr>
            </tbody>
            <?php endforeach; ?>
            <tfoot>
                <tr>
                    <th>GRAND TOTAL (All Brands)</th>
                    <?php foreach ($displayBranches as $branch): ?>
                        <th class="num" data-label="<?= e((string)$branch['name']) ?>"><?= number_format((int)($branchTotals[(int)$branch['id']] ?? 0)) ?></th>
                    <?php endforeach; ?>
                    <th class="num" data-label="Overall Total"><?= number_format((int)($totals['ending'] ?? 0)) ?></th>
                </tr>
            </tfoot>
        </table>
    </div>
<?php else: ?>
    <div class="table-scroll stock-monitoring-table-wrap">
        <table class="data-table stock-monitoring-table stock-monitoring-branch-table" data-stock-monitoring-table="branch">
            <thead>
                <tr>
                    <th>Model</th>
                    <th class="num">Opening Stock</th>
                    <th class="num">IN</th>
                    <th class="num">OUT</th>
                    <th class="num">SOLD</th>
                    <th class="num">Ending Stock</th>
                </tr>
            </thead>
            <?php foreach ($groups as $groupName => $groupRows):
                $groupKey = 'brand-' . substr(md5((string)$groupName), 0, 12);
                $groupTotal = ['opening'=>0,'in'=>0,'out'=>0,'sold'=>0,'ending'=>0];
            ?>
            <tbody class="stock-monitoring-brand-group" data-stock-brand-group="<?= e($groupKey) ?>">
                <tr class="stock-monitoring-brand-row">
                    <th colspan="6">
                        <button type="button" class="stock-monitoring-brand-toggle" data-stock-brand-toggle="<?= e($groupKey) ?>" aria-expanded="true">
                            <span class="stock-monitoring-brand-chevron"><?= icon('chevron') ?></span>
                            <?= brand_logo_html((string)$groupName, 'stock-monitoring-brand-logo') ?>
                            <span><?= e(strtoupper((string)$groupName)) ?></span>
                        </button>
                    </th>
                </tr>
                <?php foreach ($groupRows as $row):
                    $groupTotal['opening'] += (int)$row['opening_stock'];
                    $groupTotal['in'] += (int)$row['in_qty'];
                    $groupTotal['out'] += (int)$row['out_qty'];
                    $groupTotal['sold'] += (int)$row['sold_qty'];
                    $groupTotal['ending'] += (int)$row['ending_stock'];
                ?>
                <tr class="stock-monitoring-model-row" data-stock-brand-item="<?= e($groupKey) ?>">
                    <td class="stock-monitoring-model-cell" data-label="Model"><?= e(StockMonitoringReport::displayModel($row)) ?></td>
                    <td class="num" data-label="Opening Stock"><?= number_format((int)$row['opening_stock']) ?></td>
                    <td class="num" data-label="IN"><?= number_format((int)$row['in_qty']) ?></td>
                    <td class="num" data-label="OUT"><?= number_format((int)$row['out_qty']) ?></td>
                    <td class="num" data-label="SOLD"><?= number_format((int)$row['sold_qty']) ?></td>
                    <td class="num stock-monitoring-overall-cell" data-label="Ending Stock"><?= number_format((int)$row['ending_stock']) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="stock-monitoring-total-row">
                    <td><?= e(strtoupper((string)$groupName)) ?> TOTAL</td>
                    <td class="num" data-label="Opening Stock"><?= number_format($groupTotal['opening']) ?></td>
                    <td class="num" data-label="IN"><?= number_format($groupTotal['in']) ?></td>
                    <td class="num" data-label="OUT"><?= number_format($groupTotal['out']) ?></td>
                    <td class="num" data-label="SOLD"><?= number_format($groupTotal['sold']) ?></td>
                    <td class="num" data-label="Ending Stock"><?= number_format($groupTotal['ending']) ?></td>
                </tr>
            </tbody>
            <?php endforeach; ?>
            <tfoot>
                <tr>
                    <th>GRAND TOTAL (All Brands)</th>
                    <th class="num" data-label="Opening Stock"><?= number_format((int)($totals['opening'] ?? 0)) ?></th>
                    <th class="num" data-label="IN"><?= number_format((int)($totals['in'] ?? 0)) ?></th>
                    <th class="num" data-label="OUT"><?= number_format((int)($totals['out'] ?? 0)) ?></th>
                    <th class="num" data-label="SOLD"><?= number_format((int)($totals['sold'] ?? 0)) ?></th>
                    <th class="num" data-label="Ending Stock"><?= number_format((int)($totals['ending'] ?? 0)) ?></th>
                </tr>
            </tfoot>
        </table>
    </div>
<?php endif; ?>
</section>

<script>
(function () {
    var table = document.querySelector('[data-stock-monitoring-table]');
    if (!table) return;
    var storageKey = 'malbcoff.stockMonitoring.collapsed.' + table.getAttribute('data-stock-monitoring-table');
    var collapsed = [];
    try {
        var saved = JSON.parse(localStorage.getItem(storageKey) || '[]');
        if (Array.isArray(saved)) collapsed = saved;
    } catch (e) {}

    function isCollapsed(key) { return collapsed.indexOf(key) !== -1; }
    function save() {
        try { localStorage.setItem(storageKey, JSON.stringify(collapsed)); } catch (e) {}
    }
    function apply(key) {
        var shouldCollapse = isCollapsed(key);
        var buttons = table.querySelectorAll('[data-stock-brand-toggle]');
        for (var i = 0; i < buttons.length; i++) {
            if (buttons[i].getAttribute('data-stock-brand-toggle') === key) {
                buttons[i].setAttribute('aria-expanded', shouldCollapse ? 'false' : 'true');
                buttons[i].classList.toggle('is-collapsed', shouldCollapse);
            }
        }
        var rows = table.querySelectorAll('[data-stock-brand-item]');
        for (var j = 0; j < rows.length; j++) {
            if (rows[j].getAttribute('data-stock-brand-item') === key) rows[j].hidden = shouldCollapse;
        }
    }

    var toggles = table.querySelectorAll('[data-stock-brand-toggle]');
    for (var i = 0; i < toggles.length; i++) {
        var key = toggles[i].getAttribute('data-stock-brand-toggle');
        apply(key);
        toggles[i].addEventListener('click', function () {
            var currentKey = this.getAttribute('data-stock-brand-toggle');
            var idx = collapsed.indexOf(currentKey);
            if (idx === -1) collapsed.push(currentKey); else collapsed.splice(idx, 1);
            apply(currentKey);
            save();
        });
    }
})();
</script>
