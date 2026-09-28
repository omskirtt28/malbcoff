<?php
final class Auth
{
    public static function attempt(string $email, string $password): bool
    {
        $email = strtolower(trim($email));
        $user = Database::query(
            'SELECT u.*, b.name AS branch_name FROM users u LEFT JOIN branches b ON b.id = u.branch_id WHERE LOWER(u.email) = ? AND u.is_active = 1 LIMIT 1',
            [$email]
        )->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        if (class_exists('Security') && Security::isProduction()) {
            $emailValue = strtolower((string)($user['email'] ?? ''));
            $knownDevelopmentHashes = [
                '$2y$12$1jkavOcYHgeXt5gjuogrZepegN7zY3DuJwasrDZ49z5j.QQhcxvTu',
                '$2y$12$PyncbYymfzFxunhB05r4v.r7Xk797DaH2tD.LxVTSaNmyeO5q8czW',
            ];
            if (str_ends_with($emailValue, '@malbcoff.local') || in_array((string)$user['password_hash'], $knownDevelopmentHashes, true)) {
                if (class_exists('Security')) Security::audit('auth.production_demo_account_blocked', 'user', (int)$user['id']);
                return false;
            }
        }

        if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
            try {
                Database::query('UPDATE users SET password_hash=?,password_changed_at=COALESCE(password_changed_at,NOW()) WHERE id=?', [password_hash($password, PASSWORD_DEFAULT), (int)$user['id']]);
            } catch (Throwable $e) {
                if (class_exists('Security')) Security::reportException($e, 'password_rehash');
            }
        }

        try {
            Database::query('UPDATE users SET last_login_at=NOW() WHERE id=?', [(int)$user['id']]);
            $user['last_login_at'] = date('Y-m-d H:i:s');
        } catch (Throwable $e) {
            if (class_exists('Security')) Security::reportException($e, 'last_login_update');
        }

        session_regenerate_id(true);
        unset($user['password_hash']);
        $_SESSION['user'] = $user;
        Csrf::rotate();
        if (class_exists('Security')) Security::initializeAuthenticatedSession();
        return true;
    }

    public static function check(): bool
    {
        return isset($_SESSION['user']['id']) && (int)$_SESSION['user']['id'] > 0;
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


    public static function requiresPasswordChange(): bool
    {
        return self::check() && (int)(self::user()['must_change_password'] ?? 0) === 1;
    }

    public static function refreshCurrentUser(): void
    {
        if (!self::check()) return;
        $userId = (int)(self::user()['id'] ?? 0);
        if ($userId <= 0) return;
        $fresh = Database::query(
            'SELECT u.id,u.branch_id,u.name,u.email,u.role,u.is_active,u.must_change_password,u.password_changed_at,u.last_login_at,b.name AS branch_name FROM users u LEFT JOIN branches b ON b.id=u.branch_id WHERE u.id=? LIMIT 1',
            [$userId]
        )->fetch();
        if ($fresh) $_SESSION['user'] = $fresh;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool)($params['secure'] ?? false),
                'httponly' => true,
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    }
}
