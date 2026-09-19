<?php
require __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthenticated']);
    exit;
}

$role = Auth::user()['role'] ?? '';
if (!in_array($role, ['owner', 'branch_manager', 'cashier'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Your account does not have POS access.']);
    exit;
}

$requestedBranch = filter_input(INPUT_GET, 'branch_id', FILTER_VALIDATE_INT) ?: 0;
$branchId = Auth::isOwner() ? $requestedBranch : (Auth::branchId() ?: 0);
if (!$branchId) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Select a branch before searching products.']);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') {
    echo json_encode(['ok' => true, 'items' => []]);
    exit;
}

try {
    ensure_pos_schema_dependencies();
    $like = '%' . $q . '%';
    $items = [];

    // Serialized brand-new devices: one result per physical unit so the cashier sells an exact IMEI / Serial Number.
    $deviceRows = Database::query(
        "SELECT iu.id unit_id, iu.product_id, iu.imei, iu.serial_no, iu.acquisition_cost,
                COALESCE(bpp.selling_price,p.selling_price) sale_price,
                p.product_type,p.ram,p.storage,p.connectivity,p.color,
                br.name brand_name,pm.name model_name
         FROM inventory_units iu
         JOIN products p ON p.id=iu.product_id AND p.is_active=1
         LEFT JOIN branch_product_prices bpp ON bpp.product_id=p.id AND bpp.branch_id=iu.branch_id
         LEFT JOIN brands br ON br.id=p.brand_id
         LEFT JOIN product_models pm ON pm.id=p.model_id
         WHERE iu.branch_id=? AND iu.status='available' AND iu.condition_type='brand_new'
           AND p.product_type IN ('phone','tablet')
           AND (
                COALESCE(iu.imei,'') LIKE ? OR COALESCE(iu.serial_no,'') LIKE ? OR
                COALESCE(br.name,'') LIKE ? OR COALESCE(pm.name,'') LIKE ? OR
                CONCAT_WS(' ',COALESCE(br.name,''),COALESCE(pm.name,''),COALESCE(p.ram,''),COALESCE(p.storage,''),COALESCE(p.connectivity,''),COALESCE(p.color,'')) LIKE ?
           )
         ORDER BY CASE WHEN iu.imei=? OR iu.serial_no=? THEN 0 ELSE 1 END, br.name, pm.name, iu.id
         LIMIT 20",
        [$branchId,$like,$like,$like,$like,$like,$q,$q]
    )->fetchAll();

    foreach ($deviceRows as $row) {
        $identifier = trim((string)($row['serial_no'] ?: $row['imei'] ?: ''));
        $identifierType = $row['serial_no'] ? 'Serial Number' : 'IMEI';
        $items[] = [
            'key' => 'unit:' . (int)$row['unit_id'],
            'kind' => 'device',
            'product_id' => (int)$row['product_id'],
            'unit_id' => (int)$row['unit_id'],
            'name' => malbcoff_product_name($row),
            'specs' => malbcoff_product_specs($row),
            'identifier' => $identifier,
            'identifier_type' => $identifierType,
            'price' => (float)$row['sale_price'],
            'available_qty' => 1,
        ];
    }

    // Accessories are quantity-based and can be added to the cart from the branch balance.
    $accessoryRows = Database::query(
        "SELECT p.id product_id,p.product_name,p.barcode,p.cost_price,COALESCE(bpp.selling_price,p.selling_price) selling_price,c.name category_name,ib.quantity
         FROM products p
         JOIN inventory_balances ib ON ib.product_id=p.id AND ib.branch_id=? AND ib.quantity>0
         LEFT JOIN branch_product_prices bpp ON bpp.product_id=p.id AND bpp.branch_id=ib.branch_id
         LEFT JOIN categories c ON c.id=p.category_id
         WHERE p.is_active=1 AND p.product_type='accessory'
           AND (COALESCE(p.product_name,'') LIKE ? OR COALESCE(p.barcode,'') LIKE ? OR COALESCE(c.name,'') LIKE ?)
         ORDER BY CASE WHEN p.barcode=? THEN 0 ELSE 1 END, p.product_name
         LIMIT 12",
        [$branchId,$like,$like,$like,$q]
    )->fetchAll();

    foreach ($accessoryRows as $row) {
        $items[] = [
            'key' => 'product:' . (int)$row['product_id'],
            'kind' => 'accessory',
            'product_id' => (int)$row['product_id'],
            'unit_id' => null,
            'name' => trim((string)($row['product_name'] ?: 'Accessory')),
            'specs' => trim((string)($row['category_name'] ?: 'Accessory')),
            'identifier' => trim((string)($row['barcode'] ?: '')),
            'identifier_type' => 'Barcode',
            'price' => (float)$row['selling_price'],
            'available_qty' => (int)$row['quantity'],
        ];
    }

    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'POS search is unavailable. Run the required POS and P2-004 pricing migrations first.']);
}

function ensure_pos_schema_dependencies(): void
{
    if (!branch_pricing_ready()) throw new RuntimeException('P2-004 branch pricing migration is missing.');
    $condition = Database::query("SHOW COLUMNS FROM inventory_units LIKE 'condition_type'")->fetch();
    $unitCost = Database::query("SHOW COLUMNS FROM inventory_units LIKE 'acquisition_cost'")->fetch();
    if (!$condition || !$unitCost) {
        throw new RuntimeException('Required Phase 1 inventory migrations are missing.');
    }
}
