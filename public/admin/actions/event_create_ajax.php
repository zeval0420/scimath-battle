<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
Auth::requireAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!Csrf::check()) {
    http_response_code(419);
    ob_clean();
    echo json_encode(['error' => 'Security check failed. Please refresh and try again.']);
    exit;
}

$v = new Validator();
$name = $v->requiredString($_POST, 'name', 'Event name', 150);
$subtitle = $v->optionalString($_POST, 'subtitle', 'Subtitle', 255);
$eventDate = $v->optionalDate($_POST, 'event_date', 'Event date');

if ($v->hasErrors()) {
    echo json_encode(['error' => implode(' ', $v->errors())]);
    exit;
}

try {
    $event = Event::create([
        'name'       => $name,
        'subtitle'   => $subtitle,
        'event_date' => $eventDate,
        'status'     => EventStatus::DRAFT,
    ]);
    EventSetting::createDefaultsFor((int) $event['id']);
    CompetitionSession::createFor((int) $event['id']);

    echo json_encode([
        'ok'         => true,
        'event_id'   => (int) $event['id'],
        'event_name' => $name,
    ]);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Failed to create event: ' . $e->getMessage()]);
    exit;
}
