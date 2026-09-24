<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#ffffff">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title><?= e($pageTitle) ?> | <?= e($app['name']) ?></title>
    <link rel="stylesheet" href="assets/css/app.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/app.css') ?>">
</head>
<body class="app-page page-<?= e($page ?? 'dashboard') ?> role-<?= e(Auth::user()['role'] ?? 'guest') ?>">
<div class="app-shell">
