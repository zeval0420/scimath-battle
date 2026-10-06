<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . relative_url('/admin/index.php'));
    exit;
}
Csrf::verify();

$eventId = (int) ($_POST['event_id'] ?? 0);
$settingsId = (int) ($_POST['settings_id'] ?? 0);
$event = Event::find($eventId);
$settings = $event !== null ? EventSetting::forEvent($eventId) : null;

if ($event === null || $settings === null || (int) $settings['id'] !== $settingsId) {
    Flash::error('That event/settings record could not be found.');
    header('Location: ' . relative_url('/admin/index.php'));
    exit;
}

$v = new Validator();
$defaultPoints = $v->requiredInt($_POST, 'default_points', 'Default points', 0, 100000);
$defaultTime = $v->requiredInt($_POST, 'default_time_seconds', 'Default time', 5, 7200);
$rankingOrder = $v->inSet($_POST, 'ranking_order', 'Ranking order', RankingOrder::ALL);
$timerWarning = $v->optionalIntOrInherit($_POST, 'timer_warning_seconds', 'Timer warning', 0, 600);

if ($v->hasErrors()) {
    Flash::error('Please fix the highlighted errors: ' . implode(' ', $v->errors()));
    header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#settings'));
    exit;
}

$toggles = [
    'show_category'        => isset($_POST['show_category']),
    'show_question_number' => isset($_POST['show_question_number']),
    'show_timer'           => isset($_POST['show_timer']),
];

// Handle file uploads
require_once __DIR__ . '/../../../src/Support/Uploader.php';

$updates = [
    'default_points'                => $defaultPoints,
    'default_time_seconds'          => $defaultTime,
    'ranking_order'                 => $rankingOrder,
    'timer_warning_seconds'         => $timerWarning,
    'auto_show_ranking_after_score' => isset($_POST['auto_show_ranking_after_score']) ? 1 : 0,
    'extra_settings'                => $toggles,
    'banner_audio_volume'           => (int) ($_POST['banner_audio_volume'] ?? 100),
    'game_audio_volume'             => (int) ($_POST['game_audio_volume'] ?? 30),
];

// Timer audio
if (isset($_FILES['timer_audio']) && $_FILES['timer_audio']['error'] === UPLOAD_ERR_OK) {
    // Delete old timer audio if exists
    if (!empty($settings['timer_audio_path'])) {
        Uploader::delete($settings['timer_audio_path']);
    }
    $audioConfig = require __DIR__ . '/../../../config/app.php';
    $audioDir = $audioConfig['uploads']['base_path'] . '/audio/' . $eventId;
    if (!is_dir($audioDir) && !mkdir($audioDir, 0755, true) && !is_dir($audioDir)) {
        throw new RuntimeException('Could not create audio directory.');
    }
    $tmpName = $_FILES['timer_audio']['tmp_name'];
    $ext = pathinfo($_FILES['timer_audio']['name'], PATHINFO_EXTENSION);
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $destination = $audioDir . '/' . $filename;
    if (move_uploaded_file($tmpName, $destination)) {
        chmod($destination, 0644);
        $updates['timer_audio_path'] = $audioConfig['uploads']['base_url'] . '/audio/' . $eventId . '/' . $filename;
    }
} elseif (isset($_POST['remove_timer_audio']) && !empty($settings['timer_audio_path'])) {
    Uploader::delete($settings['timer_audio_path']);
    $updates['timer_audio_path'] = null;
}

// Banner audio
if (isset($_FILES['banner_audio']) && $_FILES['banner_audio']['error'] === UPLOAD_ERR_OK) {
    if (!empty($settings['banner_audio_path'])) {
        Uploader::delete($settings['banner_audio_path']);
    }
    $audioConfig = require __DIR__ . '/../../../config/app.php';
    $audioDir = $audioConfig['uploads']['base_path'] . '/audio/' . $eventId;
    $tmpName = $_FILES['banner_audio']['tmp_name'];
    $ext = pathinfo($_FILES['banner_audio']['name'], PATHINFO_EXTENSION);
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $destination = $audioDir . '/' . $filename;
    if (move_uploaded_file($tmpName, $destination)) {
        chmod($destination, 0644);
        $updates['banner_audio_path'] = $audioConfig['uploads']['base_url'] . '/audio/' . $eventId . '/' . $filename;
    }
} elseif (isset($_POST['remove_banner_audio']) && !empty($settings['banner_audio_path'])) {
    Uploader::delete($settings['banner_audio_path']);
    $updates['banner_audio_path'] = null;
}

// Game audio
if (isset($_FILES['game_audio']) && $_FILES['game_audio']['error'] === UPLOAD_ERR_OK) {
    if (!empty($settings['game_audio_path'])) {
        Uploader::delete($settings['game_audio_path']);
    }
    $audioConfig = require __DIR__ . '/../../../config/app.php';
    $audioDir = $audioConfig['uploads']['base_path'] . '/audio/' . $eventId;
    $tmpName = $_FILES['game_audio']['tmp_name'];
    $ext = pathinfo($_FILES['game_audio']['name'], PATHINFO_EXTENSION);
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $destination = $audioDir . '/' . $filename;
    if (move_uploaded_file($tmpName, $destination)) {
        chmod($destination, 0644);
        $updates['game_audio_path'] = $audioConfig['uploads']['base_url'] . '/audio/' . $eventId . '/' . $filename;
    }
} elseif (isset($_POST['remove_game_audio']) && !empty($settings['game_audio_path'])) {
    Uploader::delete($settings['game_audio_path']);
    $updates['game_audio_path'] = null;
}

// Times up image
if (isset($_FILES['times_up_image']) && $_FILES['times_up_image']['error'] === UPLOAD_ERR_OK) {
    if (!empty($settings['times_up_image_path'])) {
        Uploader::delete($settings['times_up_image_path']);
    }
    $newPath = Uploader::handleImage($_FILES['times_up_image'], 'media', $eventId);
    $updates['times_up_image_path'] = $newPath;
} elseif (isset($_POST['remove_timesup']) && !empty($settings['times_up_image_path'])) {
    Uploader::delete($settings['times_up_image_path']);
    $updates['times_up_image_path'] = null;
}

EventSetting::update($settingsId, $updates);

Flash::success('Settings saved.');
header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#settings'));

