<?php
require __DIR__ . '/bootstrap.php';
if (Auth::check()) {
    if (Auth::isImpersonating()) {
        Security::audit('impersonation.ended', 'user', (int)(Auth::user()['id'] ?? 0), ['reason' => 'logout']);
        Security::closeImpersonationSession('logout');
    }
    Security::audit('auth.logout', 'user', (int)(Auth::user()['id'] ?? 0));
}
Auth::logout();
redirect(app_url('login'));
