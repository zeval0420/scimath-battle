<?php

/**
 * Public display API. Unlike public/api/runtime.php, this endpoint is:
 *   - unauthenticated (the projector/screen in a venue has no operator
 *     logged into it, and shouldn't need to be)
 *   - read-only (GET only, a single "state" action -- the public display
 *     never mutates anything)
 *   - a narrower payload (CompetitionRuntime::getPublicState(), not
 *     getDashboard()) -- no who-scored-what, no per-contestant
 *     current-question results, only what the projected screen should show
 *
 * Still goes through CompetitionRuntime exclusively; no state logic here.
 */

require_once __DIR__ . '/../../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function display_api_fail(int $httpStatus, string $message): void
{
    http_response_code($httpStatus);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    display_api_fail(405, 'This endpoint is read-only.');
}

$eventId = (int) ($_GET['event_id'] ?? 0);
if ($eventId <= 0) {
    display_api_fail(400, 'event_id is required.');
}

try {
    $publicState = CompetitionRuntime::getPublicState($eventId);
    echo json_encode(['ok' => true] + $publicState);
} catch (CompetitionRuntimeException $e) {
    display_api_fail(404, $e->getMessage());
} catch (Throwable $e) {
    error_log('[display API] ' . $e->getMessage());
    display_api_fail(500, 'An unexpected error occurred.');
}
