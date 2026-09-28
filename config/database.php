<?php
$appOverridePath = __DIR__ . '/app.local.php';
$appOverride = is_file($appOverridePath) ? (require $appOverridePath) : [];
if (!is_array($appOverride)) $appOverride = [];
$envValue = getenv('APP_ENV');
$env = strtolower(trim((string)(($envValue !== false && trim((string)$envValue) !== '') ? $envValue : ($appOverride['environment'] ?? 'local'))));
$isProduction = $env === 'production';
$localOverride = __DIR__ . '/database.local.php';
$override = is_file($localOverride) ? (require $localOverride) : [];
if (!is_array($override)) $override = [];

$get = static function (string $key, mixed $fallback = '') use ($override): mixed {
    $value = getenv($key);
    if ($value !== false && $value !== '') return $value;
    $map = [
        'DB_HOST' => 'host',
        'DB_PORT' => 'port',
        'DB_NAME' => 'name',
        'DB_USER' => 'user',
        'DB_PASS' => 'pass',
        'DB_CHARSET' => 'charset',
    ];
    $overrideKey = $map[$key] ?? null;
    if ($overrideKey !== null && array_key_exists($overrideKey, $override)) return $override[$overrideKey];
    return $fallback;
};

$config = [
    'host' => (string)$get('DB_HOST', '127.0.0.1'),
    'port' => (string)$get('DB_PORT', '3306'),
    'name' => (string)$get('DB_NAME', 'malbcoff_pos'),
    'user' => (string)$get('DB_USER', $isProduction ? '' : 'root'),
    'pass' => (string)$get('DB_PASS', ''),
    'charset' => (string)$get('DB_CHARSET', 'utf8mb4'),
];

if ($isProduction) {
    if ($config['host'] === '' || $config['name'] === '' || $config['user'] === '' || $config['pass'] === '') {
        throw new RuntimeException('Production database credentials are not configured.');
    }
}

return $config;
