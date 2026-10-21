<?php
// Included only by the directly signed-in System Admin account page.
$managedBranches = Database::query('SELECT id,code,name,is_active FROM branches ORDER BY id')->fetchAll();
$branchNameLimit = (int)Database::query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='branches' AND COLUMN_NAME='name'")->fetchColumn();
?>
<dialog id="addUserDialog" class="admin-profile-dialog" aria-labelledby="addUserTitle">
    <div class="admin-profile-dialog-heading"><h2 id="addUserTitle">Add User</h2><button type="button" class="btn btn-secondary" data-close-account-dialog>Close</button></div>
    <form method="post" action="actions/system_admin_user_create.php" data-account-change-form autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
        <input type="hidden" name="client_key" value="<?= e(account_change_key()) ?>">
        <label class="field"><span>Full Name</span><input name="name" required maxlength="120" autocomplete="off"></label>
        <label class="field"><span>Email</span><input type="email" name="email" required maxlength="160" autocomplete="off"></label>
        <label class="field"><span>Role</span><select name="role" data-admin-user-role required><?php foreach (['cashier','inventory','branch_manager','owner'] as $businessRole): ?><option value="<?= e($businessRole) ?>"><?= e(role_label($businessRole)) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Branch</span><select name="branch_id" data-admin-user-branch required><option value="">Select an active branch</option><?php foreach ($branches as $activeBranch): ?><option value="<?= (int)$activeBranch['id'] ?>"><?= e($activeBranch['name']) ?></option><?php endforeach; ?></select><span data-admin-all-branches hidden>All Branches</span></label>
        <label class="field"><span>Temporary Password</span><input type="password" name="temporary_password" required minlength="12" maxlength="128" autocomplete="new-password"><small>At least 12 characters, including a letter and a number. The user must change it on first sign in.</small></label>
        <button class="btn btn-primary" type="submit">Create User</button>
    </form>
</dialog>
<dialog id="manageBranchesDialog" class="admin-profile-dialog" aria-labelledby="manageBranchesTitle">
    <div class="admin-profile-dialog-heading"><h2 id="manageBranchesTitle">Manage Branches</h2><button type="button" class="btn btn-secondary" data-close-account-dialog>Close</button></div>
    <p>Rename a branch without changing its code, assignments or records.</p>
    <div class="admin-profile-branch-list">
    <?php foreach ($managedBranches as $managedBranch): ?>
        <section class="admin-profile-branch">
            <div><strong><?= e($managedBranch['name']) ?></strong><span><?= e($managedBranch['code']) ?> &middot; <?= (int)$managedBranch['is_active'] ? 'Active' : 'Inactive' ?></span></div>
            <details><summary class="btn btn-secondary">Rename</summary>
                <form method="post" action="actions/system_admin_branch_rename.php" data-account-change-form>
                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
                    <input type="hidden" name="client_key" value="<?= e(account_change_key()) ?>">
                    <input type="hidden" name="branch_id" value="<?= (int)$managedBranch['id'] ?>">
                    <label class="field"><span>Branch name</span><input name="name" value="<?= e($managedBranch['name']) ?>" maxlength="<?= $branchNameLimit ?>" required></label>
                    <button class="btn btn-primary" type="submit">Save branch name</button>
                </form>
            </details>
        </section>
    <?php endforeach; ?>
    </div>
</dialog>
