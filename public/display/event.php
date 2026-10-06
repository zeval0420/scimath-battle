<?php
require_once __DIR__ . '/../../src/bootstrap.php';

// Deliberately NO Auth::requireAdmin() here -- this page is the projector
// screen in the venue. Anyone with the link can view it (it's read-only and
// shows nothing sensitive), matching "no administrative controls visible on
// this interface" from the original spec.

$eventId = (int) ($_GET['id'] ?? 0);
$event = $eventId > 0 ? Event::find($eventId) : null;
if ($event === null) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;background:#111;color:#eee;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;">Event not found.</body></html>';
    exit;
}

// Server-rendered initial payload for a fast, correct first paint (no flash
// of empty/wrong content while the first poll is still in flight).
$publicState = CompetitionRuntime::getPublicState($eventId);
$audioConfig = EventSetting::audioConfig($settings ?? []);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= htmlspecialchars($event['name']) ?></title>
<link rel="stylesheet" href="../assets/display.css">
</head>
<body>

<div id="disp-root"
     data-event-id="<?= $eventId ?>"
     data-initial='<?= htmlspecialchars(json_encode($publicState), ENT_QUOTES) ?>'
     data-audio-config='<?= htmlspecialchars(json_encode($audioConfig), ENT_QUOTES) ?>'>

    <!-- COVER -->
    <section class="disp-state disp-cover" data-state-section="cover">
        <div class="disp-cover-bg" id="disp-cover-bg"></div>
        <div class="disp-cover-overlay"></div>
        <div class="disp-cover-content">
            <img id="disp-cover-logo" class="disp-cover-logo" style="display:none;" alt="">
            <h1 id="disp-cover-name" class="disp-cover-name"></h1>
            <p id="disp-cover-subtitle" class="disp-cover-subtitle"></p>
        </div>
    </section>

    <!-- QUESTION -->
    <section class="disp-state disp-question" data-state-section="question">
        <div class="disp-question-topbar">
            <div class="disp-question-category" id="disp-q-category"></div>
            <div class="disp-question-number" id="disp-q-number"></div>
            <div class="disp-timer" id="disp-timer">--:--</div>
        </div>
        <div class="disp-question-stage">
            <img id="disp-q-image" class="disp-question-image" alt="Question">
        </div>
        <div class="disp-timeup-overlay" id="disp-timeup-overlay">
            <?php if (!empty($audioConfig['times_up_image_path'])): ?>
                <img src="../<?= htmlspecialchars($audioConfig['times_up_image_path']) ?>" alt="Time's Up" class="disp-timeup-image">
            <?php else: ?>
                <div class="disp-timeup-banner">TIME'S UP</div>
            <?php endif; ?>
        </div>
    </section>

    <!-- RANKING -->
    <section class="disp-state disp-ranking" data-state-section="ranking">
        <h2 class="disp-ranking-title">Current Standings</h2>
        <div class="disp-ranking-list" id="disp-ranking-list"></div>
    </section>

    <!-- FINAL RESULTS -->
    <section class="disp-state disp-final" data-state-section="final_results">
        <div class="disp-final-header">
            <img id="disp-final-logo" class="disp-final-logo" style="display:none;" alt="">
            <h2 class="disp-final-eyebrow">Final Results</h2>
            <h1 id="disp-final-event-name" class="disp-final-event-name"></h1>
        </div>
        <div class="disp-champion" id="disp-champion"></div>
        <div class="disp-ranking-list disp-final-list" id="disp-final-ranking-list"></div>
    </section>

    <!-- PROMOTIONAL -->
    <section class="disp-state disp-promo" data-state-section="promotional">
        <div class="disp-promo-content">
            <img id="disp-promo-image" class="disp-promo-image" style="display:none;" alt="">
            <div id="disp-promo-desc" class="disp-promo-description" style="display:none;"></div>
        </div>
    </section>

    <!-- ANSWER -->
    <section class="disp-state disp-answer" data-state-section="answer">
        <div class="disp-answer-content">
            <img id="disp-answer-image" class="disp-answer-image" alt="Answer">
        </div>
    </section>

    <!-- HIDDEN OVERLAY -->
    <div class="disp-hidden-overlay" id="disp-hidden-overlay" style="display:none;">
        <div class="disp-hidden-text">Please Wait</div>
    </div>

    <button type="button" id="disp-fullscreen-btn" class="disp-fullscreen-btn" title="Toggle fullscreen (F)">&#x26F6;</button>
</div>

<script src="../assets/display.js"></script>
</body>
</html>
