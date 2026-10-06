<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . relative_url('/admin/index.php'));
    exit;
}
Csrf::verify();

$eventId = (int) ($_POST['event_id'] ?? 0);
$categoryId = (int) ($_POST['category_id'] ?? 0);
$direction = (string) ($_POST['direction'] ?? '');

$category = Category::find($categoryId);
if ($category === null || (int) $category['event_id'] !== $eventId || !in_array($direction, ['up', 'down'], true)) {
    Flash::error('Could not reorder that category.');
    header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#categories'));
    exit;
}

$ordered = Category::forEvent($eventId);
$ids = array_column($ordered, 'id');
$index = array_search($categoryId, $ids, true);

if ($index !== false) {
    $swapWith = $direction === 'up' ? $index - 1 : $index + 1;
    if ($swapWith >= 0 && $swapWith < count($ids)) {
        [$ids[$index], $ids[$swapWith]] = [$ids[$swapWith], $ids[$index]];
        Category::reorder($ids);
    }
}

header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#categories'));
