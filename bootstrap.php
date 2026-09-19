<?php
$app = require __DIR__ . '/config/app.php';
date_default_timezone_set($app['timezone']);
session_name($app['session_name']);
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

require __DIR__ . '/core/Database.php';
require __DIR__ . '/core/Csrf.php';
require __DIR__ . '/core/Auth.php';
require __DIR__ . '/core/helpers.php';
