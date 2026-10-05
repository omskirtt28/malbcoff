<?php
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}


/**
 * Return the application's public base path.
 *
 * Production runs at the domain root, while local/XAMPP copies may live in a
 * sub-folder (for example /malbcoff). Keeping this dynamic lets clean URLs
 * work in both environments and from /actions/*.php redirects.
 */
function app_base_path(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (($actionPos = strpos($script, '/actions/')) !== false) {
        return rtrim(substr($script, 0, $actionPos), '/');
    }

    $dir = str_replace('\\', '/', dirname($script));
    if ($dir === '/' || $dir === '.' || $dir === '\\') return '';
    return rtrim($dir, '/');
}

/**
 * Build a browser-facing clean URL for an application route.
 */
function app_url(string $route = 'dashboard', array $params = [], string $fragment = ''): string
{
    // Archive is the clean public route for the archived Products state.
    if ($route === 'products' && (($params['status'] ?? '') === 'archived')) {
        $route = 'archive';
        unset($params['status']);
    }

    // Deleted Records intentionally reuses the already-established /system-admin
    // route. Some production hosts keep an older .htaccess during patch-only
    // uploads, which can make a brand-new clean URL return Apache 404 before
    // PHP is reached. Using the existing System Admin route avoids that
    // dependency while preserving a clean browser URL.
    if ($route === 'system-admin-deleted-records') {
        $route = 'system-admin-dashboard';
        $params = ['view' => 'deleted-records'] + $params;
    }

    $paths = [
        'dashboard' => '/',
        'pos' => '/pos',
        'sales-records' => '/sales-records',
        'products' => '/products',
        'archive' => '/archive',
        'add-item' => '/add-item',
        'inventory' => '/inventory',
        'stock-monitoring' => '/stock-monitoring',
        'stock-monitoring-export' => '/stock-monitoring/export',
        'branch-transfers' => '/branch-transfers',
        'stock-in' => '/receive-stock',
        'stock-movement' => '/stock-movement',
        'users' => '/users',
        'system-admin-dashboard' => '/system-admin',
        'system-admin-users' => '/system-admin-users',
        'system-admin-history' => '/system-admin-history',
        'system-admin-deleted-records' => '/system-admin-deleted-records',
        'security-logs' => '/security-logs',
        'system-admin-data-reset' => '/system-admin-data-reset',
        'login' => '/login',
        'logout' => '/logout',
        'change-password' => '/change-password',
        'maintenance' => '/maintenance',
    ];

    $path = $paths[$route] ?? '/';
    $base = app_base_path();
    $url = $path === '/' ? ($base !== '' ? $base . '/' : '/') : $base . $path;

    if ($params) {
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        if ($query !== '') $url .= '?' . $query;
    }
    if ($fragment !== '') $url .= '#' . ltrim($fragment, '#');
    return $url;
}

function app_home_url(): string
{
    return Auth::isSystemAdmin() && !Auth::isImpersonating()
        ? app_url('system-admin-dashboard')
        : app_url('dashboard');
}

/**
 * True only when the browser directly requested a legacy root PHP script.
 * Internal mod_rewrite requests keep the friendly REQUEST_URI and therefore
 * do not trigger a redirect loop.
 */
function is_legacy_script_request(string $filename): bool
{
    $requestPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    return basename(rtrim($requestPath, '/')) === $filename;
}

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['_flash'][$key] = $message;
        return null;
    }
    $value = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $value;
}

function role_label(string $role): string
{
    return match ($role) {
        'system_admin' => 'System Admin',
        'owner' => 'Owner',
        'branch_manager' => 'Branch Manager',
        'cashier' => 'Cashier',
        'inventory' => 'Inventory Staff',
        default => ucfirst(str_replace('_', ' ', $role)),
    };
}

function movement_label(string $type): string
{
    return match ($type) {
        'stock_in' => 'Stock In',
        'stock_out' => 'Stock Out',
        'sale' => 'Sold',
        'transfer_in', 'transfer_out' => 'Transfer',
        'adjustment' => 'Adjustment',
        'return' => 'Return',
        'defective' => 'Defective',
        default => ucwords(str_replace('_', ' ', $type)),
    };
}

function icon(string $name, string $class = ''): string
{
    $icons = [
        'dashboard' => '<path d="M3 10.5 12 3l9 7.5v9a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 19.5z"/><path d="M9 21v-7h6v7"/>',
        'products' => '<path d="m7.5 4.3 4.5-2.1 4.5 2.1 4 1.9-8.5 4-8.5-4z"/><path d="M3.5 6.2v9.5l8.5 4.1 8.5-4.1V6.2"/><path d="M12 10.2v9.6"/>',
        'inventory' => '<path d="M4 7.5 12 3l8 4.5-8 4.5z"/><path d="M4 12l8 4.5 8-4.5"/><path d="M4 16.5 12 21l8-4.5"/>',
        'stock' => '<path d="M12 3v12"/><path d="m7.5 10.5 4.5 4.5 4.5-4.5"/><path d="M4 17.5V21h16v-3.5"/>',
        'movement' => '<path d="M7 7h11l-3-3"/><path d="m18 7-3 3"/><path d="M17 17H6l3 3"/><path d="m6 17 3-3"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9.5" cy="7" r="4"/><path d="M17 11a4 4 0 0 1 4 4v2"/><path d="M17 3.2a4 4 0 0 1 0 7.6"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'phone' => '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/>',
        'tablet' => '<rect x="4" y="2.5" width="16" height="19" rx="2"/><path d="M11 18.5h2"/>',
        'tag' => '<path d="M20.5 13.5 13.5 20.5 3.5 10.5V3.5h7z"/><circle cx="8" cy="8" r="1"/>',
        'accessory' => '<path d="M4 13a8 8 0 0 1 16 0"/><path d="M4 13v5a2 2 0 0 0 2 2h2v-8H6a2 2 0 0 0-2 2"/><path d="M20 13v5a2 2 0 0 1-2 2h-2v-8h2a2 2 0 0 1 2 2"/>',
        'branch' => '<path d="M4 10h16"/><path d="M6 10v10h12V10"/><path d="M8 6h8l2 4H6z"/><path d="M9 14h2v6M15 14h-2v6"/>',
        'chevron' => '<path d="m8 10 4 4 4-4"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4z"/>',
        'archive' => '<path d="M4 7h16v13H4z"/><path d="M3 3h18v4H3z"/><path d="M9 11h6"/>',
        'restore' => '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/>',
        'trash' => '<path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 14h10l1-14"/><path d="M9 7V4h6v3"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'barcode' => '<path d="M3 5v14M6 5v14M10 5v14M14 5v14M17 5v14M21 5v14"/>',
        'pos' => '<path d="M4 5h16v11H4z"/><path d="M8 20h8M12 16v4"/><path d="M8 9h3M15 9h1M8 12h8"/>',
        'cart' => '<circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/><path d="M3 4h2l2.4 10.3a2 2 0 0 0 2 1.7h7.7a2 2 0 0 0 1.9-1.4L21 8H7"/>',
        'receipt' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6M9 16h4"/>',
        'cash' => '<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M7 9h.01M17 15h.01"/>',
        'save' => '<path d="M5 3h12l4 4v14H3V3z"/><path d="M7 3v6h10V3M7 21v-8h10v8"/>',
        'eye' => '<path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6"/><circle cx="12" cy="12" r="2.5"/>',
        'logout' => '<path d="M10 5H4v14h6"/><path d="m14 8 4 4-4 4"/><path d="M8 12h10"/>',
        'shield' => '<path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11"/><path d="m9 12 2 2 4-4"/>',
        'alert' => '<path d="M12 3 2.5 20h19z"/><path d="M12 9v4M12 17h.01"/>',

        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>',
        'more' => '<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
        'close' => '<path d="m6 6 12 12M18 6 6 18"/>',
    ];
    $body = $icons[$name] ?? $icons['dashboard'];
    return '<svg class="icon ' . e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}



function brand_logo_asset(?string $brand): ?string
{
    $key = strtolower(trim((string)$brand));
    $key = preg_replace('/[^a-z0-9]+/', '', $key);
    $map = [
        'apple' => 'apple.svg',
        'samsung' => 'samsung.svg',
        'xiaomi' => 'xiaomi.svg',
        'oppo' => 'oppo.svg',
        'vivo' => 'vivo.svg',
        'realme' => 'realme.svg',
        'redmi' => 'redmi.svg',
        'tecno' => 'tecno.svg',
        'itel' => 'itel.svg',
        'infinix' => 'infinix.svg',
        'honor' => 'honor.svg',
        'poco' => 'poco.svg',
        'nubia' => 'nubia.svg',
    ];
    return isset($map[$key]) ? 'assets/brand-logos/' . $map[$key] : null;
}

function brand_logo_html(?string $brand, string $class = ''): string
{
    $asset = brand_logo_asset($brand);
    $brand = trim((string)$brand);
    if ($asset) {
        return '<span class="brand-logo ' . e($class) . '"><img src="' . e($asset) . '" alt="' . e($brand) . ' logo" loading="lazy"></span>';
    }
    $initials = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $brand ?: 'DV'), 0, 2));
    return '<span class="brand-logo brand-logo-fallback ' . e($class) . '" aria-label="' . e($brand ?: 'Device') . '">' . e($initials ?: 'DV') . '</span>';
}

function malbcoff_product_name(array $row): string
{
    if (($row['product_type'] ?? '') === 'accessory') {
        return trim((string)($row['product_name'] ?? 'Accessory'));
    }
    return trim(((string)($row['brand_name'] ?? '')) . ' ' . ((string)($row['model_name'] ?? '')));
}

function malbcoff_product_specs(array $row): string
{
    if (($row['product_type'] ?? '') === 'accessory') {
        return trim((string)($row['category_name'] ?? 'Accessory'));
    }
    $parts = [];
    foreach (['ram', 'storage', 'connectivity', 'color'] as $key) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') $parts[] = $value;
    }
    return $parts ? implode(' • ', $parts) : '—';
}

function peso(float|int|string|null $amount): string
{
    return '₱' . number_format((float)$amount, 2);
}

function pos_role_allowed(?string $role = null): bool
{
    $role = $role ?? (Auth::user()['role'] ?? '');
    return in_array($role, ['branch_manager', 'cashier'], true);
}

function current_branch_scope(): ?int
{
    if (!Auth::isOwner()) {
        return Auth::branchId();
    }
    $branch = filter_input(INPUT_GET, 'branch', FILTER_VALIDATE_INT);
    return $branch && $branch > 0 ? $branch : null;
}

function owner_branch_filter_url(string $page, ?int $branchId): string
{
    return app_url($page, $branchId ? ['branch' => $branchId] : []);
}

function branch_pricing_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $ready = (bool)Database::query("SHOW TABLES LIKE 'branch_product_prices'")->fetchColumn();
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function branch_selling_price(int $productId, int $branchId, float $fallback = 0.0): float
{
    if ($productId <= 0 || $branchId <= 0 || !branch_pricing_ready()) return max(0, $fallback);
    try {
        $price = Database::query(
            'SELECT selling_price FROM branch_product_prices WHERE product_id=? AND branch_id=? LIMIT 1',
            [$productId, $branchId]
        )->fetchColumn();
        if ($price !== false && $price !== null) return max(0, (float)$price);
    } catch (Throwable $e) {
    }
    return max(0, $fallback);
}

// One rule shared by Receive Stock's UI payload and server-side storage.
function stock_uses_apple_serial(string $brand, string $model = ''): bool
{
    $brand = trim($brand);
    $model = trim($model);
    return preg_match('/^(?:apple|iphone|ipad)(?:$|[^a-z0-9])/i', $brand) === 1
        || preg_match('/^(?:apple\s+)?(?:iphone|ipad)(?:$|[^a-z0-9])/i', $model) === 1;
}

function save_branch_selling_price(int $productId, int $branchId, float $price, ?int $userId = null): void
{
    if (!branch_pricing_ready()) {
        throw new RuntimeException('Branch pricing setup is incomplete. Contact the system administrator.');
    }
    if ($productId <= 0 || $branchId <= 0) throw new RuntimeException('A valid product and branch are required.');
    $price = round(max(0, $price), 2);
    Database::query(
        'INSERT INTO branch_product_prices (product_id,branch_id,selling_price,updated_by) VALUES (?,?,?,?) '
        . 'ON DUPLICATE KEY UPDATE selling_price=VALUES(selling_price),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP',
        [$productId, $branchId, $price, $userId]
    );
}

function safe_exception_message(Throwable $e, string $fallback): string
{
    if ($e instanceof RuntimeException && !($e instanceof PDOException)) {
        $message = trim($e->getMessage());
        if ($message !== '') return $message;
    }
    if (class_exists('Security')) Security::reportException($e, 'user_facing_exception');
    return $fallback;
}

/**
 * Normalize a user-entered color label for consistent variant matching.
 * Keeps human-readable spacing while standardizing case.
 */
function variant_color_normalize(mixed $value, int $max = 80): string
{
    $value = trim((string)$value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    $value = function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    return mb_substr($value, 0, $max);
}

/**
 * Compact comparison key used only for typo detection, never for display.
 */
function variant_color_key(mixed $value): string
{
    $value = variant_color_normalize($value);
    $value = preg_replace('/[^A-Z0-9]+/', '', $value) ?? '';
    return $value;
}

/**
 * Return a canonical existing color for a model when the new entry is an
 * unambiguous one-character typo. If multiple near matches exist, block the
 * save and ask the user to choose an exact existing value.
 */
function variant_color_canonicalize(int $modelId, mixed $value, ?int $excludeProductId = null): array
{
    $input = variant_color_normalize($value);
    if ($input === '' || $modelId <= 0) {
        return ['value' => $input, 'corrected_from' => null, 'suggestions' => []];
    }

    $sql = "SELECT id,color,created_at FROM products
            WHERE model_id=? AND product_type IN ('phone','tablet')
              AND color IS NOT NULL AND TRIM(color)<>''";
    $params = [$modelId];
    if ($excludeProductId) {
        $sql .= ' AND id<>?';
        $params[] = $excludeProductId;
    }
    try {
        if (Database::query("SHOW COLUMNS FROM products LIKE 'catalog_deleted_at'")->fetch()) {
            $sql .= ' AND catalog_deleted_at IS NULL';
        }
    } catch (Throwable $e) {
    }
    $sql .= ' ORDER BY created_at ASC,id ASC';

    $rows = Database::query($sql, $params)->fetchAll();
    $byLabel = [];
    foreach ($rows as $row) {
        $label = variant_color_normalize($row['color'] ?? '');
        if ($label === '') continue;
        if (!isset($byLabel[$label])) $byLabel[$label] = $row;
    }

    if (isset($byLabel[$input])) {
        return ['value' => $input, 'corrected_from' => null, 'suggestions' => []];
    }

    $inputKey = variant_color_key($input);
    if (strlen($inputKey) < 4) {
        return ['value' => $input, 'corrected_from' => null, 'suggestions' => []];
    }

    $near = [];
    foreach ($byLabel as $label => $row) {
        $candidateKey = variant_color_key($label);
        if (strlen($candidateKey) < 4) continue;
        $distance = levenshtein($inputKey, $candidateKey);
        if ($distance <= 1) {
            $near[$label] = ['label' => $label, 'distance' => $distance, 'id' => (int)$row['id']];
        }
    }

    if (count($near) === 1) {
        $candidate = reset($near);
        return [
            'value' => $candidate['label'],
            'corrected_from' => $input,
            'suggestions' => [$candidate['label']],
        ];
    }

    if (count($near) > 1) {
        $labels = array_keys($near);
        sort($labels, SORT_NATURAL | SORT_FLAG_CASE);
        return ['value' => $input, 'corrected_from' => null, 'suggestions' => $labels];
    }

    return ['value' => $input, 'corrected_from' => null, 'suggestions' => []];
}

/**
 * Determines whether two existing variant colors are close enough to be shown
 * as a possible spelling duplicate. The actual merge action re-validates all
 * other specs server-side before touching data.
 */
function variant_colors_are_probable_typo(mixed $a, mixed $b): bool
{
    $ka = variant_color_key($a);
    $kb = variant_color_key($b);
    if ($ka === '' || $kb === '' || $ka === $kb || strlen($ka) < 4 || strlen($kb) < 4) return false;
    return levenshtein($ka, $kb) <= 1;
}

/**
 * Production-safe semantic key for device variant values.
 * This mirrors what users actually see in Inventory/Stock Monitoring so
 * historical rows that render identically can be consolidated even when an
 * older release stored the single capacity in a different column.
 */
function variant_semantic_value_key(mixed $value): string
{
    $value = trim((string)$value);
    if ($value === '') return '';
    $value = preg_replace('/[\x{00A0}\s]+/u', ' ', $value) ?? $value;
    $value = function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    return trim($value);
}

/**
 * Non-color device specs exactly in the same order shown to users.
 * Empty columns are intentionally skipped. Example: an old row with
 * RAM=NULL, STORAGE=128GB and another old row with RAM=128GB, STORAGE=NULL
 * both resolve to the same visible signature: 128GB.
 */
function variant_visible_non_color_signature(array $row): string
{
    $parts = [];
    foreach (['ram', 'storage', 'connectivity'] as $field) {
        $value = variant_semantic_value_key($row[$field] ?? '');
        if ($value !== '') $parts[] = $value;
    }
    return implode('|', $parts);
}

function variant_visible_signature(array $row): string
{
    $parts = [];
    $specs = variant_visible_non_color_signature($row);
    if ($specs !== '') $parts[] = $specs;
    $color = variant_color_key($row['color'] ?? '');
    if ($color !== '') $parts[] = $color;
    return implode('|', $parts);
}

/**
 * Accept only exact color duplicates or obvious truncated completions.
 * Covers the production cases requested by the client:
 * MIDNIGH -> MIDNIGHT, SILV/SILVE -> SILVER.
 */
function variant_colors_can_auto_merge(mixed $a, mixed $b): bool
{
    $ka = variant_color_key($a);
    $kb = variant_color_key($b);
    if ($ka === '' || $kb === '') return false;
    if ($ka === $kb) return true;

    $short = strlen($ka) <= strlen($kb) ? $ka : $kb;
    $long = strlen($ka) <= strlen($kb) ? $kb : $ka;
    $difference = strlen($long) - strlen($short);

    if (strlen($short) < 4 || $difference < 1 || $difference > 2) return false;
    return str_starts_with($long, $short);
}

function variant_semantic_specs_match(array $a, array $b): bool
{
    foreach (['product_type', 'brand_id', 'model_id'] as $field) {
        if ((string)($a[$field] ?? '') !== (string)($b[$field] ?? '')) return false;
    }
    return variant_visible_non_color_signature($a) === variant_visible_non_color_signature($b);
}

/**
 * Merge one legacy duplicate variant into the canonical product row.
 * The source product is archived rather than deleted so any future/unknown
 * historical foreign keys remain valid. Critical stock references are moved
 * transactionally, which makes the visible quantity become the true combined
 * quantity (for example 1 + 2 = 3).
 */
function variant_merge_live_duplicate(int $sourceId, int $targetId): void
{
    if ($sourceId <= 0 || $targetId <= 0 || $sourceId === $targetId) {
        throw new RuntimeException('Invalid duplicate variant merge request.');
    }

    $pdo = Database::connection();
    $started = !$pdo->inTransaction();
    if ($started) $pdo->beginTransaction();

    try {
        $source = Database::query(
            "SELECT id,product_type,brand_id,model_id,ram,storage,connectivity,color,cost_price,selling_price,is_active,created_at
             FROM products WHERE id=? LIMIT 1 FOR UPDATE",
            [$sourceId]
        )->fetch();
        $target = Database::query(
            "SELECT id,product_type,brand_id,model_id,ram,storage,connectivity,color,cost_price,selling_price,is_active,created_at
             FROM products WHERE id=? LIMIT 1 FOR UPDATE",
            [$targetId]
        )->fetch();

        if (!$source || !$target || !(int)$source['is_active'] || !(int)$target['is_active']) {
            throw new RuntimeException('The duplicate variant is no longer active.');
        }
        if (!variant_semantic_specs_match($source, $target)) {
            throw new RuntimeException('The variants do not represent the same visible model/specification.');
        }
        if (!variant_colors_can_auto_merge($source['color'] ?? '', $target['color'] ?? '')) {
            throw new RuntimeException('The variants do not represent the same color.');
        }

        // Quantity-based balance rows (normally accessories, but handled here
        // defensively for historical device data as well).
        $balances = Database::query(
            'SELECT branch_id,quantity FROM inventory_balances WHERE product_id=? FOR UPDATE',
            [$sourceId]
        )->fetchAll();
        foreach ($balances as $balance) {
            Database::query(
                'INSERT INTO inventory_balances (product_id,branch_id,quantity) VALUES (?,?,?) '
                . 'ON DUPLICATE KEY UPDATE quantity=inventory_balances.quantity+VALUES(quantity)',
                [$targetId, (int)$balance['branch_id'], (int)$balance['quantity']]
            );
        }
        Database::query('DELETE FROM inventory_balances WHERE product_id=?', [$sourceId]);

        // Branch price rows have a unique product+branch key. Canonical target
        // pricing wins when both variants already have a price for the branch.
        if (branch_pricing_ready()) {
            $prices = Database::query(
                'SELECT branch_id,selling_price,updated_by FROM branch_product_prices WHERE product_id=? FOR UPDATE',
                [$sourceId]
            )->fetchAll();
            foreach ($prices as $price) {
                $exists = Database::query(
                    'SELECT 1 FROM branch_product_prices WHERE product_id=? AND branch_id=? LIMIT 1',
                    [$targetId, (int)$price['branch_id']]
                )->fetchColumn();
                if (!$exists) {
                    Database::query(
                        'INSERT INTO branch_product_prices (product_id,branch_id,selling_price,updated_by) VALUES (?,?,?,?)',
                        [
                            $targetId,
                            (int)$price['branch_id'],
                            (float)$price['selling_price'],
                            $price['updated_by'] !== null ? (int)$price['updated_by'] : null,
                        ]
                    );
                }
            }
            Database::query('DELETE FROM branch_product_prices WHERE product_id=?', [$sourceId]);
        }

        // These two tables are the source of truth for the screens shown by the
        // client. They are mandatory: any failure rolls the whole merge back.
        Database::query('UPDATE inventory_units SET product_id=? WHERE product_id=?', [$targetId, $sourceId]);
        Database::query('UPDATE stock_movements SET product_id=? WHERE product_id=?', [$targetId, $sourceId]);

        // Optional historical modules. They should follow the canonical product
        // when available, but older installations may not have these tables yet.
        foreach (['sale_items', 'inventory_transfers'] as $table) {
            try {
                Database::query("UPDATE `{$table}` SET product_id=? WHERE product_id=?", [$targetId, $sourceId]);
            } catch (PDOException $e) {
                // Optional table missing on an older live database.
            }
        }

        // Preserve useful master pricing when the canonical target is blank.
        Database::query(
            'UPDATE products SET '
            . 'cost_price=CASE WHEN cost_price<=0 THEN ? ELSE cost_price END, '
            . 'selling_price=CASE WHEN selling_price<=0 THEN ? ELSE selling_price END '
            . 'WHERE id=?',
            [(float)$source['cost_price'], (float)$source['selling_price'], $targetId]
        );

        // Verify the critical rows really moved before hiding the source record.
        $leftUnits = (int)Database::query(
            'SELECT COUNT(*) FROM inventory_units WHERE product_id=?',
            [$sourceId]
        )->fetchColumn();
        $leftMovements = (int)Database::query(
            'SELECT COUNT(*) FROM stock_movements WHERE product_id=?',
            [$sourceId]
        )->fetchColumn();
        if ($leftUnits > 0 || $leftMovements > 0) {
            throw new RuntimeException('Critical stock references are still linked to the duplicate variant.');
        }

        $catalogDeleteReady = (bool)Database::query("SHOW COLUMNS FROM products LIKE 'catalog_deleted_at'")->fetch();
        if ($catalogDeleteReady) {
            Database::query(
                'UPDATE products SET is_active=0,catalog_deleted_at=COALESCE(catalog_deleted_at,NOW()) WHERE id=?',
                [$sourceId]
            );
        } else {
            Database::query('UPDATE products SET is_active=0 WHERE id=?', [$sourceId]);
        }

        if ($started) $pdo->commit();
    } catch (Throwable $e) {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Repair legacy production duplicates on demand.
 * No migration flag is used: the function is idempotent and can safely check
 * again after a deployment because already-merged sources are inactive.
 */
function variant_repair_live_device_duplicates(): array
{
    static $result = null;
    if (is_array($result)) return $result;
    $result = ['merged' => 0, 'errors' => 0];

    try {
        $catalogDeleteReady = (bool)Database::query("SHOW COLUMNS FROM products LIKE 'catalog_deleted_at'")->fetch();
        $deletedFilter = $catalogDeleteReady ? ' AND catalog_deleted_at IS NULL' : '';
        $rows = Database::query(
            "SELECT id,product_type,brand_id,model_id,ram,storage,connectivity,color,cost_price,selling_price,is_active,created_at
             FROM products
             WHERE is_active=1 AND product_type IN ('phone','tablet'){$deletedFilter}
             ORDER BY brand_id,model_id,created_at ASC,id ASC"
        )->fetchAll();

        $groups = [];
        foreach ($rows as $row) {
            $key = implode('|', [
                (string)($row['product_type'] ?? ''),
                (int)($row['brand_id'] ?? 0),
                (int)($row['model_id'] ?? 0),
                variant_visible_non_color_signature($row),
            ]);
            $groups[$key][] = $row;
        }

        foreach ($groups as $groupRows) {
            if (count($groupRows) < 2) continue;

            // Correct/longest color wins; if spelling length is equal, the oldest
            // row wins. This keeps MIDNIGHT over MIDNIGH and SILVER over SILVE/SILV.
            usort($groupRows, static function (array $a, array $b): int {
                $len = strlen(variant_color_key($b['color'] ?? '')) <=> strlen(variant_color_key($a['color'] ?? ''));
                if ($len !== 0) return $len;
                $created = strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? ''));
                if ($created !== 0) return $created;
                return ((int)$a['id']) <=> ((int)$b['id']);
            });

            $removed = [];
            foreach ($groupRows as $target) {
                $targetId = (int)$target['id'];
                if ($targetId <= 0 || isset($removed[$targetId])) continue;

                foreach ($groupRows as $source) {
                    $sourceId = (int)$source['id'];
                    if ($sourceId <= 0 || $sourceId === $targetId || isset($removed[$sourceId])) continue;
                    if (!variant_semantic_specs_match($source, $target)) continue;
                    if (!variant_colors_can_auto_merge($source['color'] ?? '', $target['color'] ?? '')) continue;

                    try {
                        variant_merge_live_duplicate($sourceId, $targetId);
                        $removed[$sourceId] = true;
                        $result['merged']++;
                        if (class_exists('Security')) {
                            Security::audit('catalog.variant_auto_consolidated', 'product', $targetId, [
                                'source_variant_id' => $sourceId,
                                'source_color' => (string)($source['color'] ?? ''),
                                'target_color' => (string)($target['color'] ?? ''),
                                'visible_specs' => variant_visible_non_color_signature($target),
                            ]);
                        }
                    } catch (Throwable $mergeError) {
                        $result['errors']++;
                        if (class_exists('Security')) {
                            Security::reportException($mergeError, 'variant_auto_consolidation');
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $result['errors']++;
        if (class_exists('Security')) Security::reportException($e, 'variant_auto_consolidation_scan');
    }

    return $result;
}

/**
 * Find an existing device variant by what the user actually sees, not only by
 * the historical storage column layout. Used by Receive Stock/Add Variant so
 * future entries reuse the canonical product id instead of creating another row.
 */
function variant_find_semantic_existing(
    string $type,
    int $brandId,
    int $modelId,
    mixed $ram,
    mixed $storage,
    mixed $connectivity,
    mixed $color,
    ?int $excludeProductId = null
): ?array {
    $sql = "SELECT id,product_type,brand_id,model_id,ram,storage,connectivity,color,is_active,cost_price,selling_price,created_at
            FROM products
            WHERE product_type=? AND brand_id=? AND model_id=?";
    $params = [$type, $brandId, $modelId];
    if ($excludeProductId) {
        $sql .= ' AND id<>?';
        $params[] = $excludeProductId;
    }
    try {
        if (Database::query("SHOW COLUMNS FROM products LIKE 'catalog_deleted_at'")->fetch()) {
            $sql .= ' AND catalog_deleted_at IS NULL';
        }
    } catch (Throwable $e) {
    }
    $sql .= ' ORDER BY is_active DESC,created_at ASC,id ASC';

    $needle = [
        'product_type' => $type,
        'brand_id' => $brandId,
        'model_id' => $modelId,
        'ram' => $ram,
        'storage' => $storage,
        'connectivity' => $connectivity,
        'color' => $color,
    ];
    $needleSpecs = variant_visible_non_color_signature($needle);
    $needleColor = variant_color_key($color);

    foreach (Database::query($sql, $params)->fetchAll() as $row) {
        if (variant_visible_non_color_signature($row) !== $needleSpecs) continue;
        $rowColor = variant_color_key($row['color'] ?? '');
        if ($rowColor === $needleColor || variant_colors_can_auto_merge($row['color'] ?? '', $color)) {
            return $row;
        }
    }
    return null;
}

/*
 * Backward-compatibility bridge for the earlier controlled-variant UI patch.
 * Some live pages from that patch still call these helper names. Keeping these
 * aliases prevents a fatal "undefined function" while routing all merge work
 * through the current production-safe semantic merge implementation above.
 */
function variant_color_is_truncated_typo(mixed $shortValue, mixed $longValue): bool
{
    $short = variant_color_key($shortValue);
    $long = variant_color_key($longValue);
    if ($short === '' || $long === '' || strlen($short) < 4 || strlen($long) <= strlen($short)) {
        return false;
    }

    $gap = strlen($long) - strlen($short);
    return $gap <= 2 && str_starts_with($long, $short);
}

function variant_color_is_likely_completion(mixed $shortValue, mixed $longValue): bool
{
    if (!variant_color_is_truncated_typo($shortValue, $longValue)) {
        return false;
    }

    $short = variant_color_key($shortValue);
    $long = variant_color_key($longValue);
    $suffix = substr($long, strlen($short));
    $last = substr($short, -1);

    // Reject repeated trailing-key mistakes such as SILVER -> SILVERR.
    if ($suffix !== '' && trim($suffix, $last) === '') {
        return false;
    }

    return true;
}

function variant_merge_product_records(int $sourceId, int $targetId): void
{
    variant_merge_live_duplicate($sourceId, $targetId);
}

function variant_cleanup_truncated_color_duplicates_once(): void
{
    // Current repair routine is idempotent, transaction-safe per merge, and
    // catches/report merge failures instead of breaking page rendering.
    variant_repair_live_device_duplicates();
}
