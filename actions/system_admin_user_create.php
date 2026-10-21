<?php
require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../core/UserManagement.php';
require_once __DIR__ . '/../core/AccountChanges.php';

// Whitelist non-secret intent fields; no password-derived hash is persisted here.
$intent = array_intersect_key($_POST, array_flip(['name','email','role','branch_id']));
run_account_change('admin_user_create', $intent, static function (array $actor): array {
    $name = clean_user_name($_POST['name'] ?? '');
    $email = clean_user_email($_POST['email'] ?? '');
    $role = clean_user_role($_POST['role'] ?? '', ['owner','branch_manager','inventory','cashier']);
    $branchId = resolve_user_branch($role, $_POST['branch_id'] ?? null);
    $password = (string)($_POST['temporary_password'] ?? '');
    validate_new_password($password);
    if ($name === '') throw new RuntimeException('Name is required.');
    if ($email === '') throw new RuntimeException('Enter a valid email address.');
    if (Security::isProduction() && str_ends_with($email, '@malbcoff.local')) throw new RuntimeException('Use a real production email address.');
    if ($branchId !== null && !Database::query('SELECT id FROM branches WHERE id=? AND is_active=1 FOR UPDATE', [$branchId])->fetchColumn()) {
        throw new RuntimeException('Select an active branch.');
    }
    if (Database::query('SELECT 1 FROM users WHERE LOWER(email)=LOWER(?) LIMIT 1', [$email])->fetchColumn()) {
        throw new RuntimeException('That email address is already assigned to another user.');
    }
    Database::query('INSERT INTO users (branch_id,name,email,password_hash,role,is_active,must_change_password,password_changed_at) VALUES (?,?,?,?,?,1,1,NULL)',
        [$branchId,$name,$email,password_hash($password, PASSWORD_DEFAULT),$role]);
    $id = (int)Database::connection()->lastInsertId();
    Security::audit('user.created', 'user', $id, ['role'=>$role, 'branch_id'=>$branchId]);
    return ['ok'=>true, 'message'=>'User created. They must change the temporary password on first sign in.'];
});
