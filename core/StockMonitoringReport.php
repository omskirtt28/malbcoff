<?php
final class StockMonitoringReport
{
    public static function load(array $input = []): array
    {
        $role = (string)(Auth::user()['role'] ?? '');
        if (!in_array($role, ['owner', 'branch_manager', 'inventory'], true)) {
            throw new RuntimeException('You do not have access to Stock Monitoring.');
        }

        $isOwner = Auth::isOwner();
        $branches = Database::query('SELECT id,name,code FROM branches WHERE is_active=1 ORDER BY id')->fetchAll();
        $brands = Database::query('SELECT id,name FROM brands WHERE is_active=1 ORDER BY name')->fetchAll();

        $date = self::validDate((string)($input['date'] ?? '')) ?: date('Y-m-d');
        if ($date > date('Y-m-d')) $date = date('Y-m-d');

        $brandId = self::positiveInt($input['brand'] ?? null);
        $search = trim((string)($input['q'] ?? ''));
        if (mb_strlen($search) > 120) $search = mb_substr($search, 0, 120);

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

        $displayBranches = $branches;
        if ($isOwner && $branchId !== null) {
            $displayBranches = array_values(array_filter(
                $branches,
                static fn(array $branch): bool => (int)$branch['id'] === $branchId
            ));
        }

        $startAt = $date . ' 00:00:00';
        $endAt = date('Y-m-d 00:00:00', strtotime($date . ' +1 day'));

        $where = ['sm.created_at < ?'];
        $whereParams = [$endAt];
        if ($branchId !== null) {
            $where[] = 'sm.branch_id = ?';
            $whereParams[] = $branchId;
        }
        if ($brandId !== null) {
            $where[] = 'p.brand_id = ?';
            $whereParams[] = $brandId;
        }
        if ($search !== '') {
            $where[] = "(COALESCE(br.name,'') LIKE ? OR COALESCE(pm.name,'') LIKE ? OR COALESCE(p.product_name,'') LIKE ? OR COALESCE(p.ram,'') LIKE ? OR COALESCE(p.storage,'') LIKE ? OR COALESCE(p.connectivity,'') LIKE ? OR COALESCE(p.color,'') LIKE ? OR COALESCE(p.barcode,'') LIKE ?)";
            $needle = '%' . $search . '%';
            for ($i = 0; $i < 8; $i++) $whereParams[] = $needle;
        }

        $sql = "SELECT
                    p.id product_id,p.product_type,p.product_name,p.ram,p.storage,p.connectivity,p.color,p.barcode,
                    br.name brand_name,pm.name model_name,c.name category_name,
                    COALESCE(SUM(CASE WHEN sm.created_at < ? THEN sm.quantity ELSE 0 END),0) opening_stock,
                    COALESCE(SUM(CASE WHEN sm.created_at >= ? AND sm.created_at < ? AND (
                        (sm.movement_type IN ('stock_in','transfer_in','return') AND sm.quantity > 0)
                        OR (sm.movement_type='adjustment' AND sm.quantity > 0)
                    ) THEN sm.quantity ELSE 0 END),0) in_qty,
                    COALESCE(SUM(CASE WHEN sm.created_at >= ? AND sm.created_at < ? AND (
                        (sm.movement_type IN ('stock_out','transfer_out','defective') AND sm.quantity < 0)
                        OR (sm.movement_type='adjustment' AND sm.quantity < 0)
                    ) THEN ABS(sm.quantity) ELSE 0 END),0) out_qty,
                    COALESCE(SUM(CASE WHEN sm.created_at >= ? AND sm.created_at < ? AND sm.movement_type='sale' THEN ABS(sm.quantity) ELSE 0 END),0) sold_qty,
                    COALESCE(SUM(CASE WHEN sm.created_at < ? THEN sm.quantity ELSE 0 END),0) ending_stock
                FROM stock_movements sm
                JOIN products p ON p.id=sm.product_id
                LEFT JOIN brands br ON br.id=p.brand_id
                LEFT JOIN product_models pm ON pm.id=p.model_id
                LEFT JOIN categories c ON c.id=p.category_id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY p.id,p.product_type,p.product_name,p.ram,p.storage,p.connectivity,p.color,p.barcode,br.name,pm.name,c.name
                HAVING opening_stock<>0 OR in_qty<>0 OR out_qty<>0 OR sold_qty<>0 OR ending_stock<>0
                ORDER BY COALESCE(br.name,'ZZZ Accessories'),COALESCE(pm.name,p.product_name),p.ram,p.storage,p.connectivity,p.color";

        $params = [
            $startAt,
            $startAt, $endAt,
            $startAt, $endAt,
            $startAt, $endAt,
            $endAt,
            ...$whereParams,
        ];
        $rows = Database::query($sql, $params)->fetchAll();

        $groups = [];
        $totals = ['opening' => 0, 'in' => 0, 'out' => 0, 'sold' => 0, 'ending' => 0];
        foreach ($rows as $row) {
            $groupName = self::brandName($row);
            $groups[$groupName][] = $row;
            $totals['opening'] += (int)$row['opening_stock'];
            $totals['in'] += (int)$row['in_qty'];
            $totals['out'] += (int)$row['out_qty'];
            $totals['sold'] += (int)$row['sold_qty'];
            $totals['ending'] += (int)$row['ending_stock'];
        }

        $branchEnding = [];
        $branchTotals = [];
        foreach ($displayBranches as $branch) $branchTotals[(int)$branch['id']] = 0;

        if ($isOwner && $rows) {
            $productIds = array_values(array_unique(array_map(
                static fn(array $row): int => (int)$row['product_id'],
                $rows
            )));
            $marks = implode(',', array_fill(0, count($productIds), '?'));
            $branchWhere = ['sm.created_at < ?', "sm.product_id IN ($marks)"];
            $branchParams = [$endAt, ...$productIds];
            if ($branchId !== null) {
                $branchWhere[] = 'sm.branch_id = ?';
                $branchParams[] = $branchId;
            }
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
                if (array_key_exists($bid, $branchTotals)) $branchTotals[$bid] += $qty;
            }

            // Owner's Overall Total is always the exact sum of the displayed Branch columns.
            $totals['ending'] = array_sum($branchTotals);
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
            'role' => $role,
            'date' => $date,
            'formatted_date' => date('M d, Y', strtotime($date)),
            'brand_id' => $brandId,
            'search' => $search,
            'branch_id' => $branchId,
            'selected_branch_name' => $selectedBranchName,
            'branches' => $branches,
            'display_branches' => $displayBranches,
            'brands' => $brands,
            'rows' => $rows,
            'groups' => $groups,
            'totals' => $totals,
            'branch_ending' => $branchEnding,
            'branch_totals' => $branchTotals,
            'start_at' => $startAt,
            'end_at' => $endAt,
        ];
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
