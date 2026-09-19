<?php
require __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Please sign in again.']);
    exit;
}
if (!Auth::isOwner()) {
    http_response_code(403);
    echo json_encode(['error' => 'Only the Owner can manage inventory adjustments.']);
    exit;
}

$productId = filter_input(INPUT_GET, 'product_id', FILTER_VALIDATE_INT) ?: 0;
if ($productId <= 0) {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid variant.']);
    exit;
}

try {
    $product = Database::query(
        "SELECT p.id,p.product_type,p.ram,p.storage,p.connectivity,p.color,
                b.name AS brand_name,pm.name AS model_name
         FROM products p
         LEFT JOIN brands b ON b.id=p.brand_id
         LEFT JOIN product_models pm ON pm.id=p.model_id
         WHERE p.id=? AND p.product_type IN ('phone','tablet') LIMIT 1",
        [$productId]
    )->fetch();
    if (!$product) {
        http_response_code(404);
        echo json_encode(['error' => 'Variant not found.']);
        exit;
    }

    $branches = Database::query(
        "SELECT b.id,b.name,b.code,
                COUNT(iu.id) AS available_count
         FROM branches b
         LEFT JOIN inventory_units iu
           ON iu.branch_id=b.id AND iu.product_id=? AND iu.status='available'
         WHERE b.is_active=1
         GROUP BY b.id,b.name,b.code
         ORDER BY b.id",
        [$productId]
    )->fetchAll();

    $units = Database::query(
        "SELECT iu.id,iu.branch_id,iu.serial_no,iu.imei,iu.created_at
         FROM inventory_units iu
         JOIN branches b ON b.id=iu.branch_id AND b.is_active=1
         WHERE iu.product_id=? AND iu.status='available'
         ORDER BY iu.branch_id,iu.created_at,iu.id",
        [$productId]
    )->fetchAll();

    $unitsByBranch = [];
    foreach ($units as $unit) {
        $identifier = trim((string)($unit['serial_no'] ?: $unit['imei'] ?: ''));
        $unitsByBranch[(int)$unit['branch_id']][] = [
            'id' => (int)$unit['id'],
            'identifier' => $identifier ?: ('Unit #' . (int)$unit['id']),
            'identifier_type' => !empty($unit['serial_no']) ? 'Serial Number' : 'IMEI',
            'received_at' => (string)$unit['created_at'],
        ];
    }

    $total = 0;
    foreach ($branches as &$branch) {
        $branch['id'] = (int)$branch['id'];
        $branch['available_count'] = (int)$branch['available_count'];
        $branch['units'] = $unitsByBranch[$branch['id']] ?? [];
        $total += $branch['available_count'];
    }
    unset($branch);

    echo json_encode([
        'product_id' => $productId,
        'label' => malbcoff_product_name($product),
        'specs' => malbcoff_product_specs($product),
        'total_available' => $total,
        'branches' => $branches,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load this inventory right now.']);
}
