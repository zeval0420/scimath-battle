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

// Order-following questions list, handed to the JS so it can render the
// current/next slide preview without a per-poll round trip.
$questionNumberById = [];
$questionListPayload = [];
foreach ($allQuestions as $q) {
    $questionNumberById[(int) $q['id']] = (int) $q['question_number'];
    $questionListPayload[] = [
        'id'              => (int) $q['id'],
        'question_number' => (int) $q['question_number'],
        'round'           => $q['round'] ?? null,
        'image_path'      => $q['image_path'],
    ];
}

// The answer-slide control is question-scoped: general/event-level answer
// slides (question_id = NULL) are excluded, keeping this control inside the
// dedicated "Question Controls" container.
$questionAnswerSlides = array_values(array_filter($answerSlides, static function ($slide) {
    return $slide['question_id'] !== null && (int) $slide['question_id'] > 0;
}));

$pageTitle = $event['name'] . ' — Operator';
require __DIR__ . '/../admin/includes/header.php';
?>
<link rel="stylesheet" href="../assets/operator.css">

<div id="op-root"
     data-event-id="<?= $eventId ?>"
     data-csrf="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>"
     data-initial='<?= htmlspecialchars(json_encode($dashboard), ENT_QUOTES) ?>'
     data-questions='<?= htmlspecialchars(json_encode($questionListPayload), ENT_QUOTES) ?>'
     data-last-scored-question-id='<?= $dashboard["last_scored_question_id"] ?? "null" ?>'>

    <div class="op-topbar">
        <div>
            <h1 class="op-event-name"><?= htmlspecialchars($event['name']) ?></h1>
            <div class="op-progress" id="op-progress">—</div>
        </div>
        <div class="op-event-controls">
            <div class="op-components">
                <button type="button" class="btn" id="op-btn-components" aria-expanded="false" aria-controls="op-components-menu">
                    Components &#9662;
                </button>
                <div class="op-components-menu" id="op-components-menu" hidden>
                    <p class="op-components-title">Show / hide panels</p>
                    <div id="op-components-list"></div>
                </div>
            </div>
            <button type="button" class="btn btn-primary" id="op-btn-start-event">Start Event</button>
            <button type="button" class="btn btn-danger" id="op-btn-end-event">End Event</button>
            <a href="index.php" class="btn btn-small">Switch event</a>
        </div>
    </div>

    <div class="op-display-state" id="op-display-state">
        <span class="label">Public display:</span> <span id="op-display-state-text">—</span>
    </div>

    <div class="op-wrap">
        <div class="op-panel op-panel--wide" data-op-component="current-question">
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

            <div class="op-panel" data-op-component="timer">
                <h2>Timer</h2>
                <div class="op-timer-display timer-idle" id="op-timer-display">--</div>
                <div class="op-timer-buttons">
                    <button type="button" class="btn btn-primary" id="op-btn-timer-start">START</button>
                    <button type="button" class="btn" id="op-btn-timer-pause">PAUSE</button>
                    <button type="button" class="btn" id="op-btn-timer-resume">RESUME</button>
                    <button type="button" class="btn" id="op-btn-timer-reset">RESET</button>
                </div>
            </div>

            <div class="op-panel" data-op-component="question-controls">
                <h2>Question Controls</h2>

                <div class="op-slide-strip">
                    <figure class="op-slide-thumb">
                        <figcaption>Current</figcaption>
                        <div class="op-slide-frame">
                            <img id="op-current-thumb" alt="Current slide preview" style="display:none;">
                            <span class="op-slide-empty" id="op-current-thumb-empty">&mdash;</span>
                        </div>
                        <span class="op-slide-caption" id="op-current-thumb-caption">No question</span>
                    </figure>
                    <figure class="op-slide-thumb">
                        <figcaption>Next</figcaption>
                        <div class="op-slide-frame">
                            <img id="op-next-thumb" alt="Next slide preview" style="display:none;">
                            <span class="op-slide-empty" id="op-next-thumb-empty">&mdash;</span>
                        </div>
                        <span class="op-slide-caption" id="op-next-thumb-caption">No next question</span>
                    </figure>
                </div>

                <div class="op-action-buttons">
                    <button type="button" class="btn btn-primary op-action-btn" id="op-btn-show-question" disabled>Show Question</button>
                    <button type="button" class="btn btn-primary op-action-btn" id="op-btn-show-answer" disabled>Show Answer</button>
                    <button type="button" class="btn btn-success op-action-btn" id="op-btn-show-results" disabled>Show Results</button>
                </div>

                <div class="op-toggle-row">
                    <span class="op-toggle-title">Show question on display</span>
                    <label class="op-switch">
                        <input type="checkbox" id="op-toggle-question">
                        <span class="op-switch-track"><span class="op-switch-thumb"></span></span>
                    </label>
                    <span class="op-toggle-state" id="op-toggle-question-state">Hidden</span>
                </div>
                <p class="hint" style="margin-top:10px;">Override: force the current question to show or hide on the public display.</p>

                <div class="op-answer-control">
                    <label for="op-answer-select" class="op-answer-label">Show answer slide (questions only)</label>
                    <select id="op-answer-select" class="btn">
                        <option value="">Show answer slide...</option>
                        <?php foreach ($questionAnswerSlides as $slide): ?>
                            <option value="<?= (int) $slide['id'] ?>" data-question-id="<?= (int) $slide['question_id'] ?>">Q<?= (int) ($questionNumberById[(int) $slide['question_id']] ?? 0) ?> &mdash; answer slide</option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$questionAnswerSlides): ?>
                        <p class="muted" style="margin:8px 0 0;">No question-linked answer slides configured.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="op-panel" data-op-component="round-control">
                <h2>Round Control</h2>
                <div class="op-nav-buttons">
                    <select id="op-round-select" class="btn">
                        <?php foreach (Round::ALL as $r): ?>
                            <option value="<?= $r ?>"><?= htmlspecialchars(Round::label($r)) ?> Round</option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-primary" id="op-btn-start-round">Start Round</button>
                    <button type="button" class="btn" id="op-btn-end-round">End Round</button>
                </div>
                <p class="hint" style="margin-top:10px;">Starting a round jumps the display to that round's first question. Clincher questions can only be scored for the teams selected in the admin panel.</p>
            </div>

            <div class="op-panel" data-op-component="ranking-control">
                <h2>Ranking control</h2>
                <div class="op-nav-buttons">
                    <button type="button" class="btn btn-primary" id="op-btn-show-round-ranking">Show Round Rankings</button>
                    <button type="button" class="btn btn-primary" id="op-btn-show-ranking">Show Final Rankings</button>
                </div>
                <p class="hint" style="margin-top:10px;">Round Rankings shows the current round's breakdown; Final Rankings is the cumulative, all-rounds standings.</p>
                <div class="op-nav-buttons" style="margin-top:10px;">
                    <button type="button" class="btn" id="op-btn-return-to-question">Return to question</button>
                    <button type="button" class="btn" id="op-btn-return-to-cover">Return to cover</button>
                </div>
                <p class="hint" id="op-auto-rank-hint" style="display:none;margin-top:10px;">This event is configured to automatically show the ranking after each score is entered.</p>
            </div>

            <div class="op-panel" data-op-component="promotional-slides">
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
        <div class="op-panel op-panel--wide" data-op-component="results" id="op-results-panel" style="display:none;">
            <h2>Question Results &mdash; <span id="op-results-round-label">—</span></h2>
            <div class="op-results-info">
                <span>Most recently scored: <strong id="op-results-last-q">—</strong></span>
            </div>
            <div class="op-results-matrix-wrap" id="op-results-matrix-wrap">
                <p class="muted">Click &ldquo;Show Results&rdquo; to display the round breakdown.</p>
            </div>
            <div class="op-nav-buttons" style="margin-top:14px;">
                <button type="button" class="btn" id="op-btn-close-results">Close</button>
                <select id="op-results-round-select" class="btn">
                    <?php foreach (Round::ALL as $r): ?>
                        <option value="<?= $r ?>"><?= htmlspecialchars(Round::label($r)) ?> Round</option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-primary" id="op-btn-refresh-results">Refresh</button>
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
