<?php
$scope = current_branch_scope();
$inventoryRole = Auth::user()['role'] ?? '';
$canStockIn = in_array($inventoryRole, ['owner','branch_manager','inventory'], true);
$brand = filter_input(INPUT_GET, 'brand', FILTER_VALIDATE_INT);
$type = $_GET['type'] ?? '';
$search = trim((string)($_GET['q'] ?? ''));

$brands = $rows = [];
try {
    $brands = Database::query('SELECT id,name FROM brands WHERE is_active=1 ORDER BY name')->fetchAll();
    $conditions = [];
    $params = [];

    if ($brand) { $conditions[] = 'p.brand_id = :brand'; $params['brand'] = $brand; }
    if (in_array($type, ['phone','tablet','accessory'], true)) { $conditions[] = 'p.product_type = :type'; $params['type'] = $type; }
    if ($search !== '') {
        $conditions[] = '(br.name LIKE :search1 OR pm.name LIKE :search2 OR p.product_name LIKE :search3 OR p.barcode LIKE :search4 OR EXISTS (SELECT 1 FROM inventory_units six WHERE six.product_id=p.id AND (six.imei LIKE :search5 OR six.serial_no LIKE :search6)))';
        for ($i=1;$i<=6;$i++) $params['search'.$i] = '%'.$search.'%';
    }

    if ($scope) {
        $conditions[] = '(EXISTS (SELECT 1 FROM inventory_units sx WHERE sx.product_id=p.id AND sx.branch_id=:scope_exists_unit) OR EXISTS (SELECT 1 FROM inventory_balances sb WHERE sb.product_id=p.id AND sb.branch_id=:scope_exists_balance))';
        $params['scope_exists_unit'] = $scope;
        $params['scope_exists_balance'] = $scope;
    } else {
        $conditions[] = '(EXISTS (SELECT 1 FROM inventory_units sx WHERE sx.product_id=p.id) OR EXISTS (SELECT 1 FROM inventory_balances sb WHERE sb.product_id=p.id))';
    }
    $where = $conditions ? 'WHERE '.implode(' AND ', $conditions) : '';

    if ($scope) {
        $qtySql = "CASE WHEN p.product_type='accessory'
                    THEN COALESCE((SELECT ib.quantity FROM inventory_balances ib WHERE ib.product_id=p.id AND ib.branch_id=:scope_qty_balance LIMIT 1),0)
                    ELSE COALESCE((SELECT COUNT(*) FROM inventory_units iu WHERE iu.product_id=p.id AND iu.branch_id=:scope_qty_unit AND iu.status='available'),0)
                   END";
        $branchSql = "COALESCE((SELECT name FROM branches WHERE id=:scope_branch_name),'—')";
        $prelovedSql = "COALESCE((SELECT COUNT(*) FROM inventory_units ip WHERE ip.product_id=p.id AND ip.branch_id=:scope_pre AND ip.status='available' AND ip.condition_type='preloved'),0)";
        $params['scope_qty_balance']=$scope; $params['scope_qty_unit']=$scope; $params['scope_branch_name']=$scope; $params['scope_pre']=$scope;
    } else {
        $qtySql = "CASE WHEN p.product_type='accessory'
                    THEN COALESCE((SELECT SUM(ib.quantity) FROM inventory_balances ib WHERE ib.product_id=p.id),0)
                    ELSE COALESCE((SELECT COUNT(*) FROM inventory_units iu WHERE iu.product_id=p.id AND iu.status='available'),0)
                   END";
        $branchSql = "CASE WHEN p.product_type='accessory'
                    THEN COALESCE((SELECT GROUP_CONCAT(DISTINCT b1.name ORDER BY b1.id SEPARATOR ', ') FROM inventory_balances ib1 JOIN branches b1 ON b1.id=ib1.branch_id WHERE ib1.product_id=p.id AND ib1.quantity<>0),'—')
                    ELSE COALESCE((SELECT GROUP_CONCAT(DISTINCT b2.name ORDER BY b2.id SEPARATOR ', ') FROM inventory_units iu2 JOIN branches b2 ON b2.id=iu2.branch_id WHERE iu2.product_id=p.id AND iu2.status='available'),'—')
                   END";
        $prelovedSql = "COALESCE((SELECT COUNT(*) FROM inventory_units ip WHERE ip.product_id=p.id AND ip.status='available' AND ip.condition_type='preloved'),0)";
    }

    $sql = "SELECT p.id,p.product_type,br.name brand_name,pm.name model_name,p.product_name,p.ram,p.storage,p.color,p.connectivity,p.barcode,c.name category_name,
                   {$qtySql} AS available_units, {$branchSql} AS branches, {$prelovedSql} AS preloved_units
            FROM products p
            LEFT JOIN brands br ON br.id=p.brand_id
            LEFT JOIN product_models pm ON pm.id=p.model_id
            LEFT JOIN categories c ON c.id=p.category_id
            {$where}
            ORDER BY COALESCE(br.name,p.product_name),pm.name,p.ram,p.storage,p.connectivity,p.color
            LIMIT 200";
    $rows = Database::query($sql,$params)->fetchAll();
} catch (Throwable $e) {}

function inventory_specs(array $row): string {
    if (($row['product_type'] ?? '') === 'accessory') return $row['category_name'] ?: 'Barcode / Quantity';
    $parts = [];
    if (!empty($row['ram'])) $parts[] = $row['ram'];
    if (!empty($row['storage'])) $parts[] = $row['storage'];
    if (!empty($row['connectivity'])) $parts[] = $row['connectivity'];
    if (!empty($row['color'])) $parts[] = $row['color'];
    return $parts ? implode(' • ', $parts) : '—';
}
function inventory_type_label(string $type): string {
    return match($type){'tablet'=>'Tablet','accessory'=>'Accessory',default=>'Phone'};
}
?>
<section class="page-heading"><div><span class="eyebrow">STOCK OVERVIEW</span><h1>Inventory</h1><p><?= Auth::isOwner() ? 'Search and monitor received stock across all branches.' : 'Search and monitor stock already received by your branch.' ?></p></div><?php if($canStockIn): ?><a class="btn btn-primary" href="index.php?page=stock-in<?= Auth::isOwner()&&$scope?'&branch='.(int)$scope:'' ?>"><?= icon('stock') ?> Receive Stock</a><?php endif; ?></section>

<form class="filter-card" method="get">
    <input type="hidden" name="page" value="inventory">
    <?php if ($scope && Auth::isOwner()): ?><input type="hidden" name="branch" value="<?= (int)$scope ?>"><?php endif; ?>
    <label class="search-box"><?= icon('search') ?><input type="search" name="q" value="<?= e($search) ?>" placeholder="Search product, model, IMEI, serial, barcode…"></label>
    <label><span>Brand</span><select name="brand"><option value="">All Brands</option><?php foreach ($brands as $row): ?><option value="<?= (int)$row['id'] ?>" <?= $brand===(int)$row['id']?'selected':'' ?>><?= e($row['name']) ?></option><?php endforeach; ?></select></label>
    <label><span>Item Type</span><select name="type"><option value="">All Types</option><option value="phone" <?= $type==='phone'?'selected':'' ?>>Phone</option><option value="tablet" <?= $type==='tablet'?'selected':'' ?>>Tablet</option><option value="accessory" <?= $type==='accessory'?'selected':'' ?>>Accessory</option></select></label>
    <button class="btn btn-secondary" type="submit">Apply</button>
    <a class="btn btn-ghost" href="<?= e(owner_branch_filter_url('inventory',$scope)) ?>">Reset</a>
</form>

<section class="card table-card">
<div class="table-wrap"><table class="data-table"><thead><tr><th>Product</th><th>Specs</th><th>Available Units</th><th>Branch</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php if(!$rows): ?>
<tr><td colspan="6"><div class="empty-state"><div class="empty-icon"><?= icon('inventory') ?></div><strong>No stock received yet</strong><span>Add your products in Products, then use Receive Stock when items arrive.</span><div class="empty-actions"><a class="btn btn-secondary btn-sm" href="index.php?page=products">Products</a><?php if($canStockIn): ?><a class="btn btn-primary btn-sm" href="index.php?page=stock-in">Receive Stock</a><?php endif; ?></div></div></td></tr>
<?php else: foreach($rows as $row):
    $name = $row['product_type']==='accessory' ? $row['product_name'] : trim(($row['brand_name']??'').' '.($row['model_name']??''));
    $qty = (int)$row['available_units'];
    $low = $qty <= 5;
    $preloved = (int)$row['preloved_units'];
    $typeLabel = inventory_type_label($row['product_type']);
?>
<tr>
<td><div class="product-cell"><div class="brand-avatar small"><?= e(strtoupper(substr($row['brand_name'] ?: ($row['product_type']==='accessory'?'AC':'DV'),0,2))) ?></div><div><strong><?= e($name) ?></strong><span><?= e($typeLabel) ?><?= $preloved ? ' • '.number_format($preloved).' Pre-Loved' : '' ?></span></div></div></td>
<td><span class="specs-text"><?= e(inventory_specs($row)) ?></span></td>
<td><strong><?= number_format($qty) ?></strong></td>
<td><?= e($row['branches'] ?: '—') ?></td>
<td><span class="status-pill <?= $low?'low':'available' ?>"><?= $low?'Low Stock':'Available' ?></span></td>
<td><div class="stock-inline-actions"><button type="button" class="btn btn-outline btn-sm" data-unit-modal data-product="<?= e($name) ?>" data-product-id="<?= (int)$row['id'] ?>"><?= icon('eye') ?> View Units</button></div></td>
</tr>
<?php endforeach; endif; ?>
</tbody></table></div>
</section>
<div class="modal" id="unitModal" hidden><div class="modal-backdrop" data-modal-close></div><div class="modal-dialog"><div class="modal-header"><div><span class="eyebrow">UNIT DETAILS</span><h2 data-modal-title>Product Units</h2></div><button type="button" class="icon-button" data-modal-close>×</button></div><div class="modal-body" data-modal-body><div class="loading-state">Select a product to view units.</div></div></div></div>
<style>.stock-inline-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.specs-text{font-weight:650;color:#475467;white-space:nowrap}</style>
