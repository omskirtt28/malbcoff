<?php
// Owner marks a "Needs action" item as Resolved, with an optional note (Patch 6c-1). Read-mostly: it only
// updates the report row; it never changes stock.
require __DIR__ . '/../bootstrap.php';

if (!Auth::check()) redirect(app_url('login'));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(app_url('needs-action'));
if (!Auth::isOwner() || Auth::isImpersonating()) {
    flash('error', 'Only the Owner can resolve these items.');
    redirect(app_home_url());
}
if (!Csrf::verify($_POST['_csrf'] ?? null)) {
    flash('error', 'Your session expired. Please try again.');
    redirect(app_url('needs-action'));
}

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$note = trim((string)($_POST['note'] ?? ''));
$note = preg_replace('/\s+/', ' ', $note) ?? $note;
$note = mb_substr($note, 0, 255);
$returnParams = (($_POST['tab'] ?? '') === 'resolved') ? ['tab' => 'resolved'] : [];

try {
    if (!outbox_reports_ready() || $id <= 0) throw new RuntimeException('This item was not found.');
    $updated = Database::query(
        "UPDATE outbox_reports SET status='resolved',resolved_by=?,resolved_at=NOW(),resolved_note=? WHERE id=? AND status='open'",
        [(int)Auth::user()['id'], $note !== '' ? $note : null, $id]
    );
    if ($updated->rowCount() === 1) {
        Security::audit('outbox.resolved', 'outbox_report', $id, ['note' => $note !== '']);
        flash('success', 'Marked as resolved.');
    } else {
        flash('success', 'This item was already resolved.');
    }
} catch (Throwable $e) {
    flash('error', safe_exception_message($e, 'Unable to update this item right now. Please try again.'));
}
redirect(app_url('needs-action', $returnParams));
