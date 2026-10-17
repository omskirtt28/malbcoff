<?php
// A queued stock action or offline sale (Patch 6c-2) was refused for good ("Needs action") → tell the owner (Patch 6c-1).
//   op=report   record it once (idempotent on client_key — safe to send again)
//   op=discard  the staff member discarded it on the device (the owner still sees it, marked as discarded)
// The user and branch come from the session; the body only carries text for the owner to read.
// This endpoint never touches stock.
require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../core/Idempotency.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'code' => 'auth', 'error' => 'Please sign in again.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Invalid request method.']);
    exit;
}
$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) $payload = $_POST;
if (!Csrf::verify(is_string($payload['_csrf'] ?? null) ? $payload['_csrf'] : null)) {
    Idempotency::csrfFailure();
}
// Only assigned branch staff have an outbox (the owner and System Admin never queue stock actions).
if (Auth::isImpersonating() || Auth::isSystemAdmin() || Auth::isOwner() || (Auth::branchId() ?: 0) <= 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not available for this account.']);
    exit;
}

$clean = static function (mixed $value, int $max): string {
    $text = trim((string)$value);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? $text;
    return mb_substr($text, 0, $max);
};

$op = (string)($payload['op'] ?? 'report');
$clientKey = strtolower(trim((string)($payload['client_key'] ?? '')));
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $clientKey) || !in_array($op, ['report', 'discard'], true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
}

// Without the table (migration not imported yet) the staff device must not fail: nothing to store, answer "ok".
if (!outbox_reports_ready()) {
    echo json_encode(['ok' => true, 'stored' => false]);
    exit;
}

$userId = (int)(Auth::user()['id'] ?? 0);
$branchId = Auth::branchId() ?: null;

try {
    if ($op === 'discard') {
        Database::query('UPDATE outbox_reports SET staff_discarded=1 WHERE client_key=? AND user_id=?', [$clientKey, $userId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    $type = (string)($payload['action_type'] ?? '');
    if (!in_array($type, ['stock_in', 'transfer_forward', 'transfer_receive', 'sale'], true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
        exit;
    }
    $summary = $clean($payload['summary'] ?? '', 255);
    // A refused sale lists its items, amounts, payment and device time (needs the wider column from P3_005).
    $details = $clean($payload['details'] ?? '', outbox_reports_details_max());
    $reason = $clean($payload['reason'] ?? '', 500);
    if ($summary === '') $summary = outbox_action_label($type);
    if ($reason === '') $reason = 'The server refused this action.';
    $queuedAt = null;
    $queuedMs = filter_var($payload['queued_at'] ?? null, FILTER_VALIDATE_INT);
    if ($queuedMs !== false && $queuedMs > 0) $queuedAt = date('Y-m-d H:i:s', (int)floor($queuedMs / 1000));

    // INSERT IGNORE on the UNIQUE client_key: a second report of the same item changes nothing.
    Database::query(
        'INSERT IGNORE INTO outbox_reports (client_key,user_id,branch_id,action_type,summary,details,reason,queued_at) VALUES (?,?,?,?,?,?,?,?)',
        [$clientKey, $userId, $branchId, $type, $summary, $details !== '' ? $details : null, $reason, $queuedAt]
    );
    Security::audit('outbox.needs_action', 'outbox', $clientKey, ['action_type' => $type, 'branch_id' => $branchId]);
    echo json_encode(['ok' => true, 'stored' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to save this report right now.']);
}
