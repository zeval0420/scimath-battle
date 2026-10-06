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
    Flash::error('Event not found.');
    header('Location: ' . relative_url('/admin/index.php'));
    exit;
}

require_once __DIR__ . '/../../../src/Support/Uploader.php';
require_once __DIR__ . '/../../../src/Models/PromotionalSlide.php';

$title = trim((string) ($_POST['title'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));

if (empty($title)) {
    Flash::error('Title is required.');
    header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#promotional'));
    exit;
}

$filePath = null;
if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
    $filePath = Uploader::handleImage($_FILES['file'], 'promotional', $eventId);
}

if ($filePath === null) {
    Flash::error('A file is required.');
    header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#promotional'));
    exit;
}

PromotionalSlide::create([
    'event_id'    => $eventId,
    'title'       => $title,
    'description' => $description ?: null,
    'file_path'   => $filePath,
    'file_type'   => 'image',
]);

Flash::success('Promotional slide added.');
header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#promotional'));
