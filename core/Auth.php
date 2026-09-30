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
            // Store the actual application-local login time (Asia/Manila) instead of
            // relying on the hosting MySQL server's system timezone.
            $loginAt = date('Y-m-d H:i:s');
            Database::query('UPDATE users SET last_login_at=? WHERE id=?', [$loginAt, (int)$user['id']]);
            $user['last_login_at'] = $loginAt;
            if (($user['role'] ?? '') === 'system_admin') {
                try {
                    Database::query("UPDATE security_impersonation_sessions SET ended_at=NOW(),ended_reason='new_login' WHERE system_admin_user_id=? AND ended_at IS NULL", [(int)$user['id']]);
                } catch (Throwable $ignore) {
                    // Migration may not be installed yet; login must continue safely.
                }
            }
        } catch (Throwable $e) {
            if (class_exists('Security')) Security::reportException($e, 'last_login_update');
        }

        session_regenerate_id(true);
        unset($user['password_hash']);
        unset($_SESSION['_impersonation']);
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

    public static function isSystemAdmin(): bool
    {
        return (self::user()['role'] ?? null) === 'system_admin';
    }

    public static function isImpersonating(): bool
    {
        $ctx = $_SESSION['_impersonation'] ?? null;
        return is_array($ctx)
            && !empty($ctx['actor']['id'])
            && (($ctx['actor']['role'] ?? null) === 'system_admin')
            && !empty($ctx['target_user_id']);
    }

    public static function actorUser(): ?array
    {
        if (self::isImpersonating()) {
            $actor = $_SESSION['_impersonation']['actor'] ?? null;
            return is_array($actor) ? $actor : null;
        }
        return self::user();
    }

    public static function actorId(): ?int
    {
        $id = self::actorUser()['id'] ?? null;
        return $id ? (int)$id : null;
    }

    public static function actorIsSystemAdmin(): bool
    {
        return (self::actorUser()['role'] ?? null) === 'system_admin';
    }

    public static function impersonationContext(): ?array
    {
        $ctx = $_SESSION['_impersonation'] ?? null;
        return is_array($ctx) ? $ctx : null;
    }

    public static function impersonationSessionId(): ?int
    {
        $id = self::impersonationContext()['db_session_id'] ?? null;
        return $id ? (int)$id : null;
    }

    public static function branchId(): ?int
    {
        $branchId = self::user()['branch_id'] ?? null;
        return $branchId ? (int)$branchId : null;
    }

    public static function requiresPasswordChange(): bool
    {
        if (self::isImpersonating()) return false;
        return self::check() && (int)(self::user()['must_change_password'] ?? 0) === 1;
    }

    public static function refreshCurrentUser(): void
    {
        if (!self::check()) return;
        $userId = (int)(self::user()['id'] ?? 0);
        if ($userId <= 0) return;
        $fresh = self::fetchUser($userId);
        if ($fresh) $_SESSION['user'] = $fresh;

        if (self::isImpersonating()) {
            $actorId = (int)(self::impersonationContext()['actor']['id'] ?? 0);
            $actor = $actorId > 0 ? self::fetchUser($actorId) : null;
            if ($actor) $_SESSION['_impersonation']['actor'] = $actor;
        }
    }

    public static function fetchUser(int $userId): ?array
    {
        if ($userId <= 0) return null;
        $row = Database::query(
            'SELECT u.id,u.branch_id,u.name,u.email,u.role,u.is_active,u.must_change_password,u.password_changed_at,u.last_login_at,b.name AS branch_name FROM users u LEFT JOIN branches b ON b.id=u.branch_id WHERE u.id=? LIMIT 1',
            [$userId]
        )->fetch();
        return $row ?: null;
    }

    public static function beginImpersonation(array $target, int $dbSessionId, string $sessionKey): void
    {
        if (!self::isSystemAdmin() || self::isImpersonating()) {
            throw new RuntimeException('Impersonation is not available for this session.');
        }
        if (!(int)($target['is_active'] ?? 0)) {
            throw new RuntimeException('Only active user accounts can be entered.');
        }
        if (($target['role'] ?? '') === 'system_admin') {
            throw new RuntimeException('System Admin accounts cannot be impersonated.');
        }

        $actor = self::user();
        if (!$actor || ($actor['role'] ?? '') !== 'system_admin') {
            throw new RuntimeException('System Admin authentication is required.');
        }

        unset($target['password_hash']);
        $_SESSION['_impersonation'] = [
            'actor' => $actor,
            'target_user_id' => (int)$target['id'],
            'db_session_id' => $dbSessionId,
            'session_key' => $sessionKey,
            'started_at' => time(),
        ];
        $_SESSION['user'] = $target;
        session_regenerate_id(true);
        Csrf::rotate();
        if (class_exists('Security')) Security::initializeAuthenticatedSession();
    }

    public static function endImpersonation(): ?array
    {
        if (!self::isImpersonating()) return null;
        $ctx = self::impersonationContext();
        $actor = $ctx['actor'] ?? null;
        if (!is_array($actor) || empty($actor['id'])) {
            self::logout();
            return $ctx;
        }
        $_SESSION['user'] = $actor;
        unset($_SESSION['_impersonation']);
        session_regenerate_id(true);
        Csrf::rotate();
        if (class_exists('Security')) Security::initializeAuthenticatedSession();
        return $ctx;
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
