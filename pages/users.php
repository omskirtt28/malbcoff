<?php
$rows=[];
try {
    $where=Auth::isOwner()?'1=1':'u.branch_id=:branch';
    $params=Auth::isOwner()?[]:['branch'=>Auth::branchId()];
    $rows=Database::query("SELECT u.name,u.email,u.role,u.is_active,b.name branch_name FROM users u LEFT JOIN branches b ON b.id=u.branch_id WHERE {$where} ORDER BY FIELD(u.role,'owner','branch_manager','inventory','cashier'),u.name",$params)->fetchAll();
} catch(Throwable $e){}
?>
<section class="page-heading"><div><span class="eyebrow">ACCESS CONTROL</span><h1>Users</h1><p>Keep roles simple and branch access clear.</p></div></section>
<div class="role-grid">
<article class="role-card"><span class="role-icon owner">O</span><div><strong>Owner</strong><p>All branches, inventory overview, cost/profit visibility and controls.</p></div></article>
<article class="role-card"><span class="role-icon manager">M</span><div><strong>Branch Manager</strong><p>Own branch inventory, stock activity and branch users.</p></div></article>
<article class="role-card"><span class="role-icon inventory">I</span><div><strong>Inventory Staff</strong><p>Products, Stock In, inventory and stock movement.</p></div></article>
<article class="role-card"><span class="role-icon cashier">C</span><div><strong>Cashier</strong><p>POS access will be activated in Phase 2.</p></div></article>
</div>
<section class="card table-card"><div class="table-wrap"><table class="data-table"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Branch</th><th>Status</th></tr></thead><tbody>
<?php foreach($rows as $row): ?><tr><td><strong><?= e($row['name']) ?></strong></td><td><?= e($row['email']) ?></td><td><?= e(role_label($row['role'])) ?></td><td><?= e($row['branch_name'] ?: 'All Branches') ?></td><td><span class="status-pill <?= $row['is_active']?'available':'low' ?>"><?= $row['is_active']?'Active':'Inactive' ?></span></td></tr><?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="5"><div class="empty-state small">No user records available.</div></td></tr><?php endif; ?>
</tbody></table></div></section>
