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

$v = new Validator();
$name = $v->requiredString($_POST, 'name', 'Event name', 150);
$subtitle = $v->optionalString($_POST, 'subtitle', 'Subtitle', 255);
$eventDate = $v->optionalDate($_POST, 'event_date', 'Event date');

if ($v->hasErrors()) {
    Flash::error('Please fix the highlighted errors: ' . implode(' ', $v->errors()));
    header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#overview'));
    exit;
}

$attributes = [
    'name'       => $name,
    'subtitle'   => $subtitle,
    'event_date' => $eventDate,
];

try {
    // Logo
    if (!empty($_POST['remove_logo'])) {
        Uploader::delete($event['logo_path']);
        $attributes['logo_path'] = null;
    }
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $newPath = Uploader::handleImage($_FILES['logo'], 'logos', $eventId);
        Uploader::delete($event['logo_path']);
        $attributes['logo_path'] = $newPath;
    }

    // Cover photo
    if (!empty($_POST['remove_cover'])) {
        Uploader::delete($event['cover_image_path']);
        $attributes['cover_image_path'] = null;
    }
    if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $newPath = Uploader::handleImage($_FILES['cover_image'], 'covers', $eventId);
        Uploader::delete($event['cover_image_path']);
        $attributes['cover_image_path'] = $newPath;
    }
} catch (RuntimeException $e) {
    Flash::error($e->getMessage());
    header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#overview'));
    exit;
}

Event::update($eventId, $attributes);

Flash::success('Event details saved.');
header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#overview'));
