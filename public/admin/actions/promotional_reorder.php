<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . relative_url('/admin/index.php'));
    exit;
}
Csrf::verify();

$eventId = (int) ($_POST['event_id'] ?? 0);
$slideId = (int) ($_POST['slide_id'] ?? 0);
$direction = (string) ($_POST['direction'] ?? '');

require_once __DIR__ . '/../../../src/Models/PromotionalSlide.php';

$slides = PromotionalSlide::forEvent($eventId);
$ids = array_column($slides, 'id');
$index = array_search($slideId, $ids, true);

if ($index === false) {
    Flash::error('Slide not found.');
    header('Location: ' . relative_url('/admin/event.php?id=' . $eventId));
    exit;
}

$newIndex = $direction === 'up' ? $index - 1 : $index + 1;
if ($newIndex < 0 || $newIndex >= count($ids)) {
    Flash::error('Cannot move further.');
    header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#promotional'));
    exit;
}

// Swap display_order
$slideA = $slides[$index];
$slideB = $slides[$newIndex];
PromotionalSlide::update($slideA['id'], ['display_order' => $slideB['display_order']]);
PromotionalSlide::update($slideB['id'], ['display_order' => $slideA['display_order']]);

Flash::success('Slide moved.');
header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#promotional'));
