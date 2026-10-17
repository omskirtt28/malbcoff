<?php
// Patch 8: "Last: ₱120" chips on the Set Prices screen.
// For each product, the per-piece price THIS branch last sold it at (completed, not voided, price typed at checkout).
// Branch comes from the logged-in user only — never from the request — so one branch can never see another branch's prices.
require __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'code' => 'auth', 'message' => 'Please sign in again.']);
    exit;
}
if (!pos_role_allowed()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Your account does not have POS access.']);
    exit;
}

$branchId = Auth::branchId() ?: 0;
$ids = [];
foreach (explode(',', (string)($_GET['product_ids'] ?? '')) as $raw) {
    $id = filter_var(trim($raw), FILTER_VALIDATE_INT);
    if ($id && $id > 0) $ids[$id] = $id;
    if (count($ids) >= 100) break;
}
if (!$branchId || !$ids) {
    echo json_encode(['ok' => true, 'prices' => (object)[]]);
    exit;
}

try {
    // Old sales (before Patch 8) have no price_entry_mode: their unit_price was a placeholder, so they are not "history".
    $hasMode = (bool)Database::query("SHOW COLUMNS FROM sale_items LIKE 'price_entry_mode'")->fetch();
    if (!$hasMode) {
        echo json_encode(['ok' => true, 'prices' => (object)[]]);
        exit;
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $rows = Database::query(
        "SELECT si.product_id, si.unit_price
         FROM sale_items si
         JOIN (SELECT MAX(si2.id) id
               FROM sale_items si2 JOIN sales s2 ON s2.id=si2.sale_id
               WHERE s2.branch_id=? AND s2.status<>'voided' AND si2.price_entry_mode IS NOT NULL
                 AND si2.product_id IN ($marks) AND " . inventory_active_sale_item_sql('si2') . "
               GROUP BY si2.product_id) latest ON latest.id=si.id",
        array_merge([$branchId], array_values($ids))
    )->fetchAll();
    $prices = [];
    foreach ($rows as $row) $prices[(string)(int)$row['product_id']] = number_format((float)$row['unit_price'], 2, '.', '');
    echo json_encode(['ok' => true, 'prices' => (object)$prices], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    Security::reportException($e, 'pos_last_prices');
    echo json_encode(['ok' => false, 'message' => 'Last prices are not available right now.']);
}
