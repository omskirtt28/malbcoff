<?php
$rows = [];
$branches = [];
$schemaReady = false;
try {
    $schemaReady = (bool)Database::query("SHOW COLUMNS FROM users LIKE 'must_change_password'")->fetch();
    $where = Auth::isOwner() ? "u.role<>'system_admin'" : "u.branch_id=:branch AND u.role<>'system_admin'";
    $params = Auth::isOwner() ? [] : ['branch' => Auth::branchId()];
    $selectExtra = $schemaReady
        ? ',u.must_change_password,u.password_changed_at,u.last_login_at,u.created_at'
        : ',0 AS must_change_password,NULL AS password_changed_at,NULL AS last_login_at,u.created_at';
    $rows = Database::query(
        "SELECT u.id,u.branch_id,u.name,u.email,u.role,u.is_active{$selectExtra},b.name branch_name
         FROM users u LEFT JOIN branches b ON b.id=u.branch_id
         WHERE {$where}
         ORDER BY FIELD(u.role,'owner','branch_manager','inventory','cashier'),u.name",
        $params
    )->fetchAll();
    $branches = Database::query('SELECT id,name,code FROM branches WHERE is_active=1 ORDER BY id')->fetchAll();
} catch (Throwable $e) {
    Security::reportException($e, 'users_page');
}
$canManageUsers = Auth::isOwner() && $schemaReady;
?>
<section class="page-heading users-page-heading">
    <div>
        <span class="eyebrow">ACCESS CONTROL</span>
        <h1>Users</h1>
        <p><?= Auth::isOwner() ? 'Create and manage individual accounts, roles and branch assignments.' : 'View the active users assigned to your branch.' ?></p>
    </div>
    <?php if ($canManageUsers): ?>
        <button class="btn btn-primary" type="button" data-user-create><?= icon('plus') ?> Add User</button>
    <?php endif; ?>
</section>

<?php if (Auth::isOwner() && !$schemaReady): ?>
<div class="alert alert-error">User management is temporarily unavailable. Please contact the system administrator.</div>
<?php endif; ?>

<div class="role-grid user-role-grid">
    <article class="role-card"><span class="role-icon owner">O</span><div><strong>Owner</strong><p>All-branch monitoring, user administration and archive controls.</p></div></article>
    <article class="role-card"><span class="role-icon manager">M</span><div><strong>Branch Manager</strong><p>Own-branch POS, inventory, receiving, transfers and branch sales.</p></div></article>
    <article class="role-card"><span class="role-icon inventory">I</span><div><strong>Inventory Staff</strong><p>Products, receiving, inventory, transfers and stock movement.</p></div></article>
    <article class="role-card"><span class="role-icon cashier">C</span><div><strong>Cashier</strong><p>POS sales, branch sales records and inventory lookup.</p></div></article>
</div>

<section class="card table-card user-management-card">
    <div class="card-header user-management-head">
        <div><h2>User Accounts</h2><p><?= count($rows) ?> account<?= count($rows) === 1 ? '' : 's' ?> in your current scope.</p></div>
        <?php if ($canManageUsers): ?><span class="security-note"><?= icon('shield') ?> Individual accounts only</span><?php endif; ?>
    </div>
    <div class="table-wrap">
        <table class="data-table users-table">
            <thead><tr><th>Name</th><th>Role</th><th>Branch</th><th>Account</th><th>Last Login</th><?php if ($canManageUsers): ?><th>Actions</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php
                $lastLogin = !empty($row['last_login_at']) ? date('M j, Y • g:i A', strtotime((string)$row['last_login_at'])) : 'Never';
                $needsChange = (int)($row['must_change_password'] ?? 0) === 1;
                ?>
                <tr>
                    <td data-label="Name"><strong><?= e($row['name']) ?></strong><span class="table-subtext"><?= e($row['email']) ?></span></td>
                    <td data-label="Role"><span class="user-role-pill role-<?= e($row['role']) ?>"><?= e(role_label($row['role'])) ?></span></td>
                    <td data-label="Branch"><?= e($row['branch_name'] ?: 'All Branches') ?></td>
                    <td data-label="Account">
                        <span class="status-pill <?= $row['is_active'] ? 'available' : 'low' ?>"><?= $row['is_active'] ? 'Active' : 'Inactive' ?></span>
                        <?php if ($needsChange): ?><span class="status-pill user-password-pending">Password Change Required</span><?php endif; ?>
                    </td>
                    <td data-label="Last Login"><span class="user-last-login"><?= e($lastLogin) ?></span></td>
                    <?php if ($canManageUsers): ?>
                    <td data-label="Actions" class="user-actions-cell">
                        <div class="user-row-actions">
                            <button class="btn btn-secondary btn-sm" type="button" data-user-edit
                                data-id="<?= (int)$row['id'] ?>"
                                data-name="<?= e($row['name']) ?>"
                                data-email="<?= e($row['email']) ?>"
                                data-role="<?= e($row['role']) ?>"
                                data-branch-id="<?= (int)($row['branch_id'] ?? 0) ?>"><?= icon('edit') ?> Edit</button>
                            <button class="btn btn-secondary btn-sm" type="button" data-user-reset data-id="<?= (int)$row['id'] ?>" data-name="<?= e($row['name']) ?>"><?= icon('shield') ?> Reset Password</button>
                            <form method="post" action="actions/user_management.php" data-confirm="<?= $row['is_active'] ? 'Deactivate this user account?' : 'Activate this user account?' ?>">
                                <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                <button class="btn btn-sm <?= $row['is_active'] ? 'btn-secondary user-deactivate-btn' : 'btn-outline' ?>" type="submit" <?= (int)$row['id'] === (int)(Auth::user()['id'] ?? 0) ? 'disabled title="You cannot deactivate your own account"' : '' ?>><?= $row['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                            </form>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="<?= $canManageUsers ? 6 : 5 ?>"><div class="empty-state small"><strong>No user records available.</strong><span>Create the first account for this scope.</span></div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($canManageUsers): ?>
<div class="modal user-account-modal" id="userAccountModal" hidden>
    <button class="modal-backdrop" type="button" data-user-modal-close aria-label="Close"></button>
    <section class="modal-dialog user-account-dialog" role="dialog" aria-modal="true" aria-labelledby="userAccountTitle">
        <header class="modal-header">
            <div><span class="eyebrow" data-user-modal-eyebrow>NEW ACCOUNT</span><h2 id="userAccountTitle" data-user-modal-title>Add User</h2><p class="modal-subtitle">Assign one account to one employee. Branch access is enforced by the backend.</p></div>
            <button class="icon-button" type="button" data-user-modal-close aria-label="Close"><?= icon('close') ?></button>
        </header>
        <form method="post" action="actions/user_management.php" class="user-account-form" data-user-account-form>
            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="action" value="create" data-user-action>
            <input type="hidden" name="id" value="" data-user-id>
            <div class="user-form-grid">
                <label class="field user-form-full"><span>Full Name <b>*</b></span><input type="text" name="name" maxlength="120" autocomplete="off" required data-user-name></label>
                <label class="field user-form-full"><span>Email Address <b>*</b></span><input type="email" name="email" maxlength="160" autocomplete="off" required data-user-email></label>
                <label class="field"><span>Role <b>*</b></span><select name="role" required data-user-role><option value="branch_manager">Branch Manager</option><option value="inventory">Inventory Staff</option><option value="cashier">Cashier</option><option value="owner">Owner</option></select></label>
                <label class="field" data-user-branch-wrap><span>Branch <b>*</b></span><select name="branch_id" data-user-branch><option value="">Select branch</option><?php foreach ($branches as $branch): ?><option value="<?= (int)$branch['id'] ?>"><?= e($branch['name']) ?></option><?php endforeach; ?></select></label>
                <label class="field user-form-full" data-user-temp-password-wrap><span>Temporary Password <b>*</b></span><input type="password" name="temporary_password" minlength="12" maxlength="128" autocomplete="new-password" data-user-temp-password><small>At least 12 characters with a letter and number. The user must change this after first sign in.</small></label>
            </div>
            <footer class="user-modal-actions"><button class="btn btn-secondary" type="button" data-user-modal-close>Cancel</button><button class="btn btn-primary" type="submit" data-user-submit>Create User</button></footer>
        </form>
    </section>
</div>

<div class="modal user-account-modal" id="userResetModal" hidden>
    <button class="modal-backdrop" type="button" data-user-reset-close aria-label="Close"></button>
    <section class="modal-dialog user-reset-dialog" role="dialog" aria-modal="true" aria-labelledby="userResetTitle">
        <header class="modal-header"><div><span class="eyebrow">PASSWORD RESET</span><h2 id="userResetTitle">Set Temporary Password</h2><p class="modal-subtitle" data-user-reset-copy>The user must replace this password after signing in.</p></div><button class="icon-button" type="button" data-user-reset-close aria-label="Close"><?= icon('close') ?></button></header>
        <form method="post" action="actions/user_management.php" class="user-account-form">
            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="" data-user-reset-id>
            <label class="field"><span>Temporary Password <b>*</b></span><input type="password" name="temporary_password" minlength="12" maxlength="128" autocomplete="new-password" required><small>At least 12 characters with a letter and number.</small></label>
            <footer class="user-modal-actions"><button class="btn btn-secondary" type="button" data-user-reset-close>Cancel</button><button class="btn btn-primary" type="submit">Reset Password</button></footer>
        </form>
    </section>
</div>
<?php endif; ?>
