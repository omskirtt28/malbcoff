<?php
final class Security
{
    private static ?array $app = null;
    private static ?bool $securitySchemaReady = null;
    private static ?bool $impersonationAuditReady = null;
    private static ?string $requestId = null;

    public static function configure(array $app): void
    {
        self::$app = $app;
        if (self::$requestId === null) {
            try {
                self::$requestId = bin2hex(random_bytes(8));
            } catch (Throwable $e) {
                self::$requestId = substr(hash('sha256', microtime(true) . '|' . getmypid()), 0, 16);
            }
        }
    }

    private static function app(): array
    {
        return self::$app ?? [];
    }

    public static function requestId(): string
    {
        return self::$requestId ?? 'unknown';
    }

    public static function isProduction(): bool
    {
        return !empty(self::app()['is_production']);
    }

    public static function isHttps(): bool
    {
        $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') return true;
        if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') return true;

        $app = self::app();
        if (!empty($app['trust_cloudflare_proxy'])) {
            $proto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
            if ($proto === 'https') return true;
        }
        return false;
    }

    public static function sendHeaders(): void
    {
        if (headers_sent()) return;
        header('X-Request-ID: ' . self::requestId());
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
        header('X-Permitted-Cross-Domain-Policies: none');
        header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; script-src 'self' 'unsafe-inline' 'wasm-unsafe-eval' blob:; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self' data: blob:; media-src 'self' blob:; worker-src 'self' blob:");
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        $app = self::app();
        if (!empty($app['is_production']) && !empty($app['hsts_enabled']) && self::isHttps()) {
            $hsts = 'Strict-Transport-Security: max-age=31536000';
            if (!empty($app['hsts_include_subdomains'])) $hsts .= '; includeSubDomains';
            header($hsts);
        }
    }

    public static function clientIp(): string
    {
        $app = self::app();
        if (!empty($app['trust_cloudflare_proxy'])) {
            $candidate = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) return $candidate;
        }
        $candidate = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : '0.0.0.0';
    }

    public static function userAgent(): string
    {
        $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
        return mb_substr($ua, 0, 255);
    }

    public static function initializeAuthenticatedSession(): void
    {
        $now = time();
        $_SESSION['_security'] = [
            'created_at' => $now,
            'last_activity' => $now,
            'last_regenerated' => $now,
            'last_user_refresh' => $now,
            'user_agent_hash' => hash('sha256', self::userAgent()),
        ];
    }

    public static function guardAuthenticatedSession(): void
    {
        if (!Auth::check()) return;

        $app = self::app();
        $now = time();
        $security = $_SESSION['_security'] ?? [];
        if (!is_array($security)) $security = [];

        $createdAt = (int)($security['created_at'] ?? $now);
        $lastActivity = (int)($security['last_activity'] ?? $now);
        $lastRegenerated = (int)($security['last_regenerated'] ?? $now);
        $lastUserRefresh = (int)($security['last_user_refresh'] ?? 0);
        $uaHash = (string)($security['user_agent_hash'] ?? '');
        $currentUaHash = hash('sha256', self::userAgent());

        $idleTimeout = max(300, (int)($app['session_idle_timeout'] ?? 1800));
        $absoluteTimeout = max($idleTimeout, (int)($app['session_absolute_timeout'] ?? 43200));
        $regenInterval = max(300, (int)($app['session_regenerate_interval'] ?? 900));
        $refreshInterval = max(60, (int)($app['session_user_refresh_interval'] ?? 300));

        if (($now - $lastActivity) > $idleTimeout || ($now - $createdAt) > $absoluteTimeout) {
            self::audit('auth.session_expired', 'session', null, ['reason' => ($now - $lastActivity) > $idleTimeout ? 'idle_timeout' : 'absolute_timeout']);
            self::closeImpersonationSession('session_expired');
            Auth::logout();
            return;
        }

        if ($uaHash !== '' && !hash_equals($uaHash, $currentUaHash)) {
            self::audit('auth.session_rejected', 'session', null, ['reason' => 'user_agent_changed']);
            self::closeImpersonationSession('user_agent_changed');
            Auth::logout();
            return;
        }

        if (($now - $lastUserRefresh) >= $refreshInterval) {
            try {
                $userId = (int)(Auth::user()['id'] ?? 0);
                $fresh = $userId > 0 ? Auth::fetchUser($userId) : null;
                if (!$fresh || !(int)$fresh['is_active']) {
                    self::audit('auth.session_revoked', 'user', $userId ?: null, ['reason' => 'inactive_or_missing_effective_user']);
                    self::closeImpersonationSession('effective_user_revoked');
                    Auth::logout();
                    return;
                }
                $_SESSION['user'] = $fresh;

                if (Auth::isImpersonating()) {
                    $actorId = (int)(Auth::impersonationContext()['actor']['id'] ?? 0);
                    $actor = $actorId > 0 ? Auth::fetchUser($actorId) : null;
                    if (!$actor || !(int)$actor['is_active'] || ($actor['role'] ?? '') !== 'system_admin') {
                        self::audit('auth.session_revoked', 'user', $actorId ?: null, ['reason' => 'invalid_system_admin_actor']);
                        self::closeImpersonationSession('system_admin_revoked');
                        Auth::logout();
                        return;
                    }
                    $_SESSION['_impersonation']['actor'] = $actor;
                }

                $security['last_user_refresh'] = $now;
            } catch (Throwable $e) {
                self::reportException($e, 'session_user_refresh');
            }
        }

        if (($now - $lastRegenerated) >= $regenInterval) {
            session_regenerate_id(true);
            $security['last_regenerated'] = $now;
        }

        $security['created_at'] = $createdAt;
        $security['last_activity'] = $now;
        $security['user_agent_hash'] = $currentUaHash;
        if (!isset($security['last_user_refresh'])) $security['last_user_refresh'] = $now;
        if (!isset($security['last_regenerated'])) $security['last_regenerated'] = $now;
        $_SESSION['_security'] = $security;
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private static function tableExists(string $table): bool
    {
        try {
            return (bool)Database::query('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1', [$table])->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function securitySchemaReady(): bool
    {
        if (self::$securitySchemaReady !== null) return self::$securitySchemaReady;
        self::$securitySchemaReady = self::tableExists('security_login_throttles') && self::tableExists('security_audit_logs');
        return self::$securitySchemaReady;
    }

    private static function impersonationAuditReady(): bool
    {
        if (self::$impersonationAuditReady !== null) return self::$impersonationAuditReady;
        if (!self::securitySchemaReady()) return self::$impersonationAuditReady = false;
        try {
            $count = (int)Database::query(
                "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='security_audit_logs' AND column_name IN ('actor_user_id','effective_user_id','impersonation_session_id')"
            )->fetchColumn();
            self::$impersonationAuditReady = $count === 3;
        } catch (Throwable $e) {
            self::$impersonationAuditReady = false;
        }
        return self::$impersonationAuditReady;
    }

    private static function throttleKey(string $scopeType, string $identifier): string
    {
        return hash('sha256', $scopeType . '|' . $identifier);
    }

    public static function loginRateStatus(string $email): array
    {
        $app = self::app();
        if (!self::securitySchemaReady()) {
            if (!empty($app['require_security_schema'])) {
                return ['allowed' => false, 'retry_after' => 60, 'setup_required' => true];
            }
            return ['allowed' => true, 'retry_after' => 0, 'setup_required' => false];
        }

        $email = self::normalizeEmail($email);
        $ip = self::clientIp();
        $keys = [
            self::throttleKey('email_ip', $email . '|' . $ip),
            self::throttleKey('email', $email),
        ];
        $marks = implode(',', array_fill(0, count($keys), '?'));
        try {
            $rows = Database::query(
                "SELECT locked_until FROM security_login_throttles WHERE scope_key IN ($marks) AND locked_until IS NOT NULL AND locked_until>NOW()",
                $keys
            )->fetchAll();
            $retryAfter = 0;
            foreach ($rows as $row) {
                $lockedUntil = strtotime((string)$row['locked_until']);
                if ($lockedUntil !== false) $retryAfter = max($retryAfter, $lockedUntil - time());
            }
            return ['allowed' => $retryAfter <= 0, 'retry_after' => max(0, $retryAfter), 'setup_required' => false];
        } catch (Throwable $e) {
            self::reportException($e, 'login_rate_status');
            return ['allowed' => empty($app['require_security_schema']), 'retry_after' => 60, 'setup_required' => !empty($app['require_security_schema'])];
        }
    }

    public static function recordLoginFailure(string $email): void
    {
        if (!self::securitySchemaReady()) return;
        $app = self::app();
        $limits = $app['login_rate_limit'] ?? [];
        $window = max(60, (int)($limits['window_seconds'] ?? 900));
        $email = self::normalizeEmail($email);
        $ip = self::clientIp();

        $scopes = [
            ['type' => 'email_ip', 'identifier' => $email . '|' . $ip, 'threshold' => max(2, (int)($limits['email_ip_threshold'] ?? 6)), 'lock' => max(60, (int)($limits['email_ip_lock_seconds'] ?? 900))],
            ['type' => 'email', 'identifier' => $email, 'threshold' => max(5, (int)($limits['email_threshold'] ?? 15)), 'lock' => max(60, (int)($limits['email_lock_seconds'] ?? 1800))],
        ];

        foreach ($scopes as $scope) {
            $key = self::throttleKey($scope['type'], $scope['identifier']);
            try {
                $pdo = Database::connection();
                $pdo->beginTransaction();
                $row = Database::query('SELECT * FROM security_login_throttles WHERE scope_key=? LIMIT 1 FOR UPDATE', [$key])->fetch();
                $now = time();
                $count = 1;
                $windowStartedAt = date('Y-m-d H:i:s', $now);
                if ($row) {
                    $started = strtotime((string)$row['window_started_at']) ?: 0;
                    if ($started > 0 && ($now - $started) <= $window) {
                        $count = (int)$row['failure_count'] + 1;
                        $windowStartedAt = (string)$row['window_started_at'];
                    }
                }
                $lockedUntil = $count >= (int)$scope['threshold'] ? date('Y-m-d H:i:s', $now + (int)$scope['lock']) : null;
                Database::query(
                    'INSERT INTO security_login_throttles (scope_key,scope_type,failure_count,window_started_at,last_failed_at,locked_until) VALUES (?,?,?,?,NOW(),?) '
                    . 'ON DUPLICATE KEY UPDATE scope_type=VALUES(scope_type),failure_count=VALUES(failure_count),window_started_at=VALUES(window_started_at),last_failed_at=NOW(),locked_until=VALUES(locked_until)',
                    [$key, $scope['type'], $count, $windowStartedAt, $lockedUntil]
                );
                $pdo->commit();
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                self::reportException($e, 'record_login_failure');
            }
        }

        try {
            Database::query("DELETE FROM security_login_throttles WHERE updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        } catch (Throwable $e) {
        }
    }

    public static function recordLoginSuccess(string $email): void
    {
        if (!self::securitySchemaReady()) return;
        $email = self::normalizeEmail($email);
        $ip = self::clientIp();
        $keys = [
            self::throttleKey('email_ip', $email . '|' . $ip),
            self::throttleKey('email', $email),
        ];
        try {
            Database::query('DELETE FROM security_login_throttles WHERE scope_key IN (?,?)', $keys);
            self::runHousekeeping();
        } catch (Throwable $e) {
            self::reportException($e, 'record_login_success');
        }
    }

    public static function runHousekeeping(): void
    {
        if (!self::securitySchemaReady()) return;
        $days = max(30, (int)(self::app()['audit_retention_days'] ?? 180));
        $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));
        try {
            Database::query('DELETE FROM security_audit_logs WHERE created_at < ? LIMIT 5000', [$cutoff]);
        } catch (Throwable $e) {
            self::reportException($e, 'security_audit_housekeeping');
        }
    }

    public static function audit(string $eventType, ?string $entityType = null, int|string|null $entityId = null, array $metadata = []): void
    {
        if (!self::securitySchemaReady()) return;
        try {
            $effective = Auth::user();
            $actor = Auth::actorUser();
            $metadataJson = $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
            if (is_string($metadataJson) && strlen($metadataJson) > 8000) $metadataJson = substr($metadataJson, 0, 8000);

            $base = [
                self::requestId(),
                isset($effective['id']) ? (int)$effective['id'] : null,
                isset($effective['branch_id']) && $effective['branch_id'] !== null ? (int)$effective['branch_id'] : null,
                mb_substr($eventType, 0, 80),
                $entityType !== null ? mb_substr($entityType, 0, 80) : null,
                $entityId !== null ? mb_substr((string)$entityId, 0, 120) : null,
                self::clientIp(),
                self::userAgent(),
                $metadataJson,
            ];

            // Store an explicit Asia/Manila audit timestamp instead of relying on
            // the hosting database server's CURRENT_TIMESTAMP timezone. This makes
            // login/audit history deterministic and keeps System Admin monitoring
            // aligned with the actual Philippine application clock.
            $auditCreatedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');

            if (self::impersonationAuditReady()) {
                Database::query(
                    'INSERT INTO security_audit_logs (request_id,user_id,branch_id,event_type,entity_type,entity_id,ip_address,user_agent,metadata_json,actor_user_id,effective_user_id,impersonation_session_id,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    array_merge($base, [
                        isset($actor['id']) ? (int)$actor['id'] : null,
                        isset($effective['id']) ? (int)$effective['id'] : null,
                        Auth::impersonationSessionId(),
                        $auditCreatedAt,
                    ])
                );
            } else {
                Database::query(
                    'INSERT INTO security_audit_logs (request_id,user_id,branch_id,event_type,entity_type,entity_id,ip_address,user_agent,metadata_json,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                    array_merge($base, [$auditCreatedAt])
                );
            }
        } catch (Throwable $e) {
            error_log('[Malbcoff security audit failure][' . self::requestId() . '] ' . $e->getMessage());
        }
    }

    public static function closeImpersonationSession(string $reason): void
    {
        if (!Auth::isImpersonating()) return;
        $ctx = Auth::impersonationContext();
        $id = (int)($ctx['db_session_id'] ?? 0);
        if ($id <= 0 || !self::tableExists('security_impersonation_sessions')) return;
        try {
            Database::query(
                'UPDATE security_impersonation_sessions SET ended_at=COALESCE(ended_at,NOW()),ended_reason=COALESCE(ended_reason,?) WHERE id=?',
                [mb_substr($reason, 0, 40), $id]
            );
        } catch (Throwable $e) {
            self::reportException($e, 'close_impersonation_session');
        }
    }

    public static function reportException(Throwable $e, string $context): void
    {
        error_log('[Malbcoff][' . self::requestId() . '][' . $context . '] ' . get_class($e) . ': ' . $e->getMessage());
    }
}
