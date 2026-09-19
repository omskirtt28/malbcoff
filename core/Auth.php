<?php
final class Auth
{
    public static function attempt(string $email, string $password): bool
    {
        $user = Database::query(
            'SELECT u.*, b.name AS branch_name FROM users u LEFT JOIN branches b ON b.id = u.branch_id WHERE u.email = ? AND u.is_active = 1 LIMIT 1',
            [$email]
        )->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        unset($user['password_hash']);
        $_SESSION['user'] = $user;
        return true;
    }

    public static function check(): bool
    {
        return isset($_SESSION['user']['id']);
    }

    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function isOwner(): bool
    {
        return (self::user()['role'] ?? null) === 'owner';
    }

    public static function branchId(): ?int
    {
        $branchId = self::user()['branch_id'] ?? null;
        return $branchId ? (int)$branchId : null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}
