<?php
require __DIR__ . '/../bootstrap.php';

if (!Auth::check()) redirect('../login.php');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('../index.php?page=products');
if (!Csrf::verify($_POST['_csrf'] ?? null)) {
    flash('error', 'Your session expired. Please try again.');
    redirect('../index.php?page=products');
}

$role = Auth::user()['role'] ?? '';
$canAddEdit = in_array($role, ['owner', 'branch_manager', 'inventory'], true);
$isOwner = Auth::isOwner();
$entity = (string)($_POST['entity'] ?? '');
$action = (string)($_POST['action'] ?? '');
$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: null;
$returnView = (($_POST['return_view'] ?? '') === 'accessories') ? 'accessories' : 'devices';
$returnModel = filter_var($_POST['return_model'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$returnUrl = '../index.php?page=products&view=' . $returnView . ($returnModel ? '&model='.$returnModel.'#variants' : '');

if (!in_array($entity, ['brand','model','category','configuration'], true) || !in_array($action, ['add','edit','archive','restore','delete'], true)) {
    flash('error', 'Invalid Product Master request.');
    redirect('../index.php?page=products');
}
if (in_array($action, ['add','edit'], true) && !$canAddEdit) {
    flash('error', 'Your account has view-only access to Product Master.');
    redirect('../index.php?page=products');
}
if (in_array($action, ['archive','restore','delete'], true) && !$isOwner) {
    flash('error', 'Only the Owner can archive, restore or permanently delete Product Master records.');
    redirect('../index.php?page=products');
}
if ($entity === 'configuration' && $action === 'edit' && !in_array($role, ['owner','branch_manager'], true)) {
    flash('error', 'Only the Owner or Branch Manager can update variant pricing.');
    redirect('../index.php?page=products');
}
if ($entity === 'brand' && $action === 'edit' && !$isOwner) {
    flash('error', 'Only the Owner can rename a global brand. Branch users can add brands and manage models.');
    redirect('../index.php?page=products');
}

try {
    ensure_product_master_schema();
    $pdo = Database::connection();
    $pdo->beginTransaction();

    if ($entity === 'brand') handle_brand($action, $id);
    elseif ($entity === 'model') handle_model($action, $id);
    elseif ($entity === 'category') handle_category($action, $id);
    else handle_configuration($action, $id);

    $pdo->commit();
    flash('success', success_message($entity, $action));
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ((string)$e->getCode() === '23000') flash('error', 'That name already exists in Product Master.');
    else flash('error', 'Unable to update Products. Please verify the required database migrations.');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    flash('error', $e->getMessage());
}
redirect($returnUrl);

function handle_brand(string $action, ?int $id): void {
    if ($action === 'add') {
        $name = clean_master_name($_POST['name'] ?? '', 100);
        if ($name === '') throw new RuntimeException('Brand name is required.');
        $existing = Database::query('SELECT id,is_active FROM brands WHERE LOWER(name)=LOWER(?) LIMIT 1', [$name])->fetch();
        if ($existing) {
            if (!(int)$existing['is_active']) throw new RuntimeException('This brand already exists but is archived. Ask the Owner to restore it.');
            throw new RuntimeException('This brand already exists.');
        }
        Database::query('INSERT INTO brands (name,is_active) VALUES (?,1)', [$name]);
        return;
    }
    $brand = require_brand($id);
    if ($action === 'edit') {
        $name = clean_master_name($_POST['name'] ?? '', 100);
        if ($name === '') throw new RuntimeException('Brand name is required.');
        Database::query('UPDATE brands SET name=? WHERE id=?', [$name,$brand['id']]);
    } elseif ($action === 'archive') {
        Database::query('UPDATE brands SET is_active=0 WHERE id=?', [$brand['id']]);
    } elseif ($action === 'restore') {
        Database::query('UPDATE brands SET is_active=1 WHERE id=?', [$brand['id']]);
    } elseif ($action === 'delete') {
        $models = (int)Database::query('SELECT COUNT(*) FROM product_models WHERE brand_id=?', [$brand['id']])->fetchColumn();
        $products = (int)Database::query('SELECT COUNT(*) FROM products WHERE brand_id=?', [$brand['id']])->fetchColumn();
        if ($models || $products) throw new RuntimeException('This brand is already used. Archive it instead so historical records remain intact.');
        Database::query('DELETE FROM brands WHERE id=?', [$brand['id']]);
    }
}

function handle_model(string $action, ?int $id): void {
    if ($action === 'add') {
        $brandId = filter_var($_POST['brand_id'] ?? null, FILTER_VALIDATE_INT);
        $name = clean_master_name($_POST['name'] ?? '', 150);
        $type = valid_device_type($_POST['device_type'] ?? 'phone');
        if (!$brandId || $name === '') throw new RuntimeException('Brand and model name are required.');
        $brand = Database::query('SELECT id FROM brands WHERE id=? AND is_active=1 LIMIT 1', [$brandId])->fetch();
        if (!$brand) throw new RuntimeException('Please select an active brand.');
        $existing = Database::query('SELECT id,is_active FROM product_models WHERE brand_id=? AND LOWER(name)=LOWER(?) LIMIT 1', [$brandId,$name])->fetch();
        if ($existing) {
            if (!(int)$existing['is_active']) throw new RuntimeException('This model already exists but is archived. Ask the Owner to restore it.');
            throw new RuntimeException('This model already exists under the selected brand.');
        }
        Database::query('INSERT INTO product_models (brand_id,name,device_type,is_active) VALUES (?,?,?,1)', [$brandId,$name,$type]);
        return;
    }

    $model = require_model($id);
    if ($action === 'edit') {
        $brandId = filter_var($_POST['brand_id'] ?? null, FILTER_VALIDATE_INT);
        $name = clean_master_name($_POST['name'] ?? '', 150);
        $type = valid_device_type($_POST['device_type'] ?? 'phone');
        if (!$brandId || $name === '') throw new RuntimeException('Brand and model name are required.');
        $brand = Database::query('SELECT id FROM brands WHERE id=? AND is_active=1 LIMIT 1', [$brandId])->fetch();
        if (!$brand) throw new RuntimeException('Please select an active brand.');
        $used = (int)Database::query('SELECT COUNT(*) FROM products WHERE model_id=?', [$model['id']])->fetchColumn();
        if ($used && ((int)$model['brand_id'] !== (int)$brandId || $model['device_type'] !== $type)) {
            throw new RuntimeException('This model already has inventory/product records. You can rename it, but its Brand and Device Type can no longer be changed.');
        }
        Database::query('UPDATE product_models SET brand_id=?,name=?,device_type=? WHERE id=?', [$brandId,$name,$type,$model['id']]);
    } elseif ($action === 'archive') {
        Database::query('UPDATE product_models SET is_active=0 WHERE id=?', [$model['id']]);
    } elseif ($action === 'restore') {
        $brandActive = Database::query('SELECT is_active FROM brands WHERE id=?', [$model['brand_id']])->fetchColumn();
        if (!(int)$brandActive) throw new RuntimeException('Restore the model\'s brand first.');
        Database::query('UPDATE product_models SET is_active=1 WHERE id=?', [$model['id']]);
    } elseif ($action === 'delete') {
        $used = (int)Database::query('SELECT COUNT(*) FROM products WHERE model_id=?', [$model['id']])->fetchColumn();
        if ($used) throw new RuntimeException('This model is already used by inventory. Archive it instead.');
        Database::query('DELETE FROM product_models WHERE id=?', [$model['id']]);
    }
}

function handle_category(string $action, ?int $id): void {
    if ($action === 'add') {
        $name = clean_master_name($_POST['name'] ?? '', 100);
        if ($name === '') throw new RuntimeException('Category name is required.');
        $existing = Database::query('SELECT id,is_active FROM categories WHERE LOWER(name)=LOWER(?) LIMIT 1', [$name])->fetch();
        if ($existing) {
            if (!(int)$existing['is_active']) throw new RuntimeException('This category already exists but is archived. Ask the Owner to restore it.');
            throw new RuntimeException('This category already exists.');
        }
        Database::query('INSERT INTO categories (name,is_active) VALUES (?,1)', [$name]);
        return;
    }
    $category = Database::query('SELECT id,name,is_active FROM categories WHERE id=? LIMIT 1', [$id])->fetch();
    if (!$category) throw new RuntimeException('Category not found.');
    if ($action === 'edit') {
        $name = clean_master_name($_POST['name'] ?? '', 100);
        if ($name === '') throw new RuntimeException('Category name is required.');
        Database::query('UPDATE categories SET name=? WHERE id=?', [$name,$category['id']]);
    } elseif ($action === 'archive') {
        Database::query('UPDATE categories SET is_active=0 WHERE id=?', [$category['id']]);
    } elseif ($action === 'restore') {
        Database::query('UPDATE categories SET is_active=1 WHERE id=?', [$category['id']]);
    } elseif ($action === 'delete') {
        $used = (int)Database::query('SELECT COUNT(*) FROM products WHERE category_id=?', [$category['id']])->fetchColumn();
        if ($used) throw new RuntimeException('This category is already used by inventory. Archive it instead.');
        Database::query('DELETE FROM categories WHERE id=?', [$category['id']]);
    }
}


function handle_configuration(string $action, ?int $id): void {
    if (!$id) throw new RuntimeException('Variant not found.');
    $config = Database::query("SELECT p.id,p.model_id,p.is_active,p.product_type,p.cost_price,p.selling_price FROM products p WHERE p.id=? AND p.product_type IN ('phone','tablet') LIMIT 1", [$id])->fetch();
    if (!$config) throw new RuntimeException('Variant not found.');

    if ($action === 'edit') {
        $role = Auth::user()['role'] ?? '';
        $userId = (int)(Auth::user()['id'] ?? 0);

        if (Auth::isOwner()) {
            $cost = round(max(0, (float)($_POST['cost_price'] ?? $config['cost_price'] ?? 0)), 2);
            Database::query('UPDATE products SET cost_price=? WHERE id=?', [$cost,$id]);

            $branchPrices = (array)($_POST['branch_prices'] ?? []);
            $branches = Database::query('SELECT id FROM branches WHERE is_active=1 ORDER BY id')->fetchAll();
            foreach ($branches as $branch) {
                $branchId = (int)$branch['id'];
                if (!array_key_exists((string)$branchId, $branchPrices) && !array_key_exists($branchId, $branchPrices)) continue;
                $raw = $branchPrices[$branchId] ?? $branchPrices[(string)$branchId] ?? null;
                $price = round(max(0, (float)$raw), 2);
                if ($price <= 0) throw new RuntimeException('Selling price must be greater than zero for each branch you update.');
                save_branch_selling_price($id, $branchId, $price, $userId ?: null);
            }
        } elseif ($role === 'branch_manager') {
            $branchId = Auth::branchId() ?: 0;
            if (!$branchId) throw new RuntimeException('Your account is not assigned to a branch.');
            $price = round(max(0, (float)($_POST['selling_price'] ?? 0)), 2);
            if ($price <= 0) throw new RuntimeException('Enter a valid selling price.');
            save_branch_selling_price($id, $branchId, $price, $userId ?: null);
        } else {
            throw new RuntimeException('Your account cannot update variant pricing.');
        }
    } elseif ($action === 'archive') {
        $available = (int)Database::query("SELECT COUNT(*) FROM inventory_units WHERE product_id=? AND status='available'", [$id])->fetchColumn();
        if ($available > 0) throw new RuntimeException('This variant still has available stock. Sell, transfer or adjust those units before archiving it.');
        Database::query('UPDATE products SET is_active=0 WHERE id=?', [$id]);
    } elseif ($action === 'restore') {
        $ready = Database::query("SELECT 1 FROM products p JOIN product_models pm ON pm.id=p.model_id JOIN brands b ON b.id=p.brand_id WHERE p.id=? AND pm.is_active=1 AND b.is_active=1 LIMIT 1", [$id])->fetchColumn();
        if (!$ready) throw new RuntimeException('Restore the variant brand/model first.');
        Database::query('UPDATE products SET is_active=1 WHERE id=?', [$id]);
    } elseif ($action === 'delete') {
        $units = (int)Database::query('SELECT COUNT(*) FROM inventory_units WHERE product_id=?', [$id])->fetchColumn();
        $movements = (int)Database::query('SELECT COUNT(*) FROM stock_movements WHERE product_id=?', [$id])->fetchColumn();
        $sales = 0;
        try { $sales = (int)Database::query('SELECT COUNT(*) FROM sale_items WHERE product_id=?', [$id])->fetchColumn(); } catch (Throwable $e) {}
        if ($units || $movements || $sales) throw new RuntimeException('This variant already has inventory or history. Archive it instead.');
        if (branch_pricing_ready()) Database::query('DELETE FROM branch_product_prices WHERE product_id=?', [$id]);
        Database::query('DELETE FROM products WHERE id=?', [$id]);
    } else {
        throw new RuntimeException('Invalid variant action.');
    }
}

function require_brand(?int $id): array {
    if (!$id) throw new RuntimeException('Brand not found.');
    $row = Database::query('SELECT id,name,is_active FROM brands WHERE id=? LIMIT 1', [$id])->fetch();
    if (!$row) throw new RuntimeException('Brand not found.');
    return $row;
}
function require_model(?int $id): array {
    if (!$id) throw new RuntimeException('Model not found.');
    $row = Database::query('SELECT id,brand_id,name,device_type,is_active FROM product_models WHERE id=? LIMIT 1', [$id])->fetch();
    if (!$row) throw new RuntimeException('Model not found.');
    return $row;
}
function clean_master_name(mixed $value, int $max): string {
    $value = trim((string)$value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return mb_substr($value, 0, $max);
}
function valid_device_type(mixed $value): string {
    $value = strtolower(trim((string)$value));
    return in_array($value, ['phone','tablet'], true) ? $value : 'phone';
}
function ensure_product_master_schema(): void {
    if (!Database::query("SHOW COLUMNS FROM product_models LIKE 'device_type'")->fetch()) {
        throw new RuntimeException('P1-006 database migration is required before managing models.');
    }
}
function success_message(string $entity, string $action): string {
    $label = $entity === 'configuration' ? 'Variant' : ucfirst($entity);
    return match ($action) {
        'add' => $label.' added to Product Master.',
        'edit' => $label.' updated.',
        'archive' => $label.' archived. Existing inventory/history was preserved.',
        'restore' => $label.' restored.',
        'delete' => $label.' permanently deleted.',
        default => 'Product Master updated.',
    };
}
