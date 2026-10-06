<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . relative_url('/admin/index.php'));
    exit;
}
Csrf::verify();

$eventId = (int) ($_POST['event_id'] ?? 0);
$questionId = (int) ($_POST['question_id'] ?? 0);
$direction = (string) ($_POST['direction'] ?? '');

$question = Question::find($questionId);
if ($question === null || (int) $question['event_id'] !== $eventId || !in_array($direction, ['up', 'down'], true)) {
    Flash::error('Could not reorder that question.');
    header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#questions'));
    exit;
}

$ordered = Question::forEvent($eventId);
$ids = array_column($ordered, 'id');
$index = array_search($questionId, $ids, true);

if ($index !== false) {
    $swapWith = $direction === 'up' ? $index - 1 : $index + 1;
    if ($swapWith >= 0 && $swapWith < count($ids)) {
        [$ids[$index], $ids[$swapWith]] = [$ids[$swapWith], $ids[$index]];
        Question::reorder($ids);
    }
}

header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#questions'));
