<?php
require_once __DIR__.'/InventoryDeletion.php';
final class StockMonitoringReport
{
    public static function load(array $input = []): array
    {
        $role = (string)(Auth::user()['role'] ?? '');
        if (!in_array($role, ['owner', 'branch_manager', 'inventory'], true)) {
            throw new RuntimeException('You do not have access to Stock Monitoring.');
        }

        $isOwner = Auth::isOwner();

        // Consolidate legacy duplicate product ids before report aggregation.
        variant_repair_live_device_duplicates();
        $branches = Database::query('SELECT id,name,code FROM branches WHERE is_active=1 ORDER BY id')->fetchAll();
        $brands = Database::query('SELECT id,name FROM brands WHERE is_active=1 ORDER BY name')->fetchAll();

        $date = self::validDate((string)($input['date'] ?? '')) ?: date('Y-m-d');
        if ($date > date('Y-m-d')) $date = date('Y-m-d');

        $brandId = self::positiveInt($input['brand'] ?? null);
        $search = trim((string)($input['q'] ?? ''));
        if (mb_strlen($search) > 120) $search = mb_substr($search, 0, 120);
        $tab = self::validTab($input['tab'] ?? null);
        $stock = in_array($input['stock'] ?? '', ['in','out'], true) ? $input['stock'] : 'all';

        $branchId = null;
        if ($isOwner) {
            $branchId = self::positiveInt($input['branch'] ?? null);
            if ($branchId !== null && !self::branchExists($branches, $branchId)) $branchId = null;
        } else {
            $branchId = Auth::branchId();
            if (!$branchId || !self::branchExists($branches, (int)$branchId)) {
                throw new RuntimeException('Your account is not assigned to an active branch.');
            }
        }

        // 'branch' = single-branch view (every branch user, or owner with one branch selected);
        // 'matrix' = owner with All Branches (one column per active branch + Overall Total).
        $view = $branchId !== null ? 'branch' : 'matrix';
        $displayBranches = $view === 'matrix' ? $branches : [];

        $startAt = $date . ' 00:00:00';
        $endAt = date('Y-m-d 00:00:00', strtotime($date . ' +1 day'));

        $where = ['sm.created_at < ?', self::validMovementSql('sm'), self::catalogSql(), 'sm.branch_id IN ('.implode(',', array_map(static fn($b) => (int)$b['id'], $branches) ?: [0]).')'];
        $whereParams = [$endAt];
        if ($branchId !== null) {
            $where[] = 'sm.branch_id = ?';
            $whereParams[] = $branchId;
        }
        [$filterWhere, $filterParams] = self::productFilter($brandId, $search);
        array_push($where, ...$filterWhere);
        array_push($whereParams, ...$filterParams);

        [$bucketSql, $bucketParams] = self::bucketSelectSql($startAt, $endAt);
        $sql = "SELECT
                    p.id product_id,p.product_type,p.product_name,p.ram,p.storage,p.connectivity,p.color,p.barcode,
                    br.name brand_name,pm.name model_name,c.name category_name,
                    {$bucketSql}
                FROM stock_movements sm
                JOIN products p ON p.id=sm.product_id
                LEFT JOIN brands br ON br.id=p.brand_id
                LEFT JOIN product_models pm ON pm.id=p.model_id
                LEFT JOIN categories c ON c.id=p.category_id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY p.id,p.product_type,p.product_name,p.ram,p.storage,p.connectivity,p.color,p.barcode,br.name,pm.name,c.name
                HAVING SUM(CASE WHEN sm.quantity>0 OR sm.movement_type='sale' THEN 1 ELSE 0 END)>0
                ORDER BY COALESCE(br.name,'ZZZ Accessories'),COALESCE(pm.name,p.product_name),p.ram,p.storage,p.connectivity,p.color";

        // Keep models with stock history even after all their quantities net to zero.
        // Availability filters apply to both types before tab totals and KPI totals are calculated.
        $allRows = Database::query($sql, [...$bucketParams, ...$whereParams])->fetchAll();
        $allRows = array_values(array_filter($allRows, static fn($r) => $stock === 'all' || ($stock === 'in' ? (int)$r['ending_stock'] > 0 : (int)$r['ending_stock'] === 0)));
        $rows = array_values(array_filter($allRows, static fn(array $row): bool => self::rowInTab($row, $tab)));

        $groups = [];
        foreach ($rows as $row) $groups[self::brandName($row)][] = $row;
        $totals = self::sumRows($rows);
        $overallTotals = self::sumRows($allRows);

        $branchEnding = [];
        $branchTotals = [];
        $overallBranchTotals = [];
        foreach ($displayBranches as $branch) $branchTotals[(int)$branch['id']] = $overallBranchTotals[(int)$branch['id']] = 0;

        if ($view === 'matrix' && $allRows) {
            $productIds = array_values(array_unique(array_map(
                static fn(array $row): int => (int)$row['product_id'],
                $allRows
            )));
            $tabProductIds = array_flip(array_map(static fn(array $row): int => (int)$row['product_id'], $rows));
            $marks = implode(',', array_fill(0, count($productIds), '?'));
            $branchWhere = ['sm.created_at < ?', self::validMovementSql('sm'), "sm.product_id IN ($marks)"];
            $branchParams = [$endAt, ...$productIds];
            $endingRows = Database::query(
                "SELECT sm.product_id,sm.branch_id,COALESCE(SUM(sm.quantity),0) ending_stock
                 FROM stock_movements sm
                 WHERE " . implode(' AND ', $branchWhere) . "
                 GROUP BY sm.product_id,sm.branch_id",
                $branchParams
            )->fetchAll();

            foreach ($endingRows as $endingRow) {
                $pid = (int)$endingRow['product_id'];
                $bid = (int)$endingRow['branch_id'];
                $qty = (int)$endingRow['ending_stock'];
                $branchEnding[$pid][$bid] = $qty;
                if (array_key_exists($bid, $overallBranchTotals)) $overallBranchTotals[$bid] += $qty;
                if (isset($tabProductIds[$pid]) && array_key_exists($bid, $branchTotals)) $branchTotals[$bid] += $qty;
            }

            // Owner's Overall Total is always the exact sum of the displayed Branch columns (per tab and combined).
            $totals['ending'] = array_sum($branchTotals);
            $overallTotals['ending'] = array_sum($overallBranchTotals);
        }

        $selectedBranchName = 'All Branches';
        if ($branchId !== null) {
            foreach ($branches as $branch) {
                if ((int)$branch['id'] === $branchId) {
                    $selectedBranchName = (string)$branch['name'];
                    break;
                }
            }
        }

        return [
            'is_owner' => $isOwner,
            'view' => $view,
            'role' => $role,
            'date' => $date,
            'formatted_date' => date('M d, Y', strtotime($date)),
            'brand_id' => $brandId,
            'search' => $search,
            'tab' => $tab,
            'stock' => $stock,
            'product_ids' => array_map(static fn($r) => (int)$r['product_id'], $allRows),
            'branch_id' => $branchId,
            'selected_branch_name' => $selectedBranchName,
            'branches' => $branches,
            'display_branches' => $displayBranches,
            'brands' => $brands,
            'rows' => $rows,
            'groups' => $groups,
            'totals' => $totals,
            'overall_totals' => $overallTotals,
            'tab_rows' => [
                'phones' => count(array_filter($allRows, static fn(array $row): bool => self::rowInTab($row, 'phones'))),
                'accessories' => count(array_filter($allRows, static fn(array $row): bool => self::rowInTab($row, 'accessories'))),
            ],
            'in_transit' => self::inTransit($endAt, $branchId),
            'sold_split' => self::soldByType($startAt, $endAt, $branchId, $brandId, $search, array_map(static fn($r) => (int)$r['product_id'], $allRows), true),
            'branch_ending' => $branchEnding,
            'branch_totals' => $branchTotals,
            'start_at' => $startAt,
            'end_at' => $endAt,
        ];
    }

    /**
     * Movement rows that count in reports. Voided originals and their "VOID-" restore
     * rows are dropped as a pair (they net to zero), so a voided sale is never Sold and a
     * restore is never Stock In, while ending stock still equals the true live stock.
     */
    public static function validMovementSql(string $alias = 'sm'): string
    {
        return inventory_active_movement_sql($alias)
            . " AND NOT ({$alias}.movement_type IN ('adjustment','return') AND COALESCE({$alias}.reference_no,'') LIKE 'VOID-%')";
    }

    /** Movement classification shared by Stock Monitoring and the Dashboard. */
    private static function bucketConditions(): array
    {
        $stockIn = "(sm.movement_type IN ('stock_in','return') AND sm.quantity > 0)";
        $transferIn = "(sm.movement_type='transfer_in' AND sm.quantity > 0)";
        $transferOut = "(sm.movement_type='transfer_out' AND sm.quantity < 0)";
        $sold = "(sm.movement_type='sale' AND sm.quantity < 0)";
        return [
            'stock_in' => [$stockIn, 'sm.quantity'],
            'transfer_in' => [$transferIn, 'sm.quantity'],
            'transfer_out' => [$transferOut, 'ABS(sm.quantity)'],
            'sold' => [$sold, 'ABS(sm.quantity)'],
            // Everything else (adjustments, stock out, defective, negative returns...) as a signed net.
            'other' => ["NOT ($stockIn OR $transferIn OR $transferOut OR $sold)", 'sm.quantity'],
        ];
    }

    /** Product-type split used everywhere "Units" and "Accessories" are reported separately. */
    public const UNITS_SQL = "p.product_type IN ('phone','tablet')";
    public const ACCESSORIES_SQL = "p.product_type = 'accessory'";

    /** SELECT list (opening, five period buckets, ending) plus its positional params. */
    private static function bucketSelectSql(string $startAt, string $endAt): array
    {
        $sql = ['COALESCE(SUM(CASE WHEN sm.created_at < ? THEN sm.quantity ELSE 0 END),0) opening_stock'];
        $params = [$startAt];
        foreach (self::bucketConditions() as $key => [$cond, $value]) {
            $sql[] = "COALESCE(SUM(CASE WHEN sm.created_at >= ? AND sm.created_at < ? AND $cond THEN $value ELSE 0 END),0) {$key}_qty";
            array_push($params, $startAt, $endAt);
        }
        // Sold split by product type (Units = phone/tablet, Accessories = accessory); needs alias p.
        [$soldCond] = self::bucketConditions()['sold'];
        foreach (['units' => self::UNITS_SQL, 'accessories' => self::ACCESSORIES_SQL] as $name => $typeSql) {
            $sql[] = "COALESCE(SUM(CASE WHEN sm.created_at >= ? AND sm.created_at < ? AND $soldCond AND $typeSql THEN ABS(sm.quantity) ELSE 0 END),0) sold_{$name}_qty";
            array_push($params, $startAt, $endAt);
        }
        $sql[] = 'COALESCE(SUM(CASE WHEN sm.created_at < ? THEN sm.quantity ELSE 0 END),0) ending_stock';
        $params[] = $endAt;
        return [implode(",\n                    ", $sql), $params];
    }

    /**
     * One day's unit totals for the KPI cards (same buckets and filters as the report),
     * optionally scoped to a branch. Used by the Dashboard so numbers match Stock Monitoring.
     */
    public static function dayTotals(string $date, ?int $branchId = null, ?int $brandId = null, string $search = ''): array
    {
        $startAt = $date . ' 00:00:00';
        $endAt = date('Y-m-d 00:00:00', strtotime($date . ' +1 day'));
        [$sql, $params] = self::bucketSelectSql($startAt, $endAt);
        $where = ['sm.created_at < ?', self::validMovementSql('sm')];
        $params[] = $endAt;
        if ($branchId !== null) {
            $where[] = 'sm.branch_id = ?';
            $params[] = $branchId;
        }
        [$filterWhere, $filterParams] = self::productFilter($brandId, $search);
        array_push($where, ...$filterWhere);
        array_push($params, ...$filterParams);
        $row = Database::query(
            "SELECT {$sql} FROM stock_movements sm
             JOIN products p ON p.id=sm.product_id
             LEFT JOIN brands br ON br.id=p.brand_id
             LEFT JOIN product_models pm ON pm.id=p.model_id
             WHERE " . implode(' AND ', $where),
            $params
        )->fetch() ?: [];
        return [
            'opening' => (int)($row['opening_stock'] ?? 0),
            'stock_in' => (int)($row['stock_in_qty'] ?? 0),
            'transfer_in' => (int)($row['transfer_in_qty'] ?? 0),
            'transfer_out' => (int)($row['transfer_out_qty'] ?? 0),
            'sold' => (int)($row['sold_qty'] ?? 0),
            'sold_units' => (int)($row['sold_units_qty'] ?? 0),
            'sold_accessories' => (int)($row['sold_accessories_qty'] ?? 0),
            'other' => (int)($row['other_qty'] ?? 0),
            'ending' => (int)($row['ending_stock'] ?? 0),
            'in_transit' => self::inTransit($endAt, $branchId),
        ];
    }

    /**
     * Sold quantity and peso amount per type from completed, non-voided sale items in [from, to).
     * Same source as Sales Records (sale_items), so Dashboard and Sales Records agree.
     * @return array{units:array{qty:int,amount:float},accessories:array{qty:int,amount:float}}
     */
    public static function soldByType(string $from, string $to, ?int $branchId = null, ?int $brandId = null, string $search = '', ?array $productIds = null, bool $activeBranchesOnly = false): array
    {
        $where = ["s.status='completed'", 's.created_at >= ?', 's.created_at < ?', inventory_active_sale_item_sql('si')];
        $params = [$from, $to];
        if ($productIds !== null) $where[] = 'p.id IN ('.implode(',', $productIds ?: [0]).')';
        if ($activeBranchesOnly) $where[] = 'EXISTS (SELECT 1 FROM branches visible_branch WHERE visible_branch.id=s.branch_id AND visible_branch.is_active=1)';
        if ($branchId !== null) {
            $where[] = 's.branch_id = ?';
            $params[] = $branchId;
        }
        [$filterWhere, $filterParams] = self::productFilter($brandId, $search);
        array_push($where, ...$filterWhere);
        array_push($params, ...$filterParams);
        $row = Database::query(
            'SELECT COALESCE(SUM(CASE WHEN ' . self::UNITS_SQL . ' THEN si.quantity END),0) units_qty,
                    COALESCE(SUM(CASE WHEN ' . self::UNITS_SQL . ' THEN si.line_total END),0) units_amount,
                    COALESCE(SUM(CASE WHEN ' . self::ACCESSORIES_SQL . ' THEN si.quantity END),0) acc_qty,
                    COALESCE(SUM(CASE WHEN ' . self::ACCESSORIES_SQL . ' THEN si.line_total END),0) acc_amount
             FROM sale_items si JOIN sales s ON s.id=si.sale_id JOIN products p ON p.id=si.product_id
             LEFT JOIN brands br ON br.id=p.brand_id LEFT JOIN product_models pm ON pm.id=p.model_id
             WHERE ' . implode(' AND ', $where),
            $params
        )->fetch() ?: [];
        return [
            'units' => ['qty' => (int)($row['units_qty'] ?? 0), 'amount' => (float)($row['units_amount'] ?? 0)],
            'accessories' => ['qty' => (int)($row['acc_qty'] ?? 0), 'amount' => (float)($row['acc_amount'] ?? 0)],
        ];
    }

    /**
     * Units sent but not yet received as of the end of the day. They sit in no branch's
     * ending stock. For a branch: 'incoming' heads to it, 'outgoing' left it.
     */
    public static function inTransit(string $endAt, ?int $branchId = null): array
    {
        $result = ['units' => 0, 'incoming' => 0, 'outgoing' => 0];
        try {
            $params = [$endAt, $endAt];
            $branchSql = '';
            if ($branchId !== null) {
                $branchSql = ' AND (source_branch_id = ? OR destination_branch_id = ?)';
                array_push($params, $branchId, $branchId);
            }
            $rows = Database::query(
                "SELECT source_branch_id,destination_branch_id,quantity FROM inventory_transfers
                 WHERE forwarded_at < ? AND (status='pending' OR (status='received' AND received_at >= ?)){$branchSql}",
                $params
            )->fetchAll();
        } catch (Throwable $e) {
            return $result;
        }
        foreach ($rows as $row) {
            $qty = (int)$row['quantity'];
            $result['units'] += $qty;
            if ($branchId !== null && (int)$row['destination_branch_id'] === $branchId) $result['incoming'] += $qty;
            if ($branchId !== null && (int)$row['source_branch_id'] === $branchId) $result['outgoing'] += $qty;
        }
        return $result;
    }

    /** Plain-text Transfer cell shared by the export: "+2 / −1", "+2", "−1" or "0" (Transfer In green / Out red on screen). */
    public static function transferLabel(int $in, int $out): string
    {
        $parts = [];
        if ($in > 0) $parts[] = '+' . number_format($in);
        if ($out > 0) $parts[] = "\u{2212}" . number_format($out);
        return $parts ? implode(' / ', $parts) : '0';
    }

    /** Group/Grand total of Sold, split by type: "Units 3 · Accessories 5". */
    public static function soldSplitLabel(int $units, int $accessories): string
    {
        return 'Units ' . number_format($units) . ' · Accessories ' . number_format($accessories);
    }

    /** Stock Monitoring tabs: 'phones' (phones & tablets, default) or 'accessories'. Anything else falls back to the default. */
    public static function validTab(mixed $value): string
    {
        return $value === 'accessories' ? 'accessories' : 'phones';
    }

    /** Everything that is not an accessory is a device, so legacy device types never fall out of both tabs. */
    private static function rowInTab(array $row, string $tab): bool
    {
        $isAccessory = (string)($row['product_type'] ?? '') === 'accessory';
        return $tab === 'accessories' ? $isAccessory : !$isAccessory;
    }

    /** Sum the per-product bucket columns of report rows. */
    private static function sumRows(array $rows): array
    {
        $totals = ['opening' => 0, 'stock_in' => 0, 'transfer_in' => 0, 'transfer_out' => 0, 'sold' => 0, 'sold_units' => 0, 'sold_accessories' => 0, 'other' => 0, 'ending' => 0];
        foreach ($rows as $row) {
            $totals['opening'] += (int)$row['opening_stock'];
            $totals['stock_in'] += (int)$row['stock_in_qty'];
            $totals['transfer_in'] += (int)$row['transfer_in_qty'];
            $totals['transfer_out'] += (int)$row['transfer_out_qty'];
            $totals['sold'] += (int)$row['sold_qty'];
            $totals['sold_units'] += (int)$row['sold_units_qty'];
            $totals['sold_accessories'] += (int)$row['sold_accessories_qty'];
            $totals['other'] += (int)$row['other_qty'];
            $totals['ending'] += (int)$row['ending_stock'];
        }
        return $totals;
    }

    /** The Other column is shown only when at least one row has a non-zero value. */
    public static function hasOther(array $rows): bool
    {
        foreach ($rows as $row) {
            if ((int)($row['other_qty'] ?? 0) !== 0) return true;
        }
        return false;
    }

    private static function catalogSql(): string
    {
        return inventory_catalog_product_sql('p');
    }

    /** WHERE parts + params for the Brand / Search filters (needs aliases p, br, pm). */
    private static function productFilter(?int $brandId, string $search): array
    {
        $where = [];
        $params = [];
        if ($brandId !== null) {
            $where[] = 'p.brand_id = ?';
            $params[] = $brandId;
        }
        if ($search !== '') {
            $where[] = "(COALESCE(br.name,'') LIKE ? OR COALESCE(pm.name,'') LIKE ? OR COALESCE(p.product_name,'') LIKE ? OR COALESCE(p.ram,'') LIKE ? OR COALESCE(p.storage,'') LIKE ? OR COALESCE(p.connectivity,'') LIKE ? OR COALESCE(p.color,'') LIKE ? OR COALESCE(p.barcode,'') LIKE ?)";
            $needle = '%' . $search . '%';
            for ($i = 0; $i < 8; $i++) $params[] = $needle;
        }
        return [$where, $params];
    }

    private const DRAWER_ROW_LIMIT = 1500;

    /**
     * Read-only data for the KPI drawer (owner only). Totals come from the same bucket SQL
     * as the KPI cards (dayTotals), so a drawer total always equals the card for the same
     * date / branch / brand / search. Kinds: overall | stock_in | transfer | sold.
     * basis 'live' (Dashboard "Total Inventory") counts available units + accessory balances now.
     */
    public static function drawer(array $input): array
    {
        if (!Auth::isOwner()) throw new RuntimeException('Owner access only.');
        $kind = (string)($input['kind'] ?? '');
        if (!in_array($kind, ['overall', 'stock_in', 'transfer', 'sold', 'sales_today', 'sales_month'], true)) {
            throw new InvalidArgumentException('Unknown drawer.');
        }

        $branches = Database::query('SELECT id,name FROM branches WHERE is_active=1 ORDER BY id')->fetchAll();
        $date = self::validDate((string)($input['date'] ?? '')) ?: date('Y-m-d');
        if ($date > date('Y-m-d')) $date = date('Y-m-d');
        $branchId = self::positiveInt($input['branch'] ?? null);
        if ($branchId !== null && !self::branchExists($branches, $branchId)) $branchId = null;
        $brandId = self::positiveInt($input['brand'] ?? null);
        $search = trim((string)($input['q'] ?? ''));
        if (mb_strlen($search) > 120) $search = mb_substr($search, 0, 120);
        $tab = ($input['tab'] ?? '') === 'in' ? 'in' : 'out';
        $soldType = $kind === 'sold' && in_array($input['type'] ?? '', ['units', 'accessories'], true) ? (string)$input['type'] : '';
        $live = $kind === 'overall' && ($input['basis'] ?? '') === 'live';
        // Live inventory drawers (Dashboard): '' = Total Inventory, or phone | tablet | accessories.
        $liveType = $live && in_array($input['type'] ?? '', ['phone', 'tablet', 'accessories'], true) ? (string)$input['type'] : '';

        $startAt = $date . ' 00:00:00';
        $endAt = date('Y-m-d 00:00:00', strtotime($date . ' +1 day'));

        if ($kind === 'sales_today' || $kind === 'sales_month') {
            return self::salesDrawer($kind, $date, $branchId, $branches);
        }
        $monitorReport = !empty($input['monitoring']) ? self::load($input) : null;
        $productIds = $monitorReport['product_ids'] ?? null;
        $totals = $monitorReport['overall_totals'] ?? self::dayTotals($date, $branchId, $brandId, $search);

        $out = [
            'kind' => $kind,
            'date' => $date,
            'date_label' => date('M d, Y', strtotime($date)),
            'branch_id' => $branchId,
            'tab' => $tab,
            'branches' => array_map(static fn(array $b): array => ['id' => (int)$b['id'], 'name' => (string)$b['name']], $branches),
            'truncated' => false,
        ];

        if ($kind === 'overall') {
            $groups = $live
                ? self::overallLive($branchId, $branches, $liveType)
                : self::overallLedger($endAt, $branchId, $brandId, $search, $branches, $productIds);
            $out['title'] = $live
                ? ['' => 'Total Inventory', 'phone' => 'Phones Available', 'tablet' => 'Tablets Available', 'accessories' => 'Accessories Stock'][$liveType]
                : 'Overall Units';
            $out['basis'] = $live ? 'live' : 'ledger';
            $out['total'] = $live ? array_sum(array_column($groups, 'qty')) : $totals['ending'];
            $out['groups'] = $groups;
            return $out;
        }

        $bucket = ['stock_in' => 'stock_in', 'sold' => 'sold', 'transfer' => $tab === 'in' ? 'transfer_in' : 'transfer_out'][$kind];
        [$cond] = self::bucketConditions()[$bucket];
        $where = ['sm.created_at >= ?', 'sm.created_at < ?', self::validMovementSql('sm'), $cond];
        if ($soldType !== '') $where[] = $soldType === 'units' ? self::UNITS_SQL : self::ACCESSORIES_SQL;
        $params = [$startAt, $endAt];
        if ($productIds !== null) {
            $where[] = 'p.id IN ('.implode(',', $productIds ?: [0]).')';
            $where[] = 'sm.branch_id IN ('.implode(',', array_column($branches, 'id') ?: [0]).')';
        }
        if ($branchId !== null) {
            $where[] = 'sm.branch_id = ?';
            $params[] = $branchId;
        }
        [$filterWhere, $filterParams] = self::productFilter($brandId, $search);
        array_push($where, ...$filterWhere);
        array_push($params, ...$filterParams);

        $extraSelect = '';
        $extraJoin = '';
        if ($kind === 'transfer') {
            $extraSelect = ',t.status transfer_status,t.forwarded_at,t.received_at,bs.name from_name,bd.name to_name';
            $extraJoin = 'LEFT JOIN inventory_transfers t ON t.reference_no=sm.reference_no
                          LEFT JOIN branches bs ON bs.id=t.source_branch_id
                          LEFT JOIN branches bd ON bd.id=t.destination_branch_id';
        } elseif ($kind === 'sold') {
            $extraSelect = ",s.id sale_id,s.sale_no," . snapshot_name_sql('sales', 's', 'cashier_name', 'cu.name') . " cashier_name,
                (SELECT COALESCE(SUM(si.line_total),0) FROM sale_items si WHERE si.sale_id=s.id AND si.product_id=sm.product_id AND si.unit_id <=> sm.unit_id) amount";
            $extraJoin = 'LEFT JOIN sales s ON s.sale_no=sm.reference_no
                          LEFT JOIN users cu ON cu.id=s.created_by';
        }

        $limit = self::DRAWER_ROW_LIMIT + 1;
        $actorSql = snapshot_name_sql('stock_movements', 'sm', 'created_by_name', 'u.name');
        $snapshotSelect = InventoryDeletion::snapshotsReady() ? ',sm.deleted_unit_id,sm.unit_snapshot' : ',NULL deleted_unit_id,NULL unit_snapshot';
        $rows = Database::query(
            "SELECT sm.id,sm.product_id,sm.unit_id,sm.branch_id,sm.movement_type,sm.quantity,sm.reference_no,sm.notes,sm.created_at,sm.created_by{$snapshotSelect},
                    b.name branch_name,{$actorSql} actor_name,
                    p.product_type,p.product_name,p.ram,p.storage,p.connectivity,p.color,p.barcode,
                    br.name brand_name,pm.name model_name{$extraSelect}
             FROM stock_movements sm
             JOIN products p ON p.id=sm.product_id
             LEFT JOIN brands br ON br.id=p.brand_id
             LEFT JOIN product_models pm ON pm.id=p.model_id
             JOIN branches b ON b.id=sm.branch_id
             LEFT JOIN users u ON u.id=sm.created_by
             {$extraJoin}
             WHERE " . implode(' AND ', $where) . "
             ORDER BY sm.created_at DESC,sm.id DESC
             LIMIT {$limit}",
            $params
        )->fetchAll();
        if (count($rows) > self::DRAWER_ROW_LIMIT) {
            array_pop($rows);
            $out['truncated'] = true;
        }

        $unitIds = self::movementUnitIds($kind === 'stock_in' ? $rows : array_values(array_filter($rows, static fn(array $r): bool => $r['unit_id'] !== null)));
        $unitLabels = self::unitLabels(array_unique(array_merge(...array_values($unitIds) ?: [[]])));

        $groups = [];
        foreach ($rows as $row) {
            $key = (string)$row['product_id'];
            if (!isset($groups[$key])) {
                $groups[$key] = ['key' => $key, 'label' => self::displayModel($row), 'brand' => self::brandName($row), 'qty' => 0, 'entries' => []];
            }
            $qty = abs((int)$row['quantity']);
            $units = array_values(array_filter(array_map(
                static fn(int $id): ?string => $unitLabels[$id] ?? null,
                $unitIds[(int)$row['id']] ?? []
            )));
            if (!empty($row['deleted_unit_id'])) {
                $snapshot = json_decode((string)$row['unit_snapshot'], true) ?: [];
                $units = ['Unit deleted · '.self::identifierLabel($snapshot + ['imei' => null, 'imei2' => null, 'serial_no' => null])];
            }
            $entry = [
                'branch' => (string)$row['branch_name'],
                'qty' => $qty,
                'time' => date('M d, h:i A', strtotime((string)$row['created_at'])),
                'ref' => (string)($row['reference_no'] ?? ''),
                'note' => (string)($row['notes'] ?? ''),
                'actor' => (string)($row['actor_name'] ?? ''),
                'units' => $units,
                'missing' => ($row['product_type'] ?? '') === 'accessory' ? 0 : max(0, $qty - count($units)),
            ];
            if ($kind === 'transfer') {
                $entry['from'] = (string)($row['from_name'] ?? '');
                $entry['to'] = (string)($row['to_name'] ?? '');
                $entry['status'] = $tab === 'in' ? 'received' : (string)($row['transfer_status'] ?? 'pending');
            } elseif ($kind === 'sold') {
                $entry['amount'] = (float)($row['amount'] ?? 0);
                $entry['cashier'] = (string)($row['cashier_name'] ?? '');
                $entry['sale_no'] = (string)($row['sale_no'] ?? $row['reference_no'] ?? '');
                $entry['sale_url'] = !empty($row['sale_id'])
                    ? app_url('sales-records', ['sale' => (int)$row['sale_id'], 'from' => $date, 'to' => $date], 'sale-detail')
                    : '';
            }
            $groups[$key]['qty'] += $qty;
            $groups[$key]['entries'][] = $entry;
        }

        $out['title'] = $soldType === 'units' ? 'Units Sold' : ($soldType === 'accessories' ? 'Accessories Sold' : ['stock_in' => 'Stock In', 'transfer' => 'Transfer', 'sold' => 'Sold'][$kind]);
        $out['groups'] = array_values($groups);
        $out['total'] = $kind === 'stock_in' ? $totals['stock_in']
            : ($kind === 'sold' ? ($soldType === 'units' ? $totals['sold_units'] : ($soldType === 'accessories' ? $totals['sold_accessories'] : $totals['sold'])) : ($tab === 'in' ? $totals['transfer_in'] : $totals['transfer_out']));
        if ($kind === 'transfer') $out['tab_totals'] = ['in' => $totals['transfer_in'], 'out' => $totals['transfer_out']];
        return $out;
    }

    /**
     * Dashboard "Sales Today" / "Sales This Month" drawers: completed (never voided) sales for the
     * branch scope, same date boundaries as the cards. Month view is grouped by day with a day subtotal.
     */
    private static function salesDrawer(string $kind, string $date, ?int $branchId, array $branches): array
    {
        $isMonth = $kind === 'sales_month';
        $from = $isMonth ? date('Y-m-01', strtotime($date)) : $date;
        $startAt = $from . ' 00:00:00';
        $endAt = date('Y-m-d 00:00:00', strtotime($date . ' +1 day'));
        $where = ["s.status='completed'", 's.created_at >= ?', 's.created_at < ?'];
        $params = [$startAt, $endAt];
        if ($branchId !== null) {
            $where[] = 's.branch_id = ?';
            $params[] = $branchId;
        }
        $whereSql = implode(' AND ', $where);

        $sum = Database::query("SELECT COUNT(*) n,COALESCE(SUM(s.total),0) total FROM sales s WHERE {$whereSql}", $params)->fetch() ?: [];
        $limit = self::DRAWER_ROW_LIMIT + 1;
        $rows = Database::query(
            "SELECT s.id,s.sale_no,s.total,s.payment_method,s.created_at,b.name branch_name," . snapshot_name_sql('sales', 's', 'cashier_name', 'u.name') . " cashier_name
             FROM sales s
             JOIN branches b ON b.id=s.branch_id
             JOIN users u ON u.id=s.created_by
             WHERE {$whereSql}
             ORDER BY s.created_at DESC,s.id DESC
             LIMIT {$limit}",
            $params
        )->fetchAll();
        $truncated = count($rows) > self::DRAWER_ROW_LIMIT;
        if ($truncated) array_pop($rows);

        $paymentLabels = ['cash' => 'Cash', 'gcash' => 'GCash', 'maya' => 'Maya', 'card' => 'Card', 'bank_transfer' => 'Bank Transfer'];
        $groups = [];
        foreach ($rows as $row) {
            $payment = $paymentLabels[(string)$row['payment_method']] ?? 'Cash';
            $amount = (float)$row['total'];
            $entry = [
                'branch' => (string)$row['branch_name'],
                'time' => date($isMonth ? 'h:i A' : 'M d, h:i A', strtotime((string)$row['created_at'])),
                'sale_no' => (string)$row['sale_no'],
                'sale_url' => app_url('sales-records', ['sale' => (int)$row['id'], 'from' => substr((string)$row['created_at'], 0, 10), 'to' => substr((string)$row['created_at'], 0, 10)], 'sale-detail'),
                'amount' => $amount,
                'payment' => $payment,
                'cashier' => (string)$row['cashier_name'],
            ];
            if ($isMonth) {
                $day = substr((string)$row['created_at'], 0, 10);
                if (!isset($groups[$day])) {
                    $groups[$day] = ['key' => $day, 'label' => date('D, M d', strtotime($day)), 'brand' => '', 'qty' => 0, 'value' => 0.0, 'entries' => []];
                }
                $groups[$day]['qty']++;
                $groups[$day]['value'] += $amount;
                $groups[$day]['brand'] = $groups[$day]['qty'] . ' transaction' . ($groups[$day]['qty'] === 1 ? '' : 's');
                $groups[$day]['entries'][] = $entry;
            } else {
                $groups[(string)$row['id']] = [
                    'key' => (string)$row['id'], 'label' => (string)$row['sale_no'],
                    'brand' => $row['branch_name'] . ' · ' . $payment . ' · ' . $row['cashier_name'],
                    'qty' => 1, 'value' => $amount, 'entries' => [$entry],
                ];
            }
        }

        $link = ['from' => $from, 'to' => $date];
        if ($branchId !== null) $link['branch'] = $branchId;
        return [
            'kind' => $kind,
            'date' => $date,
            'date_label' => $isMonth ? date('F Y', strtotime($date)) : date('M d, Y', strtotime($date)),
            'branch_id' => $branchId,
            'tab' => 'out',
            'basis' => '',
            'branches' => array_map(static fn(array $b): array => ['id' => (int)$b['id'], 'name' => (string)$b['name']], $branches),
            'truncated' => $truncated,
            'title' => $isMonth ? 'Sales This Month' : 'Sales Today',
            'money' => true,
            'total' => (float)($sum['total'] ?? 0),
            'total_label' => (int)($sum['n'] ?? 0) . ' completed transaction' . ((int)($sum['n'] ?? 0) === 1 ? '' : 's'),
            'view_url' => app_url('sales-records', $link),
            'groups' => array_values($groups),
        ];
    }

    /** Per-model, per-branch stock from the ledger as of end of day, with unit identifiers. */
    private static function overallLedger(string $endAt, ?int $branchId, ?int $brandId, string $search, array $branches, ?array $productIds = null): array
    {
        $where = ['sm.created_at < ?', self::validMovementSql('sm')];
        $params = [$endAt];
        if ($productIds !== null) {
            $where[] = 'p.id IN ('.implode(',', $productIds ?: [0]).')';
            $where[] = 'sm.branch_id IN ('.implode(',', array_column($branches, 'id') ?: [0]).')';
        }
        if ($branchId !== null) {
            $where[] = 'sm.branch_id = ?';
            $params[] = $branchId;
        }
        [$filterWhere, $filterParams] = self::productFilter($brandId, $search);
        array_push($where, ...$filterWhere);
        array_push($params, ...$filterParams);
        $joins = 'JOIN products p ON p.id=sm.product_id LEFT JOIN brands br ON br.id=p.brand_id LEFT JOIN product_models pm ON pm.id=p.model_id';
        $whereSql = implode(' AND ', $where);

        $stockRows = Database::query(
            "SELECT p.id product_id,p.product_type,p.product_name,p.ram,p.storage,p.connectivity,p.color,p.barcode,
                    br.name brand_name,pm.name model_name,sm.branch_id,SUM(sm.quantity) qty
             FROM stock_movements sm {$joins}
             WHERE {$whereSql}
             GROUP BY p.id,p.product_type,p.product_name,p.ram,p.storage,p.connectivity,p.color,p.barcode,br.name,pm.name,sm.branch_id
             HAVING qty<>0
             ORDER BY COALESCE(br.name,'ZZZ Accessories'),COALESCE(pm.name,p.product_name),p.ram,p.storage,p.connectivity,p.color",
            $params
        )->fetchAll();

        // Unit-level net stock per (unit, branch): linked movements + units of unlinked batch stock-ins.
        $unitNet = [];
        $linked = Database::query(
            "SELECT sm.unit_id,sm.branch_id,SUM(sm.quantity) qty FROM stock_movements sm {$joins}
             WHERE {$whereSql} AND sm.unit_id IS NOT NULL GROUP BY sm.unit_id,sm.branch_id",
            $params
        )->fetchAll();
        foreach ($linked as $r) $unitNet[(int)$r['branch_id']][(int)$r['unit_id']] = (int)$r['qty'];
        $snapshotMeta = [];
        $snapshotFilter = '';
        if (InventoryDeletion::snapshotsReady()) {
            $snapshotFilter = ' AND sm.deleted_unit_id IS NULL';
            $detached = Database::query("SELECT sm.deleted_unit_id,sm.product_id,sm.branch_id,SUM(sm.quantity) qty,MAX(sm.unit_snapshot) unit_snapshot FROM stock_movements sm {$joins} WHERE {$whereSql} AND sm.deleted_unit_id IS NOT NULL GROUP BY sm.deleted_unit_id,sm.product_id,sm.branch_id", $params)->fetchAll();
            foreach ($detached as $r) {
                $uid = (int)$r['deleted_unit_id'];
                $unitNet[(int)$r['branch_id']][$uid] = (int)$r['qty'];
                $snapshot = json_decode((string)$r['unit_snapshot'], true) ?: [];
                $snapshotMeta[$uid] = ['product_id' => (int)$r['product_id'], 'label' => 'Unit deleted · '.self::identifierLabel($snapshot + ['imei' => null, 'imei2' => null, 'serial_no' => null])];
            }
        }
        $batches = Database::query(
            "SELECT sm.id,sm.product_id,sm.unit_id,sm.branch_id,sm.movement_type,sm.quantity,sm.created_at,sm.created_by FROM stock_movements sm {$joins}
             WHERE {$whereSql} AND sm.unit_id IS NULL{$snapshotFilter} AND sm.movement_type='stock_in' AND sm.quantity > 0 AND p.product_type<>'accessory'",
            $params
        )->fetchAll();
        foreach (self::movementUnitIds($batches) as $movementId => $ids) {
            $bid = 0;
            foreach ($batches as $b) if ((int)$b['id'] === $movementId) { $bid = (int)$b['branch_id']; break; }
            foreach ($ids as $uid) $unitNet[$bid][$uid] = ($unitNet[$bid][$uid] ?? 0) + 1;
        }

        $inStock = [];
        foreach ($unitNet as $bid => $units) foreach ($units as $uid => $net) if ($net > 0) $inStock[$bid][] = $uid;
        $allUnitIds = [];
        foreach ($inStock as $ids) array_push($allUnitIds, ...$ids);
        $unitMeta = self::unitMeta($allUnitIds) + $snapshotMeta;

        $branchNames = [];
        foreach ($branches as $b) $branchNames[(int)$b['id']] = (string)$b['name'];
        $groups = [];
        foreach ($stockRows as $row) {
            $pid = (int)$row['product_id'];
            $bid = (int)$row['branch_id'];
            $key = (string)$pid;
            if (!isset($groups[$key])) {
                $groups[$key] = ['key' => $key, 'label' => self::displayModel($row), 'brand' => self::brandName($row), 'qty' => 0, 'entries' => []];
            }
            $units = [];
            foreach ($inStock[$bid] ?? [] as $uid) {
                if (($unitMeta[$uid]['product_id'] ?? 0) === $pid) $units[] = $unitMeta[$uid]['label'];
            }
            $qty = (int)$row['qty'];
            $groups[$key]['qty'] += $qty;
            $groups[$key]['entries'][] = [
                'branch' => $branchNames[$bid] ?? ('Branch ' . $bid),
                'qty' => $qty,
                'units' => $units,
                'missing' => ($row['product_type'] ?? '') === 'accessory' ? 0 : max(0, $qty - count($units)),
            ];
        }
        return array_values($groups);
    }

    /** Dashboard "Total Inventory": units available now (+ accessory balances), same queries as the card. */
    private static function overallLive(?int $branchId, array $branches, string $liveType = ''): array
    {
        $branchNames = [];
        foreach ($branches as $b) $branchNames[(int)$b['id']] = (string)$b['name'];
        $unitWhere = $branchId !== null ? ' AND iu.branch_id=?' : '';
        $balWhere = $branchId !== null ? ' AND ib.branch_id=?' : '';
        $p = $branchId !== null ? [$branchId] : [];
        // phone / tablet narrow the devices and drop accessories; accessories drops the devices.
        $typed = in_array($liveType, ['phone', 'tablet'], true);
        $devicePhoneTablet = $typed ? ' AND p.product_type=?' : '';
        $deviceParams = $typed ? [...$p, $liveType] : $p;
        $skipDevices = $liveType === 'accessories';
        $skipBalances = in_array($liveType, ['phone', 'tablet'], true);
        $select = 'p.id product_id,p.product_type,p.product_name,p.ram,p.storage,p.connectivity,p.color,p.barcode,br.name brand_name,pm.name model_name';
        $joins = 'JOIN products p ON p.id=%s LEFT JOIN brands br ON br.id=p.brand_id LEFT JOIN product_models pm ON pm.id=p.model_id';
        $catalogSql = self::catalogSql();

        $deviceRows = $skipDevices ? [] : Database::query(
            "SELECT {$select},iu.branch_id,iu.id unit_id,iu.imei,iu.imei2,iu.serial_no
             FROM inventory_units iu " . sprintf($joins, 'iu.product_id') . "
             WHERE iu.status='available' AND {$catalogSql}{$unitWhere}{$devicePhoneTablet}
             ORDER BY COALESCE(br.name,'ZZZ Accessories'),COALESCE(pm.name,p.product_name),iu.branch_id,iu.id",
            $deviceParams
        )->fetchAll();
        $balanceRows = $skipBalances ? [] : Database::query(
            "SELECT {$select},ib.branch_id,ib.quantity qty
             FROM inventory_balances ib " . sprintf($joins, 'ib.product_id') . "
             WHERE p.product_type='accessory' AND {$catalogSql}{$balWhere}
             ORDER BY COALESCE(br.name,'ZZZ Accessories'),p.product_name,ib.branch_id",
            $p
        )->fetchAll();

        $groups = [];
        $slot = static function (array &$groups, array $row): void {
            $key = (string)$row['product_id'];
            if (!isset($groups[$key])) {
                $groups[$key] = ['key' => $key, 'label' => self::displayModel($row), 'brand' => self::brandName($row), 'qty' => 0, 'entries' => []];
            }
        };
        foreach ($deviceRows as $row) {
            $slot($groups, $row);
            $key = (string)$row['product_id'];
            $bid = (int)$row['branch_id'];
            $idx = null;
            foreach ($groups[$key]['entries'] as $i => $e) if ($e['_bid'] === $bid) { $idx = $i; break; }
            if ($idx === null) {
                $groups[$key]['entries'][] = ['_bid' => $bid, 'branch' => $branchNames[$bid] ?? ('Branch ' . $bid), 'qty' => 0, 'units' => [], 'missing' => 0];
                $idx = count($groups[$key]['entries']) - 1;
            }
            $groups[$key]['entries'][$idx]['qty']++;
            $groups[$key]['entries'][$idx]['units'][] = self::identifierLabel($row);
            $groups[$key]['qty']++;
        }
        foreach ($balanceRows as $row) {
            $qty = (int)$row['qty'];
            if ($qty === 0) continue;
            $slot($groups, $row);
            $bid = (int)$row['branch_id'];
            $groups[(string)$row['product_id']]['entries'][] = ['_bid' => $bid, 'branch' => $branchNames[$bid] ?? ('Branch ' . $bid), 'qty' => $qty, 'units' => [], 'missing' => 0];
            $groups[(string)$row['product_id']]['qty'] += $qty;
        }
        foreach ($groups as &$g) foreach ($g['entries'] as &$e) unset($e['_bid']);
        unset($g, $e);
        return array_values($groups);
    }

    /**
     * Unit ids behind each movement: its own unit_id, or — for an unlinked multi-unit stock-in —
     * the units created by the same user/product within seconds, accepted only when the count
     * equals the movement quantity (otherwise the units stay unknown rather than guessed).
     * @return array<int, int[]> movement id => unit ids
     */
    private static function movementUnitIds(array $movements): array
    {
        $result = [];
        $batch = [];
        foreach ($movements as $m) {
            if (!empty($m['deleted_unit_id'])) continue; // Snapshot stock-ins are never guessed as live batches.
            if ($m['unit_id'] !== null) {
                $result[(int)$m['id']] = [(int)$m['unit_id']];
            } elseif (($m['movement_type'] ?? 'stock_in') === 'stock_in' && ($m['product_type'] ?? 'phone') !== 'accessory' && (int)$m['quantity'] > 0) {
                $batch[] = $m;
            }
        }
        if (!$batch) return $result;

        $productIds = array_values(array_unique(array_map(static fn(array $m): int => (int)$m['product_id'], $batch)));
        $times = array_map(static fn(array $m): int => strtotime((string)$m['created_at']), $batch);
        $marks = implode(',', array_fill(0, count($productIds), '?'));
        $units = Database::query(
            "SELECT id,product_id,created_by,UNIX_TIMESTAMP(created_at) ts FROM inventory_units
             WHERE product_id IN ({$marks}) AND created_at BETWEEN ? AND ?",
            [...$productIds, date('Y-m-d H:i:s', min($times) - 5), date('Y-m-d H:i:s', max($times) + 5)]
        )->fetchAll();
        foreach ($batch as $m) {
            $ts = strtotime((string)$m['created_at']);
            $match = [];
            foreach ($units as $u) {
                if ((int)$u['product_id'] === (int)$m['product_id'] && (int)$u['created_by'] === (int)$m['created_by'] && abs((int)$u['ts'] - $ts) <= 3) $match[] = (int)$u['id'];
            }
            if (count($match) === (int)$m['quantity']) $result[(int)$m['id']] = $match;
        }
        return $result;
    }

    private static function identifierLabel(array $u): string
    {
        $parts = [];
        $u['imei'] = $u['imei'] ?: ($u['archived_imei'] ?? null);
        $u['imei2'] = $u['imei2'] ?: ($u['archived_imei2'] ?? null);
        $u['serial_no'] = $u['serial_no'] ?: ($u['archived_serial_no'] ?? null);
        if (!empty($u['imei'])) $parts[] = 'IMEI ' . $u['imei'];
        if (!empty($u['imei2'])) $parts[] = 'IMEI 2 ' . $u['imei2'];
        if (!empty($u['serial_no'])) $parts[] = 'SN ' . $u['serial_no'];
        return $parts ? implode(' · ', $parts) : 'No IMEI/serial recorded';
    }

    /** @return array<int, array{product_id:int,label:string}> */
    private static function unitMeta(array $unitIds): array
    {
        $unitIds = array_values(array_unique(array_map('intval', $unitIds)));
        $meta = [];
        foreach (array_chunk($unitIds, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $rows = Database::query("SELECT * FROM inventory_units WHERE id IN ({$marks})", $chunk)->fetchAll();
            foreach ($rows as $r) $meta[(int)$r['id']] = ['product_id' => (int)$r['product_id'], 'label' => self::identifierLabel($r)];
        }
        return $meta;
    }

    /** @return array<int, string> */
    private static function unitLabels(array $unitIds): array
    {
        return array_map(static fn(array $m): string => $m['label'], self::unitMeta($unitIds));
    }

    public static function displayModel(array $row): string
    {
        $base = (($row['product_type'] ?? '') === 'accessory')
            ? trim((string)($row['product_name'] ?? 'Accessory'))
            : trim((string)($row['model_name'] ?? 'Device'));
        if ($base === '') $base = (($row['product_type'] ?? '') === 'accessory') ? 'Accessory' : 'Device';

        $specs = [];
        foreach (['ram', 'storage', 'connectivity', 'color'] as $field) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value !== '' && !in_array($value, $specs, true)) $specs[] = $value;
        }
        return $specs ? $base . ' · ' . implode(' · ', $specs) : $base;
    }

    public static function brandName(array $row): string
    {
        $brand = trim((string)($row['brand_name'] ?? ''));
        return $brand !== '' ? $brand : 'Accessories';
    }

    private static function validDate(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return '';
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        return checkdate($month, $day, $year) ? $value : '';
    }

    private static function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        $filtered = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $filtered === false ? null : (int)$filtered;
    }

    private static function branchExists(array $branches, int $branchId): bool
    {
        foreach ($branches as $branch) {
            if ((int)$branch['id'] === $branchId) return true;
        }
        return false;
    }
}
