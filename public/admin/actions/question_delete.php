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

$question = Question::find($questionId);
if ($question === null || (int) $question['event_id'] !== $eventId) {
    Flash::error('That question could not be found for this event.');
    header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#questions'));
    exit;
}

$hasScores = ScoreEntry::where(['question_id' => $questionId]) !== [];

if ($hasScores) {
    // Same rationale as contestant_delete.php: hard-deleting would CASCADE
    // away score_entries/score_adjustments for this question. Deactivate
    // instead so it drops out of the active question set but history stays.
    Question::update($questionId, ['is_active' => 0]);
    Flash::success("Question Q{$question['question_number']} has recorded scores, so it was deactivated (not deleted) to preserve scoring history.");
} else {
    Uploader::delete($question['image_path']);
    Question::delete($questionId);
    Flash::success("Question Q{$question['question_number']} was deleted.");
}

header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#questions'));
