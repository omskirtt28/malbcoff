<?php
require __DIR__ . '/bootstrap.php';
if (!Auth::check()) redirect('login.php');
if (Auth::isOwner() || Auth::actorIsSystemAdmin()) redirect('index.php');
if (empty($app['maintenance_mode'])) redirect('index.php?page=dashboard');
http_response_code(503);
header('Retry-After: 300');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Maintenance | <?= e($app['name']) ?></title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="shortcut icon" href="assets/favicon.svg">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= (int)@filemtime(__DIR__ . '/assets/css/app.css') ?>">
</head>
<body class="auth-page maintenance-page">
<div class="maintenance-shell">
    <div class="brand-mark">M</div>
    <span class="eyebrow">SYSTEM MAINTENANCE</span>
    <h1>We’ll be back shortly.</h1>
    <p><?= e($app['name']) ?> is temporarily unavailable while a system update is being applied. Please wait a few minutes before trying again.</p>
    <div class="maintenance-actions">
        <a class="btn btn-primary" href="maintenance.php">Check Again</a>
        <a class="btn btn-secondary" href="logout.php">Sign Out</a>
    </div>
    <small>Version <?= e($app['version'] ?? '1.0.0') ?></small>
</div>
</body>
</html>
