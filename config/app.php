<?php
$env = strtolower(trim((string)(getenv('APP_ENV') ?: 'local')));
$isProduction = $env === 'production';

$boolEnv = static function (string $key, bool $default = false): bool {
    $value = getenv($key);
    if ($value === false || $value === '') return $default;
    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
};

return [
    'name' => 'Malbcoff Trading',
    'subtitle' => 'POS & Inventory System',
    'timezone' => 'Asia/Manila',
    'environment' => $env,
    'is_production' => $isProduction,
    'debug' => $boolEnv('APP_DEBUG', !$isProduction),
    'session_name' => 'malbcoff_session',
    'session_idle_timeout' => 30 * 60,
    'session_absolute_timeout' => 12 * 60 * 60,
    'session_regenerate_interval' => 15 * 60,
    'session_user_refresh_interval' => 5 * 60,
    'trust_cloudflare_proxy' => $boolEnv('TRUST_CLOUDFLARE_PROXY', false),
    'hsts_enabled' => $boolEnv('APP_HSTS', $isProduction),
    'require_security_schema' => $boolEnv('REQUIRE_SECURITY_SCHEMA', $isProduction),
    'login_rate_limit' => [
        'window_seconds' => 15 * 60,
        'email_ip_threshold' => 6,
        'email_ip_lock_seconds' => 15 * 60,
        'email_threshold' => 15,
        'email_lock_seconds' => 30 * 60,
    ],
];
