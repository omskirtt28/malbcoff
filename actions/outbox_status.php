<?php
// Outbox heartbeat (Patch 6c-1). Answers "is this browser still signed in, as WHO, and what is the fresh token?".
// The device outbox calls it before every send cycle so a queued item is never sent under another user's session
// and a rotated CSRF token (re-login in another tab) never breaks the queue. No state is changed.
require __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'code' => 'auth', 'error' => 'Please sign in again.']);
    exit;
}

echo json_encode([
    'ok' => true,
    'user_id' => (int)(Auth::user()['id'] ?? 0),
    'branch_id' => Auth::branchId() ?: 0,
    'csrf' => Csrf::token(),
], JSON_UNESCAPED_SLASHES);
