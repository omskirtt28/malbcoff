<?php
require __DIR__ . '/bootstrap.php';
if (Auth::check()) {
    Security::audit('auth.logout', 'user', (int)(Auth::user()['id'] ?? 0));
}
Auth::logout();
redirect('login.php');
