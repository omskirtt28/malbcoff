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
// Compare matrix only for the owner on All Branches; everything else is the single-branch view.
$isMatrix = $isOwner && (($report['view'] ?? 'matrix') === 'matrix');
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
$totals = (array)($report['totals'] ?? ['opening'=>0,'stock_in'=>0,'transfer_in'=>0,'transfer_out'=>0,'sold'=>0,'other'=>0,'ending'=>0]);
$soldSplit = (array)($report['sold_split'] ?? []);
$inTransit = (array)($report['in_transit'] ?? ['units'=>0,'incoming'=>0,'outgoing'=>0]);
$transitUnits = (int)($inTransit['units'] ?? 0);
// Signed "Other" net: show a leading + for gains, the minus sign is built in for losses.
$signed = static fn(int $n): string => ($n > 0 ? '+' : '') . number_format($n);
$branchEnding = (array)($report['branch_ending'] ?? []);
$branchTotals = (array)($report['branch_totals'] ?? []);
// KPI cards always show the combined (all types) figures; the table and its totals follow the active tab.
$kpiTotals = (array)($report['overall_totals'] ?? $totals);
$tab = (string)($report['tab'] ?? 'phones');
$isAccessoriesTab = $tab === 'accessories';
$tabRows = (array)($report['tab_rows'] ?? []);

$filterParams = ['date' => $selectedDate];
if ($isOwner && $branchId) $filterParams['branch'] = (int)$branchId;
if ($brandId) $filterParams['brand'] = (int)$brandId;
if ($search !== '') $filterParams['q'] = $search;
// The tab lives in the URL (?tab=accessories); Phones & Tablets is the default and needs no parameter.
$tabParam = $isAccessoriesTab ? ['tab' => 'accessories'] : [];
$tabUrl = static fn(string $key): string => app_url('stock-monitoring', $filterParams + ($key === 'accessories' ? ['tab' => 'accessories'] : []));
// Owner KPI cards open the shared drawer for the same date / branch / brand / search as the page.
$drawerAttrs = static fn(string $kind): string => ' data-kpi-drawer="' . $kind . '" data-date="' . e($selectedDate) . '" data-branch="' . ($branchId ? (int)$branchId : '') . '" data-brand="' . ($brandId ? (int)$brandId : '') . '" data-q="' . e($search) . '"';
$exportUrl = app_url('stock-monitoring-export', $filterParams + $tabParam);
$resetUrl = app_url('stock-monitoring', $tabParam);
$tabLabel = $isAccessoriesTab ? 'Accessories' : 'Phones & Tablets';
?>
<section class="page-heading stock-monitoring-heading">
    <div>
        <span class="eyebrow"><?= $isMatrix ? 'REPORTS' : 'STOCK REPORT' ?></span>
        <h1><?= $isMatrix ? 'Overall Stock Monitoring' : 'Stock Monitoring' ?></h1>
        <p><?= $isMatrix
            ? 'View and compare daily stock levels across all branches.'
            : ($isOwner
                ? 'Monitor ' . e((string)($report['selected_branch_name'] ?? 'the branch')) . '’s daily opening, movements, and ending stock per model.'
                : 'Monitor your branch’s daily opening, movements, and ending stock per model.') ?></p>
    </div>
    <span class="branch-chip stock-monitoring-scope"><?= icon('branch') ?><span><?= e($isOwner ? (string)($report['selected_branch_name'] ?? 'All Branches') : (string)(Auth::user()['branch_name'] ?? 'Assigned Branch')) ?></span></span>
</section>

<?php if ($stockMonitorError !== ''): ?>
    <div class="alert alert-error"><?= e($stockMonitorError) ?></div>
<?php endif; ?>

<?php if ($isMatrix): ?>
<section class="stock-monitoring-kpis" aria-label="Overall stock monitoring key figures">
    <article class="stock-monitoring-kpi is-overall"<?= $drawerAttrs('overall') ?>>
        <span class="stock-monitoring-kpi-icon"><?= icon('inventory') ?></span>
        <div><span>Overall Units</span><strong><?= number_format((int)($kpiTotals['ending'] ?? 0)) ?></strong><small>Total ending stock (all branches) · In transit: <?= number_format($transitUnits) ?></small></div>
    </article>
    <article class="stock-monitoring-kpi is-in"<?= $drawerAttrs('stock_in') ?>>
        <span class="stock-monitoring-kpi-icon"><?= icon('stock') ?></span>
        <div><span>Stock In Today</span><strong><?= number_format((int)($kpiTotals['stock_in'] ?? 0)) ?></strong><small>Supplier receives &amp; returns · <?= e($formattedDate) ?></small></div>
    </article>
    <article class="stock-monitoring-kpi is-out"<?= $drawerAttrs('transfer') ?>>
        <span class="stock-monitoring-kpi-icon"><?= icon('movement') ?></span>
        <div><span>Transfer Today</span><strong><?= number_format((int)($kpiTotals['transfer_out'] ?? 0)) ?></strong><small>Units sent between branches · <?= number_format((int)($kpiTotals['transfer_in'] ?? 0)) ?> received · <?= e($formattedDate) ?></small></div>
    </article>
    <article class="stock-monitoring-kpi is-sold"<?= $drawerAttrs('sold') ?>>
        <span class="stock-monitoring-kpi-icon"><?= icon('cart') ?></span>
        <div><span>Sold Today</span><strong><?= number_format((int)($kpiTotals['sold'] ?? 0)) ?></strong>
            <small class="stock-monitoring-kpi-split"><b>Units <?= number_format((int)($kpiTotals['sold_units'] ?? 0)) ?></b> · <?= peso((float)($soldSplit['units']['amount'] ?? 0)) ?></small>
            <small class="stock-monitoring-kpi-split"><b>Accessories <?= number_format((int)($kpiTotals['sold_accessories'] ?? 0)) ?></b> · <?= peso((float)($soldSplit['accessories']['amount'] ?? 0)) ?></small>
            <small><?= e($formattedDate) ?></small></div>
    </article>
</section>
<?php elseif ($transitUnits > 0):
    $transitIn = (int)($inTransit['incoming'] ?? 0);
    $transitOut = (int)($inTransit['outgoing'] ?? 0);
?>
<div class="alert alert-info stock-monitoring-transit" role="note">
    <div>
        <strong><?= number_format($transitUnits) ?> <?= $transitUnits === 1 ? 'unit was' : 'units were' ?> sent but not received yet.</strong>
        <span>They are not counted in any branch's stock yet.
        <?php if ($transitIn > 0 || $transitOut > 0): ?>(<?= number_format($transitIn) ?> on the way to this branch, <?= number_format($transitOut) ?> sent from this branch.)<?php endif; ?></span>
    </div>
    <a class="btn btn-outline" href="<?= e(app_url('branch-transfers', ['status' => 'pending'])) ?>">View in Branch Transfers</a>
</div>
<?php endif; ?>

<form class="filter-card stock-monitoring-filter <?= $isOwner ? 'is-owner' : 'is-branch' ?>" method="get" action="<?= e(app_url('stock-monitoring')) ?>">
    <?php if ($isAccessoriesTab): ?><input type="hidden" name="tab" value="accessories"><?php endif; ?>
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

    <label class="stock-monitoring-search-field">
        <span>Search model</span>
        <div class="search-box stock-monitoring-search">
            <?= icon('search') ?>
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search model (e.g. Redmi, X9D, Poco...)" autocomplete="off">
        </div>
    </label>

    <div class="stock-monitoring-actions">
        <button class="btn btn-primary stock-monitoring-apply" type="submit">Apply</button>
        <a class="filter-reset stock-monitoring-reset" href="<?= e($resetUrl) ?>">Reset</a>
        <a class="btn btn-outline stock-monitoring-export" href="<?= e($exportUrl) ?>">
            <?= icon('stock') ?><span>Export <?= e($tabLabel) ?> to Excel</span>
        </a>
    </div>
</form>

<nav class="stock-monitoring-tabs" aria-label="Stock type">
    <a class="stock-monitoring-tab<?= $isAccessoriesTab ? '' : ' is-active' ?>" href="<?= e($tabUrl('phones')) ?>"<?= $isAccessoriesTab ? '' : ' aria-current="page"' ?>>Phones &amp; Tablets</a>
    <a class="stock-monitoring-tab<?= $isAccessoriesTab ? ' is-active' : '' ?>" href="<?= e($tabUrl('accessories')) ?>"<?= $isAccessoriesTab ? ' aria-current="page"' : '' ?>>Accessories</a>
</nav>

<section class="table-card stock-monitoring-card">
<?php if (!$rows):
    $otherTab = $isAccessoriesTab ? 'Phones & Tablets' : 'Accessories';
    $otherHasRows = (int)($tabRows[$isAccessoriesTab ? 'phones' : 'accessories'] ?? 0) > 0;
?>
    <div class="empty-state stock-monitoring-empty">
        <?= icon('inventory') ?>
        <strong>No <?= e($tabLabel) ?> stock records found</strong>
        <span><?= $otherHasRows ? 'Try the ' . e($otherTab) . ' tab, or change the date, brand, branch, or search.' : 'Try another date, brand, branch, or model search.' ?></span>
    </div>
<?php elseif ($isMatrix): ?>
    <div class="table-scroll stock-monitoring-table-wrap" tabindex="0" role="region" aria-label="<?= e($tabLabel) ?> stock table. Scroll sideways to see more columns.">
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
                        <td class="num<?= $qty === 0 ? ' is-zero' : ' is-nz' ?>" data-label="<?= e((string)$branch['name']) ?>"><?= number_format($qty) ?></td>
                    <?php endforeach; ?>
                    <td class="num stock-monitoring-overall-cell<?= $rowOverall === 0 ? ' is-zero' : '' ?>" data-label="Overall Total"><?= number_format($rowOverall) ?></td>
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
                    <th>GRAND TOTAL (<?= e($tabLabel) ?>)</th>
                    <?php foreach ($displayBranches as $branch): ?>
                        <th class="num" data-label="<?= e((string)$branch['name']) ?>"><?= number_format((int)($branchTotals[(int)$branch['id']] ?? 0)) ?></th>
                    <?php endforeach; ?>
                    <th class="num" data-label="Overall Total"><?= number_format((int)($totals['ending'] ?? 0)) ?></th>
                </tr>
            </tfoot>
        </table>
    </div>
<?php else:
    // Patch 2C: one Transfer column (In green / Out red), one Sold column per row, Other only when it has data.
    $showOther = StockMonitoringReport::hasOther($rows);
    $colCount = $showOther ? 7 : 6;
    $minus = static fn(string $text): string => str_replace('-', "\u{2212}", $text);
    $numTd = static function (int $n, string $label, string $tone = '', bool $withSign = false, string $title = '') use ($signed, $minus): string {
        $text = $withSign ? $minus($signed($n)) : number_format($n);
        $class = 'num' . ($n === 0 ? ' is-zero' : ' is-nz' . ($tone !== '' ? ' tone-' . $tone : ''));
        return '<td class="' . $class . '" data-label="' . e($label) . '"' . ($title !== '' && $n !== 0 ? ' title="' . e($title) . '"' : '') . '>' . $text . '</td>';
    };
    $transferTd = static function (int $in, int $out): string {
        if ($in === 0 && $out === 0) return '<td class="num is-zero" data-label="Transfer">0</td>';
        $bits = [];
        if ($in > 0) $bits[] = '<span class="tone-in">+' . number_format($in) . '</span>';
        if ($out > 0) $bits[] = '<span class="tone-out">' . "\u{2212}" . number_format($out) . '</span>';
        return '<td class="num is-nz" data-label="Transfer" title="Received: ' . $in . ' · Sent out: ' . $out . '">' . implode('<i> / </i>', $bits) . '</td>';
    };
    $endingTd = static fn(int $n): string => '<td class="num stock-monitoring-ending-cell' . ($n === 0 ? ' is-zero' : '') . '" data-label="Ending">' . number_format($n) . '</td>';
    $th = static function (string $label, string $tip, bool $first = false): string {
        return '<th scope="col" class="' . ($first ? 'stock-monitoring-model-head' : 'num') . '"><button type="button" class="stock-monitoring-th" data-tip="' . e($tip) . '" aria-label="' . e($label . ' — ' . $tip) . '">' . e($label) . '</button></th>';
    };
?>
    <div class="stock-monitoring-toolbar">
        <span class="stock-monitoring-hint">Tap a brand name to expand or collapse it. The brand total stays visible when it is collapsed.</span>
        <div class="stock-monitoring-toolbar-actions">
            <button type="button" class="btn btn-outline" data-stock-expand-all>Expand all</button>
            <button type="button" class="btn btn-outline" data-stock-collapse-all>Collapse all</button>
        </div>
    </div>
    <div class="table-scroll stock-monitoring-table-wrap" tabindex="0" role="region" aria-label="<?= e($tabLabel) ?> stock table. Scroll sideways to see more columns.">
        <table class="data-table stock-monitoring-table stock-monitoring-branch-table" data-stock-monitoring-table="branch">
            <thead>
                <tr>
                    <?= $th('Model', 'Model name and specs', true) ?>
                    <?= $th('Opening', 'Stock at the start of the day') ?>
                    <?= $th('Stock In', 'New stock received from suppliers') ?>
                    <?= $th('Transfer', 'Stock moved to or from other branches. Green = received, red = sent out') ?>
                    <?= $th('Sold', 'Sold to customers') ?>
                    <?php if ($showOther): ?><?= $th('Other', 'Adjustments and other changes') ?><?php endif; ?>
                    <?= $th('Ending', 'Stock at the end of the day') ?>
                </tr>
            </thead>
            <?php foreach ($groups as $groupName => $groupRows):
                $groupKey = 'brand-' . substr(md5((string)$groupName), 0, 12);
                $groupTotal = ['opening'=>0,'stock_in'=>0,'transfer_in'=>0,'transfer_out'=>0,'sold'=>0,'other'=>0,'ending'=>0];
            ?>
            <tbody class="stock-monitoring-brand-group" data-stock-brand-group="<?= e($groupKey) ?>">
                <tr class="stock-monitoring-brand-row">
                    <th colspan="<?= $colCount ?>">
                        <button type="button" class="stock-monitoring-brand-toggle" data-stock-brand-toggle="<?= e($groupKey) ?>" aria-expanded="true">
                            <span class="stock-monitoring-brand-chevron"><?= icon('chevron') ?></span>
                            <?= brand_logo_html((string)$groupName, 'stock-monitoring-brand-logo') ?>
                            <span><?= e(strtoupper((string)$groupName)) ?></span>
                        </button>
                    </th>
                </tr>
                <?php foreach ($groupRows as $row):
                    $groupTotal['opening'] += (int)$row['opening_stock'];
                    $groupTotal['stock_in'] += (int)$row['stock_in_qty'];
                    $groupTotal['transfer_in'] += (int)$row['transfer_in_qty'];
                    $groupTotal['transfer_out'] += (int)$row['transfer_out_qty'];
                    $groupTotal['sold'] += (int)$row['sold_qty'];
                    $groupTotal['other'] += (int)$row['other_qty'];
                    $groupTotal['ending'] += (int)$row['ending_stock'];
                    $rowSold = (int)$row['sold_qty'];
                    $rowOther = (int)$row['other_qty'];
                ?>
                <tr class="stock-monitoring-model-row" data-stock-brand-item="<?= e($groupKey) ?>">
                    <td class="stock-monitoring-model-cell" data-label="Model"><?= e(StockMonitoringReport::displayModel($row)) ?></td>
                    <?= $numTd((int)$row['opening_stock'], 'Opening') ?>
                    <?= $numTd((int)$row['stock_in_qty'], 'Stock In', 'in') ?>
                    <?= $transferTd((int)$row['transfer_in_qty'], (int)$row['transfer_out_qty']) ?>
                    <?= $numTd($rowSold, 'Sold', 'sold') ?>
                    <?php if ($showOther): ?><?= $numTd($rowOther, 'Other', $rowOther > 0 ? 'in' : 'out', true, 'Adjustments and other changes') ?><?php endif; ?>
                    <?= $endingTd((int)$row['ending_stock']) ?>
                </tr>
                <?php endforeach; ?>
                <tr class="stock-monitoring-total-row">
                    <td><?= e(strtoupper((string)$groupName)) ?> TOTAL</td>
                    <?= $numTd($groupTotal['opening'], 'Opening') ?>
                    <?= $numTd($groupTotal['stock_in'], 'Stock In', 'in') ?>
                    <?= $transferTd($groupTotal['transfer_in'], $groupTotal['transfer_out']) ?>
                    <?= $numTd($groupTotal['sold'], 'Sold', 'sold') ?>
                    <?php if ($showOther): ?><?= $numTd($groupTotal['other'], 'Other', $groupTotal['other'] > 0 ? 'in' : 'out', true, 'Adjustments and other changes') ?><?php endif; ?>
                    <?= $endingTd($groupTotal['ending']) ?>
                </tr>
            </tbody>
            <?php endforeach; ?>
            <tfoot>
                <tr class="stock-monitoring-grand-row">
                    <td>GRAND TOTAL (<?= e($tabLabel) ?>)</td>
                    <?= $numTd((int)($totals['opening'] ?? 0), 'Opening') ?>
                    <?= $numTd((int)($totals['stock_in'] ?? 0), 'Stock In', 'in') ?>
                    <?= $transferTd((int)($totals['transfer_in'] ?? 0), (int)($totals['transfer_out'] ?? 0)) ?>
                    <?= $numTd((int)($totals['sold'] ?? 0), 'Sold', 'sold') ?>
                    <?php if ($showOther): ?><?= $numTd((int)($totals['other'] ?? 0), 'Other', (int)($totals['other'] ?? 0) > 0 ? 'in' : 'out', true, 'Adjustments and other changes') ?><?php endif; ?>
                    <?= $endingTd((int)($totals['ending'] ?? 0)) ?>
                </tr>
            </tfoot>
        </table>
    </div>
<?php endif; ?>
</section>

<?php if ($isMatrix): ?><script src="assets/js/kpi-drawer.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/kpi-drawer.js') ?>"></script><?php endif; ?>
<script>
(function () {
    var table = document.querySelector('[data-stock-monitoring-table]');
    if (!table) return;
    var kind = table.getAttribute('data-stock-monitoring-table');
    // Branch view (Patch 2C) always opens with every brand expanded; the owner matrix keeps its remembered state.
    var remember = kind !== 'branch';
    var storageKey = 'malbcoff.stockMonitoring.collapsed.' + kind;
    var collapsed = [];
    if (remember) {
        try {
            var saved = JSON.parse(localStorage.getItem(storageKey) || '[]');
            if (Array.isArray(saved)) collapsed = saved;
        } catch (e) {}
    }

    function isCollapsed(key) { return collapsed.indexOf(key) !== -1; }
    function save() {
        if (!remember) return;
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

    function setAll(collapse) {
        collapsed = [];
        for (var n = 0; n < toggles.length; n++) {
            var k = toggles[n].getAttribute('data-stock-brand-toggle');
            if (collapse) collapsed.push(k);
            apply(k);
        }
        save();
    }
    var expandAll = document.querySelector('[data-stock-expand-all]');
    var collapseAll = document.querySelector('[data-stock-collapse-all]');
    if (expandAll) expandAll.addEventListener('click', function () { setAll(false); });
    if (collapseAll) collapseAll.addEventListener('click', function () { setAll(true); });

    // Column hints: hover / keyboard focus show them by CSS; a tap (phones) toggles .is-tip-open.
    var tips = table.querySelectorAll('.stock-monitoring-th');
    function closeTips(except) {
        for (var t = 0; t < tips.length; t++) if (tips[t] !== except) tips[t].classList.remove('is-tip-open');
    }
    for (var t = 0; t < tips.length; t++) {
        tips[t].addEventListener('click', function (event) {
            event.stopPropagation();
            var open = !this.classList.contains('is-tip-open');
            closeTips(this);
            this.classList.toggle('is-tip-open', open);
        });
    }
    document.addEventListener('click', function () { closeTips(null); });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape') closeTips(null); });
})();
</script>
