<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . relative_url('/admin/index.php'));
    exit;
}
Csrf::verify();

$eventId = (int) ($_POST['event_id'] ?? 0);
$contestantId = (int) ($_POST['contestant_id'] ?? 0);

$contestant = Contestant::find($contestantId);
if ($contestant === null || (int) $contestant['event_id'] !== $eventId) {
    Flash::error('That contestant could not be found for this event.');
    header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#contestants'));
    exit;
}

$hasScores = ScoreEntry::where(['contestant_id' => $contestantId]) !== [];

if ($hasScores) {
    // Hard-deleting would CASCADE-delete score_entries (and their
    // score_adjustments audit trail) for this contestant -- destroying
    // competition history. Deactivate instead so the contestant drops out
    // of active rosters/rankings but the record of what happened stays.
    Contestant::update($contestantId, ['is_active' => 0]);
    Flash::success("\"{$contestant['name']}\" has recorded scores, so it was deactivated (not deleted) to preserve scoring history.");
} else {
    Uploader::delete($contestant['logo_path']);
    Contestant::delete($contestantId);
    Flash::success("\"{$contestant['name']}\" was removed.");
}

header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#contestants'));
