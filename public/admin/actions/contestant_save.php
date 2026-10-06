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

$contestantId = isset($_POST['contestant_id']) ? (int) $_POST['contestant_id'] : null;
$existing = null;
if ($contestantId !== null) {
    $existing = Contestant::find($contestantId);
    if ($existing === null || (int) $existing['event_id'] !== $eventId) {
        Flash::error('That contestant could not be found for this event.');
        header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#contestants'));
        exit;
    }
}

$v = new Validator();
$name = $v->requiredString($_POST, 'name', 'Contestant name', 150);
$teamCode = $v->optionalString($_POST, 'team_code', 'Team code', 20);
$organization = $v->optionalString($_POST, 'organization', 'Organization', 150);
$startingScore = $v->requiredInt($_POST, 'starting_score', 'Starting score', -1000000, 1000000);

if ($v->hasErrors()) {
    Flash::error('Please fix the highlighted errors: ' . implode(' ', $v->errors()));
    $editParam = $contestantId !== null ? '&edit_contestant=' . $contestantId : '';
    header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . $editParam . '#contestants'));
    exit;
}

$attributes = [
    'name'           => $name,
    'team_code'      => $teamCode,
    'organization'   => $organization,
    'starting_score' => $startingScore,
];
if ($existing !== null) {
    $attributes['is_active'] = isset($_POST['is_active']) ? 1 : 0;
}

try {
    if (!empty($_POST['remove_logo']) && $existing !== null) {
        Uploader::delete($existing['logo_path']);
        $attributes['logo_path'] = null;
    }
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $newPath = Uploader::handleImage($_FILES['logo'], 'contestants', $eventId);
        if ($existing !== null) {
            Uploader::delete($existing['logo_path']);
        }
        $attributes['logo_path'] = $newPath;
    }
} catch (RuntimeException $e) {
    Flash::error($e->getMessage());
    $editParam = $contestantId !== null ? '&edit_contestant=' . $contestantId : '';
    header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . $editParam . '#contestants'));
    exit;
}

try {
    if ($contestantId !== null) {
        Contestant::update($contestantId, $attributes);
        Flash::success('Contestant updated.');
    } else {
        $attributes['event_id'] = $eventId;
        $attributes['display_order'] = count(Contestant::forEvent($eventId));
        Contestant::create($attributes);
        Flash::success('Contestant added.');
    }
} catch (PDOException $e) {
    if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), '1062')) {
        Flash::error("Team code \"{$teamCode}\" is already used by another contestant in this event.");
    } else {
        throw $e;
    }
}

header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#contestants'));
