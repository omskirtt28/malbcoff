<?php
// Read-only JSON feed for the KPI drawer (Stock Monitoring + Dashboard). Owner only.
require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../core/StockMonitoringReport.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function drawer_fail(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

if (!Auth::check()) drawer_fail(401, 'Please sign in again.');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') drawer_fail(405, 'Invalid request method.');
if (!Auth::isOwner()) drawer_fail(403, 'Only the owner can view this.');

try {
    echo json_encode(StockMonitoringReport::drawer($_GET), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (InvalidArgumentException $e) {
    drawer_fail(422, 'Invalid request.');
} catch (Throwable $e) {
    Security::reportException($e, 'kpi_drawer');
    drawer_fail(500, 'The details could not be loaded right now.');
}
