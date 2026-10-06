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

$categoryId = isset($_POST['category_id']) ? (int) $_POST['category_id'] : null;
if ($categoryId !== null) {
    $existing = Category::find($categoryId);
    if ($existing === null || (int) $existing['event_id'] !== $eventId) {
        Flash::error('That category could not be found for this event.');
        header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#categories'));
        exit;
    }
}

$v = new Validator();
$name = $v->requiredString($_POST, 'name', 'Category name', 100);
$description = $v->optionalString($_POST, 'description', 'Description', 255);

if ($v->hasErrors()) {
    Flash::error('Please fix the highlighted errors: ' . implode(' ', $v->errors()));
    $editParam = $categoryId !== null ? '&edit_category=' . $categoryId : '';
    header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . $editParam . '#categories'));
    exit;
}

try {
    if ($categoryId !== null) {
        Category::update($categoryId, ['name' => $name, 'description' => $description]);
        Flash::success('Category updated.');
    } else {
        $count = count(Category::forEvent($eventId));
        Category::create([
            'event_id'      => $eventId,
            'name'          => $name,
            'description'   => $description,
            'display_order' => $count,
        ]);
        Flash::success('Category added.');
    }
} catch (PDOException $e) {
    // Unique(event_id, name) violation -> 1062 in MySQL/MariaDB
    if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), '1062')) {
        Flash::error("A category named \"{$name}\" already exists for this event.");
    } else {
        throw $e;
    }
}

header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#categories'));
