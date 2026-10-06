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

$targetStatus = (string) ($_POST['status'] ?? '');
if (!in_array($targetStatus, EventStatus::ALL, true)) {
    Flash::error('Invalid status.');
    header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#overview'));
    exit;
}

// Explicit transition matrix -- mirrors the links shown in event.php, but
// re-checked server-side since this endpoint must not trust the form alone.
$allowedTransitions = [
    EventStatus::DRAFT     => [EventStatus::READY, EventStatus::ARCHIVED],
    EventStatus::READY     => [EventStatus::ACTIVE, EventStatus::DRAFT, EventStatus::ARCHIVED],
    EventStatus::ACTIVE    => [EventStatus::COMPLETED],
    EventStatus::COMPLETED => [EventStatus::ARCHIVED],
    EventStatus::ARCHIVED  => [EventStatus::DRAFT],
];

$currentStatus = $event['status'];
if (!in_array($targetStatus, $allowedTransitions[$currentStatus] ?? [], true)) {
    Flash::error("Cannot change status from \"{$currentStatus}\" to \"{$targetStatus}\".");
    header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#overview'));
    exit;
}

// Activating a competition (ready/active) must not be allowed if the event
// isn't actually configured enough to run.
if (in_array($targetStatus, [EventStatus::READY, EventStatus::ACTIVE], true)) {
    $problems = EventValidator::readinessProblems($eventId);
    if ($problems !== []) {
        Flash::error('Cannot activate this event yet: ' . implode(' ', $problems));
        header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#overview'));
        exit;
    }
}

Event::update($eventId, ['status' => $targetStatus]);

Flash::success("Event status changed to \"{$targetStatus}\".");
header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#overview'));
