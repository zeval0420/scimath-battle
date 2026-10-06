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

$questionId = isset($_POST['question_id']) ? (int) $_POST['question_id'] : null;
$existing = null;
if ($questionId !== null) {
    $existing = Question::find($questionId);
    if ($existing === null || (int) $existing['event_id'] !== $eventId) {
        Flash::error('That question could not be found for this event.');
        header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#questions'));
        exit;
    }
}

$v = new Validator();
$questionNumber = $v->requiredInt($_POST, 'question_number', 'Question number', 1, 9999);
$points = $v->optionalIntOrInherit($_POST, 'points', 'Points', 0, 100000);
$timeSeconds = $v->optionalIntOrInherit($_POST, 'time_seconds', 'Time limit', 5, 7200);

$categoryId = null;
$rawCategoryId = trim((string) ($_POST['category_id'] ?? ''));
if ($rawCategoryId !== '') {
    $category = Category::find((int) $rawCategoryId);
    if ($category === null || (int) $category['event_id'] !== $eventId) {
        $v->addError('category_id', 'Selected category does not belong to this event.');
    } else {
        $categoryId = (int) $rawCategoryId;
    }
}

// Image is required when creating a new question; optional (replace) when editing.
$hasNewImage = isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE;
if ($existing === null && !$hasNewImage) {
    $v->addError('image', 'A question slide image is required.');
}

if ($v->hasErrors()) {
    Flash::error('Please fix the highlighted errors: ' . implode(' ', $v->errors()));
    $editParam = $questionId !== null ? '&edit_question=' . $questionId : '';
    header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . $editParam . '#questions'));
    exit;
}

$attributes = [
    'question_number' => $questionNumber,
    'category_id'      => $categoryId,
    'points'           => $points,
    'time_seconds'     => $timeSeconds,
];
if ($existing !== null) {
    $attributes['is_active'] = isset($_POST['is_active']) ? 1 : 0;
}

try {
    if ($hasNewImage) {
        $newPath = Uploader::handleImage($_FILES['image'], 'questions', $eventId);
        if ($existing !== null) {
            Uploader::delete($existing['image_path']);
        }
        $attributes['image_path'] = $newPath;
    }
} catch (RuntimeException $e) {
    Flash::error($e->getMessage());
    $editParam = $questionId !== null ? '&edit_question=' . $questionId : '';
    header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . $editParam . '#questions'));
    exit;
}

try {
    if ($questionId !== null) {
        Question::update($questionId, $attributes);
        Flash::success('Question updated.');
    } else {
        $attributes['event_id'] = $eventId;
        $attributes['display_order'] = count(Question::forEvent($eventId));
        Question::create($attributes);
        Flash::success('Question added.');
    }
} catch (PDOException $e) {
    if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), '1062')) {
        Flash::error("Question number {$questionNumber} is already used in this event.");
    } else {
        throw $e;
    }
}

header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#questions'));
