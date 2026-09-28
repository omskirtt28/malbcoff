<?php
require __DIR__ . '/../bootstrap.php';

if (!Auth::check()) redirect('../login.php');
if (!Auth::isOwner()) {
    Security::audit('auth.user_management_denied', 'user', null, ['role' => Auth::user()['role'] ?? '']);
    flash('error', 'Only the Owner can manage user accounts.');
    redirect('../index.php?page=users');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('../index.php?page=users');
if (!Csrf::verify($_POST['_csrf'] ?? null)) {
    flash('error', 'Your session expired. Please try again.');
    redirect('../index.php?page=users');
}

$action = strtolower(trim((string)($_POST['action'] ?? '')));
$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$allowedRoles = ['owner','branch_manager','inventory','cashier'];

try {
    if (!in_array($action, ['create','update','toggle','reset_password'], true)) {
        throw new RuntimeException('Invalid user management request.');
    }

    if ($action === 'create') {
        $name = clean_user_name($_POST['name'] ?? '');
        $email = clean_user_email($_POST['email'] ?? '');
        $role = clean_user_role($_POST['role'] ?? '', $allowedRoles);
        $branchId = resolve_user_branch($role, $_POST['branch_id'] ?? null);
        $password = (string)($_POST['temporary_password'] ?? '');
        validate_new_password($password);

        if ($name === '') throw new RuntimeException('Name is required.');
        if ($email === '') throw new RuntimeException('Enter a valid email address.');
        if (Security::isProduction() && str_ends_with($email, '@malbcoff.local')) throw new RuntimeException('Use a real production email address.');
        if (Database::query('SELECT 1 FROM users WHERE LOWER(email)=LOWER(?) LIMIT 1', [$email])->fetchColumn()) {
            throw new RuntimeException('That email address is already assigned to another user.');
        }

        Database::query(
            'INSERT INTO users (branch_id,name,email,password_hash,role,is_active,must_change_password,password_changed_at) VALUES (?,?,?,?,?,1,1,NULL)',
            [$branchId,$name,$email,password_hash($password, PASSWORD_DEFAULT),$role]
        );
        $newId = (int)Database::connection()->lastInsertId();
        Security::audit('user.created', 'user', $newId, ['role' => $role, 'branch_id' => $branchId]);
        flash('success', 'User created. They must change the temporary password on first sign in.');
    }

    if ($action === 'update') {
        $target = require_managed_user($id);
        $name = clean_user_name($_POST['name'] ?? '');
        $email = clean_user_email($_POST['email'] ?? '');
        $role = clean_user_role($_POST['role'] ?? '', $allowedRoles);
        $branchId = resolve_user_branch($role, $_POST['branch_id'] ?? null);

        if ($name === '') throw new RuntimeException('Name is required.');
        if ($email === '') throw new RuntimeException('Enter a valid email address.');
        if (Security::isProduction() && str_ends_with($email, '@malbcoff.local')) throw new RuntimeException('Use a real production email address.');
        if ((int)$target['id'] === (int)(Auth::user()['id'] ?? 0) && $role !== 'owner') {
            throw new RuntimeException('You cannot remove your own Owner access.');
        }
        if (Database::query('SELECT 1 FROM users WHERE LOWER(email)=LOWER(?) AND id<>? LIMIT 1', [$email,$id])->fetchColumn()) {
            throw new RuntimeException('That email address is already assigned to another user.');
        }

        Database::query('UPDATE users SET name=?,email=?,role=?,branch_id=? WHERE id=?', [$name,$email,$role,$branchId,$id]);
        Security::audit('user.updated', 'user', $id, ['role' => $role, 'branch_id' => $branchId]);
        if ($id === (int)(Auth::user()['id'] ?? 0)) Auth::refreshCurrentUser();
        flash('success', 'User account updated.');
    }

    if ($action === 'toggle') {
        $target = require_managed_user($id);
        $current = (int)$target['is_active'] === 1;
        $next = !$current;
        if ($id === (int)(Auth::user()['id'] ?? 0) && !$next) {
            throw new RuntimeException('You cannot deactivate your own account.');
        }
        if (($target['role'] ?? '') === 'owner' && !$next) {
            $activeOwners = (int)Database::query("SELECT COUNT(*) FROM users WHERE role='owner' AND is_active=1")->fetchColumn();
            if ($activeOwners <= 1) throw new RuntimeException('At least one active Owner account is required.');
        }
        Database::query('UPDATE users SET is_active=? WHERE id=?', [$next ? 1 : 0,$id]);
        Security::audit($next ? 'user.activated' : 'user.deactivated', 'user', $id);
        flash('success', $next ? 'User account activated.' : 'User account deactivated.');
    }

    if ($action === 'reset_password') {
        $target = require_managed_user($id);
        $password = (string)($_POST['temporary_password'] ?? '');
        validate_new_password($password);
        Database::query(
            'UPDATE users SET password_hash=?,must_change_password=1,password_changed_at=NULL WHERE id=?',
            [password_hash($password, PASSWORD_DEFAULT),$id]
        );
        Security::audit('user.password_reset', 'user', $id);
        flash('success', 'Temporary password set. The user must change it on next sign in.');
    }
} catch (Throwable $e) {
    flash('error', safe_exception_message($e, 'Unable to update the user account right now. Please try again.'));
}

redirect('../index.php?page=users');

function clean_user_name(mixed $value): string
{
    $value = trim((string)$value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return mb_substr($value, 0, 120);
}

function clean_user_email(mixed $value): string
{
    $email = Security::normalizeEmail((string)$value);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 160) return '';
    return $email;
}

function clean_user_role(mixed $value, array $allowed): string
{
    $role = strtolower(trim((string)$value));
    if (!in_array($role, $allowed, true)) throw new RuntimeException('Select a valid role.');
    return $role;
}

function resolve_user_branch(string $role, mixed $branchValue): ?int
{
    if ($role === 'owner') return null;
    $branchId = filter_var($branchValue, FILTER_VALIDATE_INT) ?: 0;
    if ($branchId <= 0) throw new RuntimeException('Select the branch assigned to this user.');
    $exists = Database::query('SELECT 1 FROM branches WHERE id=? AND is_active=1 LIMIT 1', [$branchId])->fetchColumn();
    if (!$exists) throw new RuntimeException('Select an active branch.');
    return $branchId;
}

function require_managed_user(int $id): array
{
    if ($id <= 0) throw new RuntimeException('User account not found.');
    $row = Database::query('SELECT id,name,email,role,branch_id,is_active FROM users WHERE id=? LIMIT 1', [$id])->fetch();
    if (!$row) throw new RuntimeException('User account not found.');
    if (($row['role'] ?? '') === 'system_admin') throw new RuntimeException('System Admin accounts are managed separately.');
    return $row;
}

function validate_new_password(string $password): void
{
    $length = strlen($password);
    if ($length < 12) throw new RuntimeException('Password must be at least 12 characters.');
    if ($length > 128) throw new RuntimeException('Password is too long.');
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        throw new RuntimeException('Password must contain at least one letter and one number.');
    }
}
