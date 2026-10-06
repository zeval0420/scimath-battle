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

$category = Category::find($categoryId);
if ($category === null || (int) $category['event_id'] !== $eventId) {
    Flash::error('That category could not be found for this event.');
    header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#categories'));
    exit;
}

Category::delete($categoryId);
// questions.category_id references this row ON DELETE SET NULL -- any
// questions that used this category simply become uncategorized.

Flash::success('Category deleted.');
header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#categories'));
