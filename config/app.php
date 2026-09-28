<?php
$localOverride = __DIR__ . '/app.local.php';
$override = is_file($localOverride) ? (require $localOverride) : [];
if (!is_array($override)) $override = [];

$stringSetting = static function (string $envKey, string $overrideKey, string $default = '') use ($override): string {
    $value = getenv($envKey);
    if ($value !== false && trim((string)$value) !== '') return trim((string)$value);
    if (array_key_exists($overrideKey, $override) && trim((string)$override[$overrideKey]) !== '') return trim((string)$override[$overrideKey]);
    return $default;
};
$boolSetting = static function (string $envKey, string $overrideKey, bool $default = false) use ($override): bool {
    $value = getenv($envKey);
    if ($value !== false && $value !== '') return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    if (array_key_exists($overrideKey, $override)) return filter_var($override[$overrideKey], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    return $default;
};
$intSetting = static function (string $envKey, string $overrideKey, int $default) use ($override): int {
    $value = getenv($envKey);
    if ($value !== false && trim((string)$value) !== '') {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        return $parsed === false ? $default : (int)$parsed;
    }
    if (array_key_exists($overrideKey, $override)) {
        $parsed = filter_var($override[$overrideKey], FILTER_VALIDATE_INT);
        return $parsed === false ? $default : (int)$parsed;
    }
    return $default;
};

$env = strtolower($stringSetting('APP_ENV', 'environment', 'local'));
$isProduction = $env === 'production';

return [
    'name' => 'Malbcoff Trading',
    'subtitle' => 'POS & Inventory System',
    'version' => $stringSetting('APP_VERSION', 'version', '1.0.0'),
    'timezone' => 'Asia/Manila',
    'environment' => $env,
    'is_production' => $isProduction,
    'debug' => $boolSetting('APP_DEBUG', 'debug', !$isProduction),
    'session_name' => 'malbcoff_session',
    'session_idle_timeout' => 30 * 60,
    'session_absolute_timeout' => 12 * 60 * 60,
    'session_regenerate_interval' => 15 * 60,
    'session_user_refresh_interval' => 5 * 60,
    'trust_cloudflare_proxy' => $boolSetting('TRUST_CLOUDFLARE_PROXY', 'trust_cloudflare_proxy', false),
    'hsts_enabled' => $boolSetting('APP_HSTS', 'hsts_enabled', false),
    'hsts_include_subdomains' => $boolSetting('APP_HSTS_INCLUDE_SUBDOMAINS', 'hsts_include_subdomains', false),
    'maintenance_mode' => $boolSetting('APP_MAINTENANCE', 'maintenance_mode', false),
    'audit_retention_days' => max(30, $intSetting('AUDIT_LOG_RETENTION_DAYS', 'audit_retention_days', 180)),
    'require_security_schema' => $boolSetting('REQUIRE_SECURITY_SCHEMA', 'require_security_schema', $isProduction),
    'login_rate_limit' => [
        'window_seconds' => 15 * 60,
        'email_ip_threshold' => 6,
        'email_ip_lock_seconds' => 15 * 60,
        'email_threshold' => 15,
        'email_lock_seconds' => 30 * 60,
    ],
];
