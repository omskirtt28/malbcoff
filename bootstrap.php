<?php
$app = require __DIR__ . '/config/app.php';
date_default_timezone_set($app['timezone']);

if (!empty($app['is_production']) && empty($app['debug'])) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
} else {
    ini_set('display_errors', '1');
}
ini_set('log_errors', '1');
error_reporting(E_ALL);

$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
if (!$https && !empty($app['trust_cloudflare_proxy'])) {
    $https = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))) === 'https';
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', (string)max(1800, (int)($app['session_absolute_timeout'] ?? 43200)));

session_name($app['session_name']);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require __DIR__ . '/core/Database.php';
require __DIR__ . '/core/Csrf.php';
require __DIR__ . '/core/Auth.php';
require __DIR__ . '/core/Security.php';
require __DIR__ . '/core/helpers.php';

Security::configure($app);
Security::sendHeaders();
Security::guardAuthenticatedSession();

// Central release gates: forced password change and maintenance mode apply to
// every page/action so users cannot bypass them by typing a direct URL.
if (Auth::check()) {
    $scriptPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptName = basename($scriptPath);
    $isActionRequest = str_contains($scriptPath, '/actions/');

    if (Auth::requiresPasswordChange() && !in_array($scriptName, ['change-password.php', 'logout.php'], true)) {
        if ($isActionRequest) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Change your temporary password before continuing.']);
            exit;
        }
        redirect('change-password.php');
    }

    if (!empty($app['maintenance_mode']) && !Auth::isOwner() && !Auth::actorIsSystemAdmin() && !in_array($scriptName, ['maintenance.php', 'logout.php', 'change-password.php'], true)) {
        if ($isActionRequest) {
            http_response_code(503);
            header('Retry-After: 300');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'The system is temporarily under maintenance. Please try again shortly.']);
            exit;
        }
        redirect('maintenance.php');
    }
}
