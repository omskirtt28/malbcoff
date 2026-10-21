<?php
// Shared validation used by Owner management and System Admin account creation.
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
