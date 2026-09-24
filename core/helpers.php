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
    return in_array($role, ['owner', 'branch_manager', 'cashier'], true);
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
    return 'index.php?page=' . urlencode($page) . ($branchId ? '&branch=' . $branchId : '');
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
        throw new RuntimeException('Run database/P2_004_pricing_variant_serial_ux.sql before managing branch selling prices.');
    }
    if ($productId <= 0 || $branchId <= 0) throw new RuntimeException('A valid product and branch are required.');
    $price = round(max(0, $price), 2);
    Database::query(
        'INSERT INTO branch_product_prices (product_id,branch_id,selling_price,updated_by) VALUES (?,?,?,?) '
        . 'ON DUPLICATE KEY UPDATE selling_price=VALUES(selling_price),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP',
        [$productId, $branchId, $price, $userId]
    );
}
