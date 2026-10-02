<?php
require __DIR__ . '/../bootstrap.php';

if (!Auth::check()) redirect(app_url('login'));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(app_url('products'));
if (!Csrf::verify($_POST['_csrf'] ?? null)) {
    flash('error', 'Your session expired. Please try again.');
    redirect(app_url('products'));
}

$role = Auth::user()['role'] ?? '';
$canAddEdit = in_array($role, ['branch_manager', 'inventory'], true);
$isOwner = Auth::isOwner();
$canMergeVariant = $isOwner || (method_exists('Auth','actorIsSystemAdmin') && Auth::actorIsSystemAdmin());
$entity = (string)($_POST['entity'] ?? '');
$action = (string)($_POST['action'] ?? '');
$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: null;
$returnView = (($_POST['return_view'] ?? '') === 'accessories') ? 'accessories' : 'devices';
$returnModel = filter_var($_POST['return_model'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$returnArchive = (($_POST['return_archive'] ?? '') === '1');
$returnUrl = $returnArchive
    ? app_url('archive')
    : app_url('products', array_filter(['view' => $returnView, 'model' => $returnModel ?: null], static fn($v) => $v !== null && $v !== ''), $returnModel ? 'variants' : '');

if (!in_array($entity, ['brand','model','category','configuration'], true) || !in_array($action, ['add','edit','archive','restore','delete','merge_typo'], true)) {
    flash('error', 'Invalid Product Master request.');
    redirect(app_url('products'));
}
if (in_array($action, ['add','edit'], true) && !$canAddEdit) {
    flash('error', 'Your account has view-only access to Product Master.');
    redirect(app_url('products'));
}
if (in_array($action, ['archive','restore','delete'], true) && !$isOwner) {
    flash('error', 'Only the Owner can archive, restore or permanently delete Product Master records.');
    redirect(app_url('products'));
}
if ($action === 'merge_typo' && !$canMergeVariant) {
    flash('error', 'Only the Owner or System Admin can merge spelling-duplicate variants.');
    redirect(app_url('products'));
}
if ($entity === 'configuration' && $action === 'edit' && !in_array($role, ['branch_manager'], true)) {
    flash('error', 'Only the Branch Manager can update active variant pricing.');
    redirect(app_url('products'));
}
if ($entity === 'brand' && $action === 'edit' && $role !== 'branch_manager') {
    flash('error', 'Only the Branch Manager can rename a global brand.');
    redirect(app_url('products'));
}

try {
    ensure_product_master_schema();
    $pdo = Database::connection();
    $pdo->beginTransaction();

    $createdModelId = null;
    if ($entity === 'brand') handle_brand($action, $id);
    elseif ($entity === 'model') $createdModelId = handle_model($action, $id);
    elseif ($entity === 'category') handle_category($action, $id);
    else handle_configuration($action, $id);

    $pdo->commit();
    $auditEntityId = $createdModelId ?: $id;
    Security::audit('catalog.' . $entity . '.' . $action, $entity, $auditEntityId);
    flash('success', success_message($entity, $action));
    if ($entity === 'model' && $action === 'add' && $createdModelId) {
        $returnUrl = app_url('products', ['view' => 'devices', 'model' => (int)$createdModelId, 'setup' => 1], 'variants');
    }
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ((string)$e->getCode() === '23000') flash('error', 'That name already exists in Product Master.');
    else { Security::reportException($e, 'product_master'); flash('error', 'Unable to update Products right now. Please try again.'); }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    flash('error', safe_exception_message($e, 'Unable to update Products right now. Please try again.'));
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

function handle_model(string $action, ?int $id): ?int {
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
        return (int)Database::connection()->lastInsertId();
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
        $available = (int)Database::query(
            "SELECT COUNT(*) FROM inventory_units iu JOIN products p ON p.id=iu.product_id WHERE p.model_id=? AND iu.status='available'",
            [$model['id']]
        )->fetchColumn();
        if ($available > 0) {
            throw new RuntimeException('This model still has available stock. Remove, sell or transfer the remaining units before archiving it.');
        }
        // Keep child variants for history. Archiving the parent only removes the model and its variants from the active catalog.
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
    return null;
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
    $config = Database::query(
        "SELECT p.id,p.brand_id,p.model_id,p.category_id,p.product_name,p.barcode,p.low_stock_threshold,p.is_active,p.product_type,p.ram,p.storage,p.color,p.connectivity,p.cost_price,p.selling_price,
                pm.device_type,b.name AS brand_name
         FROM products p
         JOIN product_models pm ON pm.id=p.model_id
         JOIN brands b ON b.id=p.brand_id
         WHERE p.id=? AND p.product_type IN ('phone','tablet') LIMIT 1",
        [$id]
    )->fetch();
    if (!$config) throw new RuntimeException('Variant not found.');

    if ($action === 'edit') {
        $role = Auth::user()['role'] ?? '';
        $userId = (int)(Auth::user()['id'] ?? 0);
        $unlockRequested = in_array(strtolower(trim((string)($_POST['unlock_specs'] ?? '0'))), ['1','true','yes'], true);
        $canUnlockSpecs = $role === 'branch_manager';

        // Specs are normally locked while operational/historical units depend on this variant.
        // A Branch Manager may explicitly unlock a correction. The correction helper protects
        // sold/transferred history by splitting active stock into a corrected canonical variant
        // when rewriting the current product row would alter protected historical records.
        $lockedUnitCount = (int)Database::query(
            "SELECT COUNT(*) FROM inventory_units WHERE product_id=? AND status IN ('available','reserved','sold','transferred')",
            [$id]
        )->fetchColumn();
        $specsLocked = $lockedUnitCount > 0;

        $shouldProcessSpecs = !$specsLocked || ($unlockRequested && $canUnlockSpecs);
        if ($unlockRequested && !$canUnlockSpecs) {
            throw new RuntimeException('Only the Branch Manager can unlock a locked variant for correction.');
        }

        if ($shouldProcessSpecs) {
            $isApple = strcasecmp(trim((string)$config['brand_name']), 'APPLE') === 0;
            $storage = normalize_variant_capacity($_POST['storage'] ?? '');
            $ram = $isApple ? null : normalize_variant_capacity($_POST['ram'] ?? '');
            $colorResult = variant_color_canonicalize((int)$config['model_id'], $_POST['color'] ?? '', (int)$id);
            if (count($colorResult['suggestions'] ?? []) > 1) {
                throw new RuntimeException('Color is too similar to existing colors: '.implode(', ', $colorResult['suggestions']).'. Please choose the exact existing color.');
            }
            $color = (string)($colorResult['value'] ?? '');
            $connectivity = ($config['product_type'] === 'tablet') ? clean_variant_value($_POST['connectivity'] ?? '', 40, false) : null;

            if ($storage === '') throw new RuntimeException('Storage is required.');
            if (!$isApple && $ram === '') throw new RuntimeException('RAM is required for Android variants.');
            if ($color === '') throw new RuntimeException('Color is required.');
            if ($config['product_type'] === 'tablet' && !in_array($connectivity, ['Wi-Fi','Wi-Fi + Cellular'], true)) {
                throw new RuntimeException('Please select tablet connectivity.');
            }

            $sameSpecs = variant_specs_match_values($config, $ram, $storage, $connectivity, $color);
            if (!$sameSpecs) {
                $duplicate = variant_find_semantic_existing(
                    (string)$config['product_type'],
                    (int)$config['brand_id'],
                    (int)$config['model_id'],
                    $ram,
                    $storage,
                    $connectivity,
                    $color,
                    (int)$id
                );

                if ($duplicate && !(int)$duplicate['is_active']) {
                    throw new RuntimeException('The corrected variant already exists in Archived Products. Ask the Owner to restore it first.');
                }

                if ($specsLocked && $unlockRequested) {
                    $id = apply_locked_variant_correction($config, $duplicate ?: null, $ram, $storage, $connectivity, $color, $userId);
                    $config['id'] = $id;
                } else {
                    if ($duplicate) {
                        throw new RuntimeException('Another active variant already uses the same RAM, storage, color and connectivity.');
                    }
                    Database::query(
                        'UPDATE products SET ram=?,storage=?,color=?,connectivity=? WHERE id=?',
                        [$ram,$storage,$color,$connectivity,$id]
                    );
                }
            }
        }

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
            throw new RuntimeException('Your account cannot update variants.');
        }
    } elseif ($action === 'merge_typo') {
        $targetId = filter_var($_POST['target_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        if (!$targetId || $targetId === (int)$id) throw new RuntimeException('Choose a valid target variant.');
        $target = Database::query(
            "SELECT p.id,p.brand_id,p.model_id,p.product_type,p.ram,p.storage,p.color,p.connectivity,p.cost_price,p.selling_price,p.is_active,
                    pm.name model_name,b.name brand_name
             FROM products p
             JOIN product_models pm ON pm.id=p.model_id
             JOIN brands b ON b.id=p.brand_id
             WHERE p.id=? AND p.product_type IN ('phone','tablet') LIMIT 1",
            [$targetId]
        )->fetch();
        if (!$target || !(int)$target['is_active']) throw new RuntimeException('The target variant is not active.');
        if ((int)$target['brand_id'] !== (int)$config['brand_id'] || (int)$target['model_id'] !== (int)$config['model_id'] || (string)$target['product_type'] !== (string)$config['product_type']) {
            throw new RuntimeException('Only variants of the same model can be merged.');
        }
        foreach (['ram','storage','connectivity'] as $field) {
            if (trim((string)($target[$field] ?? '')) !== trim((string)($config[$field] ?? ''))) {
                throw new RuntimeException('Only variants with the same RAM, storage and connectivity can be merged.');
            }
        }
        if (!variant_colors_are_probable_typo($config['color'] ?? '', $target['color'] ?? '')) {
            throw new RuntimeException('These colors are not close enough to be treated as a spelling duplicate.');
        }
        merge_variant_typo_records((int)$id, $targetId);
        Security::audit('catalog.variant_typo_merged', 'product', $targetId, [
            'source_variant_id' => (int)$id,
            'source_color' => (string)($config['color'] ?? ''),
            'target_color' => (string)($target['color'] ?? ''),
        ]);
    } elseif ($action === 'archive') {
        $available = (int)Database::query("SELECT COUNT(*) FROM inventory_units WHERE product_id=? AND status='available'", [$id])->fetchColumn();
        if ($available > 0) throw new RuntimeException('This variant still has available stock. Sell, transfer or adjust those units before archiving it.');
        Database::query('UPDATE products SET is_active=0 WHERE id=?', [$id]);
    } elseif ($action === 'restore') {
        $ready = Database::query("SELECT 1 FROM products p JOIN product_models pm ON pm.id=p.model_id JOIN brands b ON b.id=p.brand_id WHERE p.id=? AND pm.is_active=1 AND b.is_active=1 LIMIT 1", [$id])->fetchColumn();
        if (!$ready) throw new RuntimeException('Restore the variant brand/model first.');
        Database::query('UPDATE products SET is_active=1 WHERE id=?', [$id]);
    } elseif ($action === 'delete') {
        if ((int)$config['is_active'] === 1) {
            throw new RuntimeException('Archive this variant first before deleting it.');
        }

        $available = (int)Database::query("SELECT COUNT(*) FROM inventory_units WHERE product_id=? AND status IN ('available','reserved')", [$id])->fetchColumn();
        if ($available > 0) {
            throw new RuntimeException('This archived variant still has available or reserved units. Remove, sell or transfer those units first.');
        }

        $units = (int)Database::query('SELECT COUNT(*) FROM inventory_units WHERE product_id=?', [$id])->fetchColumn();
        $movements = (int)Database::query('SELECT COUNT(*) FROM stock_movements WHERE product_id=?', [$id])->fetchColumn();
        $sales = 0;
        try { $sales = (int)Database::query('SELECT COUNT(*) FROM sale_items WHERE product_id=?', [$id])->fetchColumn(); } catch (Throwable $e) {}

        if (!$units && !$movements && !$sales) {
            if (branch_pricing_ready()) Database::query('DELETE FROM branch_product_prices WHERE product_id=?', [$id]);
            Database::query('DELETE FROM products WHERE id=?', [$id]);
        } else {
            $column = Database::query("SHOW COLUMNS FROM products LIKE 'catalog_deleted_at'")->fetch();
            if (!$column) {
                throw new RuntimeException('Archive cleanup is temporarily unavailable. Contact the system administrator.');
            }
            Database::query('UPDATE products SET catalog_deleted_at=NOW(), catalog_deleted_by=? WHERE id=?', [(int)(Auth::user()['id'] ?? 0), $id]);
        }
    } else {
        throw new RuntimeException('Invalid variant action.');
    }
}

function variant_specs_match_values(array $config, ?string $ram, string $storage, ?string $connectivity, string $color): bool {
    $norm = static fn($value): string => mb_strtoupper(trim((string)$value), 'UTF-8');
    return $norm($config['ram'] ?? '') === $norm($ram ?? '')
        && $norm($config['storage'] ?? '') === $norm($storage)
        && $norm($config['connectivity'] ?? '') === $norm($connectivity ?? '')
        && $norm($config['color'] ?? '') === $norm($color);
}

function apply_locked_variant_correction(array $source, ?array $existingTarget, ?string $ram, string $storage, ?string $connectivity, string $color, int $userId): int {
    $sourceId = (int)$source['id'];

    $protectedHistory = (int)Database::query(
        "SELECT
            (SELECT COUNT(*) FROM inventory_units WHERE product_id=? AND status IN ('sold','transferred')) +
            (SELECT COUNT(*) FROM sale_items WHERE product_id=?) +
            (SELECT COUNT(*) FROM inventory_transfers WHERE product_id=?)",
        [$sourceId,$sourceId,$sourceId]
    )->fetchColumn();

    // When there is no protected sold/transfer history and no canonical target already
    // exists, correcting the current row is safe and keeps current unit IDs untouched.
    if ($protectedHistory === 0 && !$existingTarget) {
        Database::query(
            'UPDATE products SET ram=?,storage=?,color=?,connectivity=? WHERE id=?',
            [$ram,$storage,$color,$connectivity,$sourceId]
        );
        Security::audit('catalog.variant_unlocked_corrected', 'product', $sourceId, [
            'mode' => 'in_place',
            'old_specs' => [
                'ram' => $source['ram'] ?? null,
                'storage' => $source['storage'] ?? null,
                'color' => $source['color'] ?? null,
                'connectivity' => $source['connectivity'] ?? null,
            ],
            'new_specs' => compact('ram','storage','color','connectivity'),
        ]);
        return $sourceId;
    }

    $targetId = $existingTarget ? (int)$existingTarget['id'] : 0;
    if ($targetId <= 0) {
        Database::query(
            'INSERT INTO products (product_type,brand_id,model_id,category_id,product_name,ram,storage,connectivity,color,barcode,cost_price,selling_price,low_stock_threshold,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)',
            [
                $source['product_type'],
                $source['brand_id'],
                $source['model_id'],
                $source['category_id'] ?? null,
                $source['product_name'] ?? null,
                $ram,
                $storage,
                $connectivity,
                $color,
                $source['barcode'] ?? null,
                $source['cost_price'] ?? 0,
                $source['selling_price'] ?? 0,
                $source['low_stock_threshold'] ?? 5,
            ]
        );
        $targetId = (int)Database::connection()->lastInsertId();

        $priceRows = Database::query('SELECT branch_id,selling_price FROM branch_product_prices WHERE product_id=?', [$sourceId])->fetchAll();
        foreach ($priceRows as $priceRow) {
            save_branch_selling_price($targetId, (int)$priceRow['branch_id'], (float)$priceRow['selling_price'], $userId ?: null);
        }
    }

    // Move only operational units to the corrected canonical variant. Sold and
    // transferred units stay on the historical source variant.
    $moveRows = Database::query(
        "SELECT id FROM inventory_units WHERE product_id=? AND status IN ('available','reserved') FOR UPDATE",
        [$sourceId]
    )->fetchAll();
    $moveIds = array_values(array_map(static fn($row) => (int)$row['id'], $moveRows));

    if ($moveIds) {
        $marks = implode(',', array_fill(0, count($moveIds), '?'));
        Database::query(
            "UPDATE inventory_units SET product_id=? WHERE id IN ($marks)",
            array_merge([$targetId], $moveIds)
        );
        Database::query(
            "UPDATE stock_movements SET product_id=? WHERE unit_id IN ($marks)",
            array_merge([$targetId], $moveIds)
        );
    }

    // If the target already existed, preserve any branch price not yet configured there.
    $priceRows = Database::query('SELECT branch_id,selling_price FROM branch_product_prices WHERE product_id=?', [$sourceId])->fetchAll();
    foreach ($priceRows as $priceRow) {
        $exists = Database::query('SELECT id FROM branch_product_prices WHERE product_id=? AND branch_id=? LIMIT 1', [$targetId,(int)$priceRow['branch_id']])->fetchColumn();
        if (!$exists) save_branch_selling_price($targetId, (int)$priceRow['branch_id'], (float)$priceRow['selling_price'], $userId ?: null);
    }

    $catalogDeletedReady = (bool)Database::query("SHOW COLUMNS FROM products LIKE 'catalog_deleted_at'")->fetch();
    if ($catalogDeletedReady) {
        Database::query('UPDATE products SET is_active=0,catalog_deleted_at=COALESCE(catalog_deleted_at,NOW()),catalog_deleted_by=COALESCE(catalog_deleted_by,?) WHERE id=?', [$userId ?: null,$sourceId]);
    } else {
        Database::query('UPDATE products SET is_active=0 WHERE id=?', [$sourceId]);
    }

    Security::audit('catalog.variant_unlocked_corrected', 'product', $targetId, [
        'mode' => $existingTarget ? 'merged_into_existing' : 'split_for_history',
        'source_variant_id' => $sourceId,
        'moved_active_unit_count' => count($moveIds),
        'protected_history_count' => $protectedHistory,
        'old_specs' => [
            'ram' => $source['ram'] ?? null,
            'storage' => $source['storage'] ?? null,
            'color' => $source['color'] ?? null,
            'connectivity' => $source['connectivity'] ?? null,
        ],
        'new_specs' => compact('ram','storage','color','connectivity'),
    ]);

    return $targetId;
}

function merge_variant_typo_records(int $sourceId, int $targetId): void {
    variant_merge_live_duplicate($sourceId, $targetId);
}


function clean_variant_value(mixed $value, int $max, bool $uppercase=true): string {
    $value = trim((string)$value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    if ($uppercase) $value = function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    return mb_substr($value, 0, $max);
}

function normalize_variant_capacity(mixed $value): string {
    $value = strtoupper(preg_replace('/\s+/', '', trim((string)$value)) ?? '');
    if ($value === '') return '';
    if (preg_match('/^\d+(?:\.\d+)?$/', $value)) return $value.'GB';
    if (preg_match('/^(\d+(?:\.\d+)?)G(?:B)?$/', $value, $m)) return $m[1].'GB';
    if (preg_match('/^(\d+(?:\.\d+)?)T(?:B)?$/', $value, $m)) return $m[1].'TB';
    return $value;
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
    $value = function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    return mb_substr($value, 0, $max);
}
function valid_device_type(mixed $value): string {
    $value = strtolower(trim((string)$value));
    return in_array($value, ['phone','tablet'], true) ? $value : 'phone';
}
function ensure_product_master_schema(): void {
    if (!Database::query("SHOW COLUMNS FROM product_models LIKE 'device_type'")->fetch()) {
        throw new RuntimeException('Product setup is incomplete. Contact the system administrator.');
    }
}
function success_message(string $entity, string $action): string {
    $label = $entity === 'configuration' ? 'Variant' : ucfirst($entity);
    return match ($action) {
        'add' => $label.' added to Product Master.',
        'edit' => $label.' updated.',
        'archive' => $label.' archived. Existing inventory/history was preserved.',
        'restore' => $label.' restored.',
        'delete' => $label.' deleted from Product Setup. Historical transactions were preserved when required.',
        'merge_typo' => 'Spelling duplicate merged into the correct variant. Inventory and history were preserved.',
        default => 'Product Master updated.',
    };
}
