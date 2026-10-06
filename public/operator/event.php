<?php
require_once __DIR__ . '/../../src/bootstrap.php';
Auth::requireAdmin();

$eventId = (int) ($_GET['id'] ?? 0);
$event = $eventId > 0 ? Event::find($eventId) : null;
if ($event === null) {
    Flash::error('That event could not be found.');
    header('Location: ' . relative_url('/operator/index.php'));
    exit;
}
if (!in_array($event['status'], [EventStatus::READY, EventStatus::ACTIVE, EventStatus::COMPLETED], true)) {
    Flash::error('This event is not ready to run yet. Configure it in the admin panel first.');
    header('Location: ' . relative_url('/operator/index.php'));
    exit;
}

// Server-rendered initial payload -- fast first paint, no loading flash.
$dashboard = CompetitionRuntime::getDashboard($eventId);

// The question list for the "Go to question" control is effectively static
// during a live run (editing questions concurrently with running the event
// isn't a supported workflow), so it's rendered once server-side rather
// than re-fetched on every poll.
$allQuestions = Question::forEvent($eventId, true);
$promotionalSlides = PromotionalSlide::forEvent($eventId, true);
$answerSlides = AnswerSlide::forEvent($eventId, true);
$settings = EventSetting::forEvent($eventId);
$audioConfig = $settings ? EventSetting::audioConfig($settings) : [];

$pageTitle = $event['name'] . ' — Operator';
require __DIR__ . '/../admin/includes/header.php';
?>
<link rel="stylesheet" href="../assets/operator.css">

<div id="op-root"
     data-event-id="<?= $eventId ?>"
     data-csrf="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>"
     data-initial='<?= htmlspecialchars(json_encode($dashboard), ENT_QUOTES) ?>'>

    <div class="op-topbar">
        <div>
            <h1 class="op-event-name"><?= htmlspecialchars($event['name']) ?></h1>
            <div class="op-progress" id="op-progress">—</div>
        </div>
        <div class="op-event-controls">
            <button type="button" class="btn btn-primary" id="op-btn-start-event">Start Event</button>
            <button type="button" class="btn btn-danger" id="op-btn-end-event">End Event</button>
            <a href="index.php" class="btn btn-small">Switch event</a>
        </div>
    </div>

    <div class="op-display-state" id="op-display-state">
        <span class="label">Public display:</span> <span id="op-display-state-text">—</span>
    </div>

    <div class="op-wrap">
        <!-- LEFT COLUMN: question, timer, navigation -->
        <div>
            <div class="op-panel">
                <h2>Current question</h2>
                <div class="op-question-meta">
                    <div class="stat"><span class="value" id="op-q-number">—</span><span class="label">Question</span></div>
                    <div class="stat"><span class="value" id="op-q-category">—</span><span class="label">Category</span></div>
                    <div class="stat"><span class="value" id="op-q-points">—</span><span class="label">Points</span></div>
                    <div class="stat"><span class="value" id="op-q-time">—</span><span class="label">Time limit</span></div>
                </div>
                <img id="op-q-preview" class="op-question-preview" style="display:none;" alt="Current question preview">
                <p class="muted" id="op-q-none">No question is currently displayed.</p>

                <div class="op-nav-buttons">
                    <button type="button" class="btn btn-primary" id="op-btn-start-first">Start First Question</button>
                    <button type="button" class="btn" id="op-btn-prev">&larr; Previous</button>
                    <button type="button" class="btn" id="op-btn-next">Next &rarr;</button>
                    <select id="op-goto-select" class="btn" style="max-width:200px;">
                        <option value="">Go to question…</option>
                        <?php foreach ($allQuestions as $q): ?>
                            <option value="<?= (int) $q['id'] ?>">Q<?= (int) $q['question_number'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="op-panel">
                <h2>Timer</h2>
                <div class="op-timer-display timer-idle" id="op-timer-display">--</div>
                <div class="op-timer-buttons">
                    <button type="button" class="btn btn-primary" id="op-btn-timer-start">START</button>
                    <button type="button" class="btn" id="op-btn-timer-pause">PAUSE</button>
                    <button type="button" class="btn" id="op-btn-timer-resume">RESUME</button>
                    <button type="button" class="btn" id="op-btn-timer-reset">RESET</button>
                </div>
            </div>

            <div class="op-panel">
                <h2>Question Controls</h2>
                <div class="op-nav-buttons">
                    <button type="button" class="btn" id="op-btn-hide-question">Hide Question</button>
                    <button type="button" class="btn" id="op-btn-show-question">Show Question</button>
                    <button type="button" class="btn" id="op-btn-preview-next">Preview Next</button>
                </div>
            </div>

            <div class="op-panel">
                <h2>Round Control</h2>
                <div class="op-nav-buttons">
                    <button type="button" class="btn" id="op-btn-start-round">Start Round</button>
                    <button type="button" class="btn" id="op-btn-end-round">End Round</button>
                </div>
            </div>

            <div class="op-panel">
                <h2>Ranking control</h2>
                <div class="op-nav-buttons">
                    <button type="button" class="btn btn-primary" id="op-btn-show-ranking">SHOW RANKING</button>
                    <button type="button" class="btn" id="op-btn-return-to-question">Return to question</button>
                    <button type="button" class="btn" id="op-btn-show-final">Show final results</button>
                    <button type="button" class="btn" id="op-btn-return-to-cover">Return to cover</button>
                </div>
                <p class="hint" id="op-auto-rank-hint" style="display:none;margin-top:10px;">This event is configured to automatically show the ranking after each score is entered.</p>
            </div>

            <div class="op-panel">
                <h2>Promotional Slides</h2>
                <div class="op-nav-buttons">
                    <select id="op-promo-select" class="btn">
                        <option value="">Show promotional slide...</option>
                        <?php foreach ($promotionalSlides as $slide): ?>
                            <option value="<?= (int) $slide['id'] ?>"><?= htmlspecialchars($slide['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="op-panel">
                <h2>Answer Slides</h2>
                <div class="op-nav-buttons">
                    <select id="op-answer-select" class="btn">
                        <option value="">Show answer slide...</option>
                        <?php foreach ($answerSlides as $slide): ?>
                            <option value="<?= (int) $slide['id'] ?>"><?= htmlspecialchars($slide['title'] ?? 'Answer Slide') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="op-panel">
                <h2>Next Up Preview</h2>
                <div id="op-next-preview">
                    <img id="op-next-image" class="op-question-preview" style="display:none;" alt="Next question preview">
                    <p class="muted" id="op-next-none">No next question</p>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN: scoring, rankings -->
        <div>
            <div class="op-panel">
                <h2>Score entry — question <span id="op-score-q-number">—</span> (<span id="op-score-q-points">—</span> points)</h2>
                <div id="op-score-list"></div>
                <p class="muted" id="op-score-none" style="display:none;">Start a question to begin scoring.</p>
            </div>

            <div class="op-panel">
                <h2>Current ranking</h2>
                <div id="op-rank-list"></div>
            </div>
        </div>
    </div>

    <p class="op-poll-indicator" id="op-poll-indicator">Live — updating every second</p>

    <footer class="op-footer">
        <p>Operator Interface by <strong>Developer Name</strong> &middot; Powered by Sci-Math Competition System</p>
    </footer>
</div>

<script src="../assets/operator.js"></script>

<?php require __DIR__ . '/../admin/includes/footer.php'; ?>
