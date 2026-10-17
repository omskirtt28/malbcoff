<?php
require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../core/Idempotency.php';

// Two ways in: the classic form post (redirect + flash, unchanged) and the safe-retry fetch (JSON + client key).
$wantsJson = Idempotency::wantsJson();

if (!Auth::check()) {
    if ($wantsJson) Idempotency::send(401, ['ok' => false, 'code' => 'auth', 'error' => 'Please sign in again.']);
    redirect(app_url('login'));
}
if (!pos_role_allowed()) {
    if ($wantsJson) Idempotency::send(403, ['ok' => false, 'error' => 'Your account does not have permission to process sales.']);
    flash('error', 'Your account does not have permission to process sales.');
    redirect(app_url('dashboard'));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(app_url('pos'));
if (!Csrf::verify($_POST['_csrf'] ?? null)) {
    if ($wantsJson) Idempotency::csrfFailure();
    flash('error', 'Your session expired. Please review the cart and submit the sale again.');
    redirect(app_url('pos'));
}

// Same key again (double tap, lost response, retry) → return the original sale; never a second one.
$idemKey = Idempotency::key($_POST);
$idemUser = (int)(Auth::user()['id'] ?? 0);
$idemFp = Idempotency::fingerprint($_POST);
Idempotency::replayJsonAndExit($idemKey, 'sale', $idemUser, $idemFp);

// Patch 6c-2: a sale queued on the cashier's device is sent by the outbox (header X-Outbox-Send) with the SAME key as its
// client id. Everything below is validated again with the current session; the queued payload is never trusted.
$queuedSend = $wantsJson && (string)($_SERVER['HTTP_X_OUTBOX_SEND'] ?? '') === '1';
$clientId = null;
$rawClientId = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? ($_POST['client_key'] ?? null);
if (is_string($rawClientId) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim($rawClientId))) {
    $clientId = strtolower(trim($rawClientId));
}
$offlineColumns = false;
try { $offlineColumns = sales_has_offline_columns(); } catch (Throwable $e) { $offlineColumns = false; }
// Second safety net next to idempotency_keys (which may be pruned): sales.client_id is UNIQUE.
if ($clientId !== null && $offlineColumns) {
    try {
        $existingSale = Database::query(
            'SELECT s.id,s.sale_no,s.total,s.payment_method,s.change_due,s.created_by,s.is_offline,s.offline_receipt_no,b.name branch_name
             FROM sales s JOIN branches b ON b.id=s.branch_id WHERE s.client_id=? LIMIT 1',
            [$clientId]
        )->fetch();
    } catch (Throwable $e) {
        $existingSale = null;
    }
    if ($existingSale) sale_replay_existing($existingSale, $idemUser);
}

$requestedBranch = filter_var($_POST['branch_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$branchId = Auth::branchId() ?: 0;
$returnUrl = app_url('pos');

$paymentMethod = (string)($_POST['payment_method'] ?? '');
$allowedPayments = ['cash','gcash','maya','card','bank_transfer'];
if (!in_array($paymentMethod, $allowedPayments, true)) {
    sale_fail('Select a valid payment method.', $returnUrl);
}

$paymentReference = trim((string)($_POST['payment_reference'] ?? ''));
$paymentReference = preg_replace('/\s+/', ' ', $paymentReference) ?? $paymentReference;
if (mb_strlen($paymentReference) > 120) $paymentReference = mb_substr($paymentReference, 0, 120);

$cart = json_decode((string)($_POST['cart_json'] ?? ''), true);
if (!$branchId || !is_array($cart) || !$cart) {
    sale_fail(!$branchId ? 'Select the branch that is processing this sale.' : 'Your cart is empty.', $returnUrl);
}
if (count($cart) > 100) {
    sale_fail('This sale has too many cart lines. Split it into smaller transactions.', $returnUrl);
}

// Time of the sale. Online: the server's own clock, exactly as before. Queued on a device: the time the cashier tapped
// Confirm on the device — accepted only when plausible (not in the future, not older than 7 days), otherwise the server
// time is used. The device time as reported and the server time are both stored and shown to the owner.
$nowTs = time();
$saleTs = $nowTs;
$deviceTimeSql = null;
$offlineReceipt = null;
if ($queuedSend) {
    $deviceMs = filter_var($_POST['offline_device_time'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($deviceMs !== false && $deviceMs > 0) {
        $deviceTs = (int)floor($deviceMs / 1000);
        if ($deviceTs > 946684800 && $deviceTs <= $nowTs + 86400) $deviceTimeSql = date('Y-m-d H:i:s', $deviceTs);
        if ($deviceTs >= $nowTs - 7 * 86400 && $deviceTs <= $nowTs + 300) $saleTs = min($deviceTs, $nowTs);
    }
    $receiptRaw = trim((string)($_POST['offline_receipt_no'] ?? ''));
    if (preg_match('/^OFFLINE-[A-Za-z0-9_-]{1,50}$/', $receiptRaw)) $offlineReceipt = $receiptRaw;
}
$saleAt = date('Y-m-d H:i:s', $saleTs);
$retryHint = $queuedSend ? '' : ' Please retry the sale.';

try {
    ensure_pos_sale_schema();
    $pdo = Database::connection();
    $pdo->beginTransaction();
    if ($idemKey !== null && !Idempotency::claim($idemKey, 'sale', $idemUser, $idemFp)) {
        $pdo->rollBack();
        Idempotency::replayAfterLostClaim($idemKey, 'sale', $idemUser, $idemFp);
    }

    $branch = Database::query('SELECT id,name,code FROM branches WHERE id=? AND is_active=1 LIMIT 1 FOR UPDATE', [$branchId])->fetch();
    if (!$branch) throw new RuntimeException('The selected branch is not available.');

    $resolved = [];
    $subtotalCents = 0; // whole cents: the server adds up the lines itself, the browser total is never read
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
                "SELECT iu.id unit_id,iu.product_id,iu.branch_id,iu.imei,iu.imei2,iu.serial_no,iu.acquisition_cost,
                        iu.status,iu.condition_type,p.product_type,p.ram,p.storage,p.connectivity,p.color,
                        br.name brand_name,pm.name model_name
                 FROM inventory_units iu
                 JOIN products p ON p.id=iu.product_id
                 LEFT JOIN brands br ON br.id=p.brand_id
                 LEFT JOIN product_models pm ON pm.id=p.model_id
                 WHERE iu.id=? AND iu.product_id=? LIMIT 1 FOR UPDATE",
                [$unitId,$productId]
            )->fetch();

            if (!$row) throw new RuntimeException('A device in the cart was not found. It may have been removed.');
            $deviceLabel = malbcoff_product_name($row) . (($row['serial_no'] ?: $row['imei']) ? ' (' . ($row['serial_no'] ?: $row['imei']) . ')' : '');
            if ((int)$row['branch_id'] !== $branchId) throw new RuntimeException($deviceLabel . ' is no longer in this branch. It may have been transferred.');
            if ($row['status'] !== 'available') {
                throw new RuntimeException($deviceLabel . match ((string)$row['status']) {
                    'sold' => ' was already sold.',
                    'transferred' => ' was transferred to another branch.',
                    default => ' is no longer available.',
                });
            }
            if (($row['condition_type'] ?? '') !== 'brand_new') throw new RuntimeException('Only brand-new units can be sold through this POS workflow.');
            if (!in_array($row['product_type'], ['phone','tablet'], true)) throw new RuntimeException('Invalid serialized item type.');

            // Patch 8: the price is what the cashier typed at checkout (never the catalog/placeholder price).
            [$priceMode, $unitCents, $lineCents] = checkout_line_price($line, 1, malbcoff_product_name($row));
            $unitPrice = $unitCents / 100;
            $unitCost = max(0, (float)$row['acquisition_cost']);
            $identifier = (string)($row['serial_no'] ?: $row['imei'] ?: '');
            if (empty($row['serial_no']) && !empty($row['imei2'])) {
                $identifier = 'IMEI 1: ' . $identifier . ' | IMEI 2: ' . (string)$row['imei2'];
            }
            $lineTotal = $lineCents / 100;
            $subtotalCents += $lineCents;

            $resolved[] = [
                'kind' => 'device', 'product_id' => $productId, 'unit_id' => $unitId, 'quantity' => 1,
                'name' => malbcoff_product_name($row), 'specs' => malbcoff_product_specs($row), 'identifier' => $identifier,
                'unit_cost' => $unitCost, 'unit_price' => $unitPrice, 'line_total' => $lineTotal, 'price_mode' => $priceMode,
            ];
            continue;
        }

        if ($kind === 'accessory') {
            $qty = max(1, min(999, (int)($line['quantity'] ?? 1)));
            if (isset($seenProducts[$productId])) throw new RuntimeException('An accessory appears more than once in the cart.');
            $seenProducts[$productId] = true;

            $row = Database::query(
                "SELECT p.id product_id,p.product_type,p.product_name,p.cost_price,p.barcode,c.name category_name,
                        ib.id balance_id,ib.quantity available_qty
                 FROM products p
                 JOIN inventory_balances ib ON ib.product_id=p.id AND ib.branch_id=?
                 LEFT JOIN categories c ON c.id=p.category_id
                 WHERE p.id=? AND p.is_active=1 AND p.product_type='accessory' LIMIT 1 FOR UPDATE",
                [$branchId,$productId]
            )->fetch();

            if (!$row) throw new RuntimeException('An accessory in the cart is no longer available in this branch. It may have been removed.');
            if ((int)$row['available_qty'] < $qty) {
                throw new RuntimeException(trim((string)($row['product_name'] ?: 'Accessory')) . ' does not have enough stock (' . (int)$row['available_qty'] . ' left, ' . $qty . ' needed).');
            }
            [$priceMode, $unitCents, $lineCents] = checkout_line_price($line, $qty, trim((string)($row['product_name'] ?: 'Accessory')));
            $unitPrice = $unitCents / 100;
            $unitCost = max(0, (float)$row['cost_price']);
            $lineTotal = $lineCents / 100;
            $subtotalCents += $lineCents;

            $resolved[] = [
                'kind' => 'accessory', 'product_id' => $productId, 'unit_id' => null, 'quantity' => $qty,
                'name' => trim((string)($row['product_name'] ?: 'Accessory')), 'specs' => trim((string)($row['category_name'] ?: 'Accessory')),
                'identifier' => trim((string)($row['barcode'] ?: '')), 'balance_id' => (int)$row['balance_id'],
                'unit_cost' => $unitCost, 'unit_price' => $unitPrice, 'line_total' => $lineTotal, 'price_mode' => $priceMode,
            ];
            continue;
        }

        throw new RuntimeException('A cart item has an unsupported type.');
    }

    $subtotal = $subtotalCents / 100;
    $total = $subtotal; // Discounts/promos are intentionally deferred to a later POS patch.
    if ($total <= 0) throw new RuntimeException('The sale total must be greater than zero.');

    $amountReceived = null;
    $changeDue = 0.0;
    if ($paymentMethod === 'cash') {
        $amountReceived = round(max(0, (float)($_POST['amount_received'] ?? 0)), 2);
        if ((int)round($amountReceived * 100) < $subtotalCents) throw new RuntimeException('Cash received is less than the sale total.');
        $changeDue = round($amountReceived - $total, 2);
    }

    $saleNo = generate_sale_reference((string)$branch['code']);
    $saleColumns = ['sale_no','branch_id','subtotal','total','payment_method','payment_reference','amount_received','change_due','status','created_by'];
    $saleValues = [$saleNo,$branchId,$subtotal,$total,$paymentMethod,$paymentReference ?: null,$amountReceived,$changeDue,'completed',(int)Auth::user()['id']];
    if ($queuedSend) { // the sale belongs to the day it was made on the device, so every report counts it on that day
        $saleColumns[] = 'created_at';
        $saleValues[] = $saleAt;
    }
    if ($offlineColumns) {
        $saleColumns = array_merge($saleColumns, ['client_id','is_offline','device_time','received_at','offline_receipt_no']);
        $saleValues = array_merge($saleValues, [$clientId, $queuedSend ? 1 : 0, $queuedSend ? $deviceTimeSql : null, $queuedSend ? date('Y-m-d H:i:s', $nowTs) : null, $queuedSend ? $offlineReceipt : null]);
    }
    Database::query(
        'INSERT INTO sales (' . implode(',', $saleColumns) . ') VALUES (' . implode(',', array_fill(0, count($saleColumns), '?')) . ')',
        $saleValues
    );
    $saleId = (int)$pdo->lastInsertId();

    foreach ($resolved as $item) {
        if ($item['kind'] === 'device') {
            $updated = Database::query("UPDATE inventory_units SET status='sold' WHERE id=? AND branch_id=? AND status='available'", [$item['unit_id'],$branchId]);
            if ($updated->rowCount() !== 1) throw new RuntimeException($item['name'] . ' was sold or changed by another user.' . $retryHint);
        } else {
            $updated = Database::query('UPDATE inventory_balances SET quantity=quantity-? WHERE id=? AND quantity>=?', [$item['quantity'],$item['balance_id'],$item['quantity']]);
            if ($updated->rowCount() !== 1) throw new RuntimeException($item['name'] . ' no longer has enough stock.' . $retryHint);
        }

        // price_entry_mode exists after migration P3_003; before it the sale is still saved with the typed prices.
        $modeColumn = sale_items_has_price_mode();
        $itemColumns = ['sale_id','product_id','unit_id','item_name_snapshot','specs_snapshot','identifier_snapshot','quantity','unit_cost','unit_price','line_total'];
        $itemValues = [$saleId,$item['product_id'],$item['unit_id'],$item['name'],$item['specs'],$item['identifier'] ?: null,$item['quantity'],$item['unit_cost'],number_format($item['unit_price'], 2, '.', ''),number_format($item['line_total'], 2, '.', '')];
        if ($modeColumn) { $itemColumns[] = 'price_entry_mode'; $itemValues[] = $item['price_mode']; }
        if ($queuedSend) { $itemColumns[] = 'created_at'; $itemValues[] = $saleAt; }
        Database::query(
            'INSERT INTO sale_items (' . implode(',', $itemColumns) . ') VALUES (' . implode(',', array_fill(0, count($itemColumns), '?')) . ')',
            $itemValues
        );

        $moveColumns = ['product_id','unit_id','branch_id','movement_type','quantity','reference_no','notes','unit_cost','unit_selling_price','created_by'];
        $moveValues = [$item['product_id'],$item['unit_id'],$branchId,'sale',-$item['quantity'],$saleNo,$queuedSend ? 'POS sale completed (saved offline, sent later)' : 'POS sale completed',$item['unit_cost'],$item['unit_price'],(int)Auth::user()['id']];
        if ($queuedSend) { $moveColumns[] = 'created_at'; $moveValues[] = $saleAt; } // Stock Movement shows the same day as Sales Records
        Database::query(
            'INSERT INTO stock_movements (' . implode(',', $moveColumns) . ') VALUES (' . implode(',', array_fill(0, count($moveColumns), '?')) . ')',
            $moveValues
        );
    }

    $saleResult = [
        'ok' => true, 'success' => true, 'sale_id' => $saleId, 'sale_no' => $saleNo, 'total' => $total,
        'payment' => payment_method_label($paymentMethod), 'change' => $changeDue, 'branch' => $branch['name'],
    ];
    if ($queuedSend) $saleResult += ['offline' => true, 'receipt_no' => $offlineReceipt];
    if ($idemKey !== null) Idempotency::complete($idemKey, $saleResult, $saleNo);
    $pdo->commit();
    Security::audit('sale.completed', 'sale', $saleId, ['sale_no' => $saleNo, 'branch_id' => $branchId, 'total' => $total, 'payment_method' => $paymentMethod] + ($queuedSend ? ['offline' => true, 'device_time' => $deviceTimeSql, 'receipt_no' => $offlineReceipt] : []));
    if ($wantsJson) Idempotency::send(200, $saleResult);
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
    if ($e instanceof PDOException && (string)$e->getCode() === '23000' && $clientId !== null && $offlineColumns && str_contains($e->getMessage(), 'uq_sales_client_id')) {
        // Another request with the same client id won the race: answer with that sale ("already processed").
        $existingSale = Database::query(
            'SELECT s.id,s.sale_no,s.total,s.payment_method,s.change_due,s.created_by,s.is_offline,s.offline_receipt_no,b.name branch_name
             FROM sales s JOIN branches b ON b.id=s.branch_id WHERE s.client_id=? LIMIT 1',
            [$clientId]
        )->fetch();
        if ($existingSale) sale_replay_existing($existingSale, $idemUser);
    }
    $saleError = safe_exception_message($e, 'Unable to complete the sale right now. Please try again.');
    if ($wantsJson) {
        // 422 = something the cashier can fix (stock, cash…); 500 = our side → the client offers a safe retry.
        Idempotency::send(($e instanceof RuntimeException && !($e instanceof PDOException)) ? 422 : 500, ['ok' => false, 'error' => $saleError]);
    }
    flash('error', $saleError);
    redirect($returnUrl);
}

/** Business failure: JSON for the safe-retry client, flash + redirect for the classic form. */
function sale_fail(string $message, string $returnUrl, int $status = 422): never
{
    if (Idempotency::wantsJson()) Idempotency::send($status, ['ok' => false, 'error' => $message]);
    flash('error', $message);
    redirect($returnUrl);
}

/**
 * Parse a typed price such as "1,250.5" into whole cents. Null when it is not a plain amount with at most 2 decimals.
 */
function parse_price_cents(mixed $raw): ?int
{
    if (!is_string($raw) && !is_int($raw) && !is_float($raw)) return null;
    $text = str_replace([',', ' ', '₱'], '', trim((string)$raw));
    if (!preg_match('/^(\d{1,8})(?:\.(\d{1,2}))?$/', $text, $m)) return null;
    return (int)$m[1] * 100 + (isset($m[2]) ? (int)str_pad($m[2], 2, '0') : 0);
}

/**
 * Patch 8 — the price of one cart line, from what the cashier typed on the Set Prices screen.
 * price_mode 'piece': `price` is per piece, line total = price × quantity.
 * price_mode 'total': `price` is the total for all pieces (source of truth); the per-piece price is derived (rounded).
 * Returns [mode, unitCents, lineCents]. Empty, invalid or 0 prices are refused — there is no fallback price.
 */
function checkout_line_price(array $line, int $qty, string $name): array
{
    $mode = (string)($line['price_mode'] ?? '');
    if (!in_array($mode, ['piece', 'total'], true) || !isset($line['price']) || trim((string)$line['price']) === '') {
        throw new RuntimeException('Enter a price for ' . $name . '.');
    }
    $cents = parse_price_cents($line['price']);
    if ($cents === null) throw new RuntimeException('The price for ' . $name . ' is not valid. Use numbers only, with up to 2 decimals.');
    if ($cents <= 0) throw new RuntimeException('Enter a price above 0 for ' . $name . '.');
    $qty = max(1, $qty);
    if ($mode === 'piece') {
        $unit = $cents;
        $lineCents = $unit * $qty;
    } else {
        $lineCents = $cents;
        $unit = intdiv(2 * $lineCents + $qty, 2 * $qty); // rounded to the nearest cent
    }
    if ($lineCents > 9999999999) throw new RuntimeException('The price for ' . $name . ' is too high.');
    return [$mode, $unit, $lineCents];
}

/** Patch 6c-2: migration P3_005 imported (client id, offline flag, device time, received time, temporary receipt on `sales`). */
function sales_has_offline_columns(): bool
{
    static $has = null;
    if ($has === null) {
        $has = (int)Database::query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales'
             AND COLUMN_NAME IN ('client_id','is_offline','device_time','received_at','offline_receipt_no')"
        )->fetchColumn() === 5;
    }
    return $has;
}

/**
 * The same client id already has a sale: answer with it ("already processed") instead of creating another one.
 * A client id that belongs to a different user is never replayed.
 */
function sale_replay_existing(array $sale, int $userId): never
{
    if ((int)$sale['created_by'] !== $userId) {
        Idempotency::send(409, ['ok' => false, 'success' => false, 'error' => 'This request was already used for something different. Refresh the page and try again.', 'code' => 'key_conflict']);
    }
    Idempotency::send(200, [
        'ok' => true, 'success' => true, 'sale_id' => (int)$sale['id'], 'sale_no' => $sale['sale_no'], 'total' => (float)$sale['total'],
        'payment' => payment_method_label((string)$sale['payment_method']), 'change' => (float)$sale['change_due'], 'branch' => $sale['branch_name'],
        'offline' => (int)$sale['is_offline'] === 1, 'receipt_no' => $sale['offline_receipt_no'], 'replayed' => true,
    ]);
}

function sale_items_has_price_mode(): bool
{
    static $has = null;
    if ($has === null) $has = (bool)Database::query("SHOW COLUMNS FROM sale_items LIKE 'price_entry_mode'")->fetch();
    return $has;
}

function ensure_pos_sale_schema(): void
{
    if (!branch_pricing_ready()) throw new RuntimeException('POS pricing setup is incomplete. Contact the system administrator.');
    $sales = Database::query("SHOW TABLES LIKE 'sales'")->fetchColumn();
    $saleItems = Database::query("SHOW TABLES LIKE 'sale_items'")->fetchColumn();
    $movementCost = Database::query("SHOW COLUMNS FROM stock_movements LIKE 'unit_cost'")->fetch();
    $condition = Database::query("SHOW COLUMNS FROM inventory_units LIKE 'condition_type'")->fetch();
    if (!$sales || !$saleItems || !$movementCost || !$condition) {
        throw new RuntimeException('POS database setup is incomplete. Contact the system administrator.');
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
