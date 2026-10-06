<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . relative_url('/admin/index.php'));
    exit;
}
Csrf::verify();

$eventId = (int) ($_POST['event_id'] ?? 0);
$event = Event::find($eventId);
if ($event === null) {
    Flash::error('That event could not be found.');
    header('Location: ' . relative_url('/admin/index.php'));
    exit;
}

$hasData = Category::where(['event_id' => $eventId]) !== []
    || Contestant::where(['event_id' => $eventId]) !== []
    || Question::where(['event_id' => $eventId]) !== [];

if ($event['status'] !== EventStatus::DRAFT || $hasData) {
    Flash::error('Only empty draft events can be deleted. Archive this event instead to preserve its data.');
    header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#overview'));
    exit;
}

Uploader::delete($event['logo_path']);
Uploader::delete($event['cover_image_path']);

// event_settings / competition_sessions cascade-delete via FK ON DELETE CASCADE.
Event::delete($eventId);

Flash::success('Event deleted.');
header('Location: ' . relative_url('/admin/index.php'));
