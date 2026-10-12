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

// The submitted checkbox set is authoritative: any contestant not in the
// list is cleared from the clincher round.
$selected = array_map('intval', (array) ($_POST['clincher_ids'] ?? []));

$selectedCount = 0;
foreach (Contestant::forEvent($eventId) as $contestant) {
    $id = (int) $contestant['id'];
    $shouldBeClincher = in_array($id, $selected, true) ? 1 : 0;
    if ($shouldBeClincher === 1) {
        $selectedCount++;
    }
    if ((int) $contestant['is_clincher'] !== $shouldBeClincher) {
        Contestant::update($id, ['is_clincher' => $shouldBeClincher]);
    }
}

Flash::success($selectedCount === 0
    ? 'Clincher round team selection cleared.'
    : $selectedCount . ' team(s) selected to enter the clincher round.');

header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#contestants'));
