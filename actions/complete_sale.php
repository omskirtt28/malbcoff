<?php
require __DIR__ . '/../bootstrap.php';

if (!Auth::check()) redirect('../login.php');
if (!pos_role_allowed()) {
    flash('error', 'Your account does not have permission to process sales.');
    redirect('../index.php?page=dashboard');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('../index.php?page=pos');
if (!Csrf::verify($_POST['_csrf'] ?? null)) {
    flash('error', 'Your session expired. Please review the cart and submit the sale again.');
    redirect('../index.php?page=pos');
}

$requestedBranch = filter_var($_POST['branch_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$branchId = Auth::isOwner() ? $requestedBranch : (Auth::branchId() ?: 0);
$returnUrl = '../index.php?page=pos' . (Auth::isOwner() && $branchId ? '&branch=' . $branchId : '');

$paymentMethod = (string)($_POST['payment_method'] ?? '');
$allowedPayments = ['cash','gcash','maya','card','bank_transfer'];
if (!in_array($paymentMethod, $allowedPayments, true)) {
    flash('error', 'Select a valid payment method.');
    redirect($returnUrl);
}

$paymentReference = trim((string)($_POST['payment_reference'] ?? ''));
$paymentReference = preg_replace('/\s+/', ' ', $paymentReference) ?? $paymentReference;
if (mb_strlen($paymentReference) > 120) $paymentReference = mb_substr($paymentReference, 0, 120);

$cart = json_decode((string)($_POST['cart_json'] ?? ''), true);
if (!$branchId || !is_array($cart) || !$cart) {
    flash('error', !$branchId ? 'Select the branch that is processing this sale.' : 'Your cart is empty.');
    redirect($returnUrl);
}
if (count($cart) > 100) {
    flash('error', 'This sale has too many cart lines. Split it into smaller transactions.');
    redirect($returnUrl);
}

try {
    ensure_pos_sale_schema();
    $pdo = Database::connection();
    $pdo->beginTransaction();

    $branch = Database::query('SELECT id,name,code FROM branches WHERE id=? AND is_active=1 LIMIT 1 FOR UPDATE', [$branchId])->fetch();
    if (!$branch) throw new RuntimeException('The selected branch is not available.');

    $resolved = [];
    $subtotal = 0.0;
    $seenUnits = [];
    $seenProducts = [];

    foreach ($cart as $line) {
        $kind = (string)($line['kind'] ?? '');
        $productId = filter_var($line['product_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        if (!$productId) throw new RuntimeException('A cart item is invalid. Remove it and add it again.');

        if ($kind === 'device') {
            $unitId = filter_var($line['unit_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
            if (!$unitId || isset($seenUnits[$unitId])) throw new RuntimeException('A device appears more than once in the cart.');
            $seenUnits[$unitId] = true;

            $row = Database::query(
                "SELECT iu.id unit_id,iu.product_id,iu.branch_id,iu.imei,iu.imei2,iu.serial_no,iu.acquisition_cost,iu.selling_price_snapshot,
                        iu.status,iu.condition_type,p.product_type,COALESCE(bpp.selling_price,p.selling_price) selling_price,p.ram,p.storage,p.connectivity,p.color,
                        br.name brand_name,pm.name model_name
                 FROM inventory_units iu
                 JOIN products p ON p.id=iu.product_id
                 LEFT JOIN branch_product_prices bpp ON bpp.product_id=p.id AND bpp.branch_id=iu.branch_id
                 LEFT JOIN brands br ON br.id=p.brand_id
                 LEFT JOIN product_models pm ON pm.id=p.model_id
                 WHERE iu.id=? AND iu.product_id=? LIMIT 1 FOR UPDATE",
                [$unitId,$productId]
            )->fetch();

            if (!$row || (int)$row['branch_id'] !== $branchId) throw new RuntimeException('A device in the cart is not assigned to this branch.');
            if ($row['status'] !== 'available') throw new RuntimeException(malbcoff_product_name($row) . ' is no longer available.');
            if (($row['condition_type'] ?? '') !== 'brand_new') throw new RuntimeException('Pre-Loved units are not included in the current POS phase.');
            if (!in_array($row['product_type'], ['phone','tablet'], true)) throw new RuntimeException('Invalid serialized item type.');

            $unitPrice = max(0, (float)$row['selling_price']);
            if ($unitPrice < 0) $unitPrice = 0;
            $unitCost = max(0, (float)$row['acquisition_cost']);
            $identifier = (string)($row['serial_no'] ?: $row['imei'] ?: '');
            if (empty($row['serial_no']) && !empty($row['imei2'])) {
                $identifier = 'IMEI 1: ' . $identifier . ' | IMEI 2: ' . (string)$row['imei2'];
            }
            $lineTotal = round($unitPrice, 2);
            $subtotal += $lineTotal;

            $resolved[] = [
                'kind' => 'device', 'product_id' => $productId, 'unit_id' => $unitId, 'quantity' => 1,
                'name' => malbcoff_product_name($row), 'specs' => malbcoff_product_specs($row), 'identifier' => $identifier,
                'unit_cost' => $unitCost, 'unit_price' => $unitPrice, 'line_total' => $lineTotal,
            ];
            continue;
        }

        if ($kind === 'accessory') {
            $qty = max(1, min(999, (int)($line['quantity'] ?? 1)));
            if (isset($seenProducts[$productId])) throw new RuntimeException('An accessory appears more than once in the cart.');
            $seenProducts[$productId] = true;

            $row = Database::query(
                "SELECT p.id product_id,p.product_type,p.product_name,p.cost_price,COALESCE(bpp.selling_price,p.selling_price) selling_price,p.barcode,c.name category_name,
                        ib.id balance_id,ib.quantity available_qty
                 FROM products p
                 JOIN inventory_balances ib ON ib.product_id=p.id AND ib.branch_id=?
                 LEFT JOIN branch_product_prices bpp ON bpp.product_id=p.id AND bpp.branch_id=ib.branch_id
                 LEFT JOIN categories c ON c.id=p.category_id
                 WHERE p.id=? AND p.is_active=1 AND p.product_type='accessory' LIMIT 1 FOR UPDATE",
                [$branchId,$productId]
            )->fetch();

            if (!$row || (int)$row['available_qty'] < $qty) {
                throw new RuntimeException(($row['product_name'] ?? 'Accessory') . ' does not have enough available stock.');
            }
            $unitPrice = max(0, (float)$row['selling_price']);
            $unitCost = max(0, (float)$row['cost_price']);
            $lineTotal = round($unitPrice * $qty, 2);
            $subtotal += $lineTotal;

            $resolved[] = [
                'kind' => 'accessory', 'product_id' => $productId, 'unit_id' => null, 'quantity' => $qty,
                'name' => trim((string)($row['product_name'] ?: 'Accessory')), 'specs' => trim((string)($row['category_name'] ?: 'Accessory')),
                'identifier' => trim((string)($row['barcode'] ?: '')), 'balance_id' => (int)$row['balance_id'],
                'unit_cost' => $unitCost, 'unit_price' => $unitPrice, 'line_total' => $lineTotal,
            ];
            continue;
        }

        throw new RuntimeException('A cart item has an unsupported type.');
    }

    $subtotal = round($subtotal, 2);
    $total = $subtotal; // Discounts/promos are intentionally deferred to a later POS patch.
    if ($total <= 0) throw new RuntimeException('The sale total must be greater than zero.');

    $amountReceived = null;
    $changeDue = 0.0;
    if ($paymentMethod === 'cash') {
        $amountReceived = round(max(0, (float)($_POST['amount_received'] ?? 0)), 2);
        if ($amountReceived < $total) throw new RuntimeException('Cash received is less than the sale total.');
        $changeDue = round($amountReceived - $total, 2);
    }

    $saleNo = generate_sale_reference((string)$branch['code']);
    Database::query(
        'INSERT INTO sales (sale_no,branch_id,subtotal,total,payment_method,payment_reference,amount_received,change_due,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
        [$saleNo,$branchId,$subtotal,$total,$paymentMethod,$paymentReference ?: null,$amountReceived,$changeDue,'completed',(int)Auth::user()['id']]
    );
    $saleId = (int)$pdo->lastInsertId();

    foreach ($resolved as $item) {
        if ($item['kind'] === 'device') {
            $updated = Database::query("UPDATE inventory_units SET status='sold' WHERE id=? AND branch_id=? AND status='available'", [$item['unit_id'],$branchId]);
            if ($updated->rowCount() !== 1) throw new RuntimeException($item['name'] . ' was sold or changed by another user. Please retry the sale.');
        } else {
            $updated = Database::query('UPDATE inventory_balances SET quantity=quantity-? WHERE id=? AND quantity>=?', [$item['quantity'],$item['balance_id'],$item['quantity']]);
            if ($updated->rowCount() !== 1) throw new RuntimeException($item['name'] . ' no longer has enough stock. Please retry the sale.');
        }

        Database::query(
            'INSERT INTO sale_items (sale_id,product_id,unit_id,item_name_snapshot,specs_snapshot,identifier_snapshot,quantity,unit_cost,unit_price,line_total) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$saleId,$item['product_id'],$item['unit_id'],$item['name'],$item['specs'],$item['identifier'] ?: null,$item['quantity'],$item['unit_cost'],$item['unit_price'],$item['line_total']]
        );

        Database::query(
            'INSERT INTO stock_movements (product_id,unit_id,branch_id,movement_type,quantity,reference_no,notes,unit_cost,unit_selling_price,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$item['product_id'],$item['unit_id'],$branchId,'sale',-$item['quantity'],$saleNo,'POS sale completed',$item['unit_cost'],$item['unit_price'],(int)Auth::user()['id']]
        );
    }

    $pdo->commit();
    flash('sale_success', json_encode([
        'sale_no' => $saleNo,
        'total' => $total,
        'payment' => payment_method_label($paymentMethod),
        'change' => $changeDue,
        'branch' => $branch['name'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    redirect($returnUrl);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    flash('error', $e->getMessage());
    redirect($returnUrl);
}

function ensure_pos_sale_schema(): void
{
    if (!branch_pricing_ready()) throw new RuntimeException('Run database/P2_004_pricing_variant_serial_ux.sql before using POS.');
    $sales = Database::query("SHOW TABLES LIKE 'sales'")->fetchColumn();
    $saleItems = Database::query("SHOW TABLES LIKE 'sale_items'")->fetchColumn();
    $movementCost = Database::query("SHOW COLUMNS FROM stock_movements LIKE 'unit_cost'")->fetch();
    $condition = Database::query("SHOW COLUMNS FROM inventory_units LIKE 'condition_type'")->fetch();
    if (!$sales || !$saleItems || !$movementCost || !$condition) {
        throw new RuntimeException('Run database/P2_001_brand_new_pos.sql before using POS.');
    }
}

function generate_sale_reference(string $branchCode): string
{
    $branchCode = preg_replace('/[^A-Z0-9]/i', '', strtoupper($branchCode)) ?: 'BR';
    for ($i=0; $i<10; $i++) {
        $reference = 'SALE-' . date('Ymd') . '-' . $branchCode . '-' . str_pad((string)random_int(1,9999), 4, '0', STR_PAD_LEFT);
        if (!Database::query('SELECT 1 FROM sales WHERE sale_no=? LIMIT 1', [$reference])->fetchColumn()) return $reference;
    }
    return 'SALE-' . date('Ymd-His') . '-' . $branchCode;
}

function payment_method_label(string $method): string
{
    return match($method) {
        'gcash' => 'GCash', 'maya' => 'Maya', 'card' => 'Card', 'bank_transfer' => 'Bank Transfer', default => 'Cash'
    };
}
