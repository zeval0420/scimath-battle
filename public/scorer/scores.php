<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Support/ScoreReport.php';
Auth::requireAdmin();

$eventId = (int) ($_GET['id'] ?? 0);
$event = $eventId > 0 ? Event::find($eventId) : null;
if ($event === null) {
    Flash::error('That event could not be found.');
    header('Location: ' . relative_url('/scorer/index.php'));
    exit;
}

$settings = EventSetting::forEvent($eventId);
$categoryNames = [];
foreach (Category::forEvent($eventId) as $cat) {
    $categoryNames[(int) $cat['id']] = $cat['name'];
}

$report = ScoreReport::build($eventId);

// Default the "selected item" to whatever the runtime is currently showing,
// falling back to the first active question.
$session = CompetitionSession::forEvent($eventId);
$currentQuestionId = $session !== null && $session['current_question_id'] !== null
    ? (int) $session['current_question_id']
    : null;

$selectedQuestionId = null;
foreach ($report['questions'] as $q) {
    if ((int) $q['id'] === $currentQuestionId) {
        $selectedQuestionId = (int) $q['id'];
        break;
    }
}
if ($selectedQuestionId === null && $report['questions'] !== []) {
    $selectedQuestionId = (int) $report['questions'][0]['id'];
}

// Everything the JS needs, built once server-side so the initial paint and the
// live updates share one shape.
$questionsForJs = [];
foreach ($report['questions'] as $q) {
    $questionsForJs[] = [
        'id'              => (int) $q['id'],
        'question_number' => (int) $q['question_number'],
        'category'        => $q['category_id'] !== null ? ($categoryNames[(int) $q['category_id']] ?? null) : null,
        'round'           => $q['round'] ?? Round::EASY,
        'points'          => $settings !== null ? Question::effectivePoints($q, $settings) : (int) ($q['points'] ?? 0),
    ];
}
$contestantsForJs = [];
foreach ($report['contestants'] as $c) {
    $contestantsForJs[] = [
        'id'             => (int) $c['id'],
        'name'           => $c['name'],
        'acronym'        => $c['acronym'],
        'team_code'      => $c['team_code'],
        'starting_score' => (int) $c['starting_score'],
        'is_clincher'    => (int) ($c['is_clincher'] ?? 0),
    ];
}

// Group the questions by round (canonical order) for the "select item"
// dropdown, so the operator picks an item within its round.
$questionsByRound = [];
foreach ($questionsForJs as $qj) {
    $questionsByRound[$qj['round']][] = $qj;
}
$roundOrder = array_values(array_filter(Round::ALL, fn ($r) => isset($questionsByRound[$r])));

$initial = [
    'selected_question_id' => $selectedQuestionId,
    'questions'            => $questionsForJs,
    'contestants'          => $contestantsForJs,
    'cells'                => $report['cells'],
    'ranks'                => $report['ranks'],
    'totals'               => $report['totals'],
    'question_sums'        => $report['question_sums'],
];

$pageTitle = $event['name'] . ' — Scorer';
require __DIR__ . '/../admin/includes/header.php';
?>
<link rel="stylesheet" href="assets/scorer.css">

<div id="scorer-root"
     data-event-id="<?= $eventId ?>"
     data-csrf="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>"
     data-initial='<?= htmlspecialchars(json_encode($initial), ENT_QUOTES) ?>'
     data-poll-ms="3000">

    <div class="scorer-topbar">
        <div class="scorer-event-info">
            <a href="index.php" class="scorer-back-btn">&larr; All Events</a>
            <div class="scorer-event-title">
                <h1><?= htmlspecialchars($event['name']) ?></h1>
                <span class="badge badge-<?= htmlspecialchars($event['status']) ?>"><?= htmlspecialchars($event['status']) ?></span>
            </div>
        </div>
        <div class="scorer-controls">
            <button type="button" class="btn btn-small" id="scorer-btn-refresh">Refresh</button>
            <a class="btn btn-small" href="../api/export_scores.php?event_id=<?= $eventId ?>&format=excel">Export Excel</a>
            <a class="btn btn-small" href="../api/export_scores.php?event_id=<?= $eventId ?>&format=pdf" target="_blank" rel="noopener">Export PDF</a>
        </div>
    </div>

    <div class="scorer-layout">
        <div class="scorer-main">
            <!-- 1. SELECT ITEM -->
            <div class="scorer-section">
                <h2>1. Select Item (Question)</h2>
                <div class="scorer-item-select">
                    <label for="scorer-question-select">Question / Item</label>
                    <select id="scorer-question-select">
                        <?php if ($questionsForJs === []): ?>
                            <option value="">No active questions</option>
                        <?php else: ?>
                            <?php foreach ($roundOrder as $r): ?>
                                <optgroup label="<?= htmlspecialchars(Round::label($r)) ?> Round">
                                    <?php foreach ($questionsByRound[$r] as $q): ?>
                                        <?php
                                            $qid = (int) $q['id'];
                                            $label = 'Q' . (int) $q['question_number']
                                                . ($q['category'] !== null ? ' — ' . $q['category'] : '')
                                                . ' (' . $q['points'] . ' pts)';
                                        ?>
                                        <option value="<?= $qid ?>" <?= $qid === $selectedQuestionId ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <span id="scorer-item-summary" class="muted"></span>
                    <span id="scorer-round-badge" class="scorer-round-badge" style="display:none;"></span>
                </div>
            </div>

            <!-- 2. MARK ONE BY ONE -->
            <div class="scorer-section">
                <h2>2. Mark Results <span class="muted" id="scorer-mark-progress"></span></h2>
                <p class="hint">Select a contestant's result one by one. Left click records immediately.</p>
                <div id="scorer-quick-score" class="scorer-quick-score">
                    <p class="muted">Select an item above to begin marking.</p>
                </div>
            </div>

            <!-- QUIZ BEE TABLE -->
            <div class="scorer-section">
                <h2>Quiz Bee Table</h2>
                <div class="scorer-table-wrap" id="scorer-matrix-wrap"></div>
            </div>
        </div>

        <div class="scorer-sidebar">
            <div class="scorer-panel">
                <h3>Live Scoreboard</h3>
                <div class="scorer-table-wrap">
                    <table class="scorer-table">
                        <thead>
                            <tr>
                                <th class="col-rank">Rank</th>
                                <th class="col-acronym">Acro.</th>
                                <th class="col-name">Team/Contestant</th>
                                <th class="col-total">Total</th>
                            </tr>
                        </thead>
                        <tbody id="scorer-table-body"></tbody>
                    </table>
                </div>
            </div>

            <div class="scorer-panel">
                <h3>Quick Reference</h3>
                <div class="scorer-ref-info">
                    <p><strong>Contestants:</strong> <span id="ref-contestants">0</span></p>
                    <p><strong>Questions:</strong> <span id="ref-questions">0</span></p>
                    <p><strong>Points per Q:</strong> <span id="ref-points">—</span></p>
                </div>
            </div>

            <div class="scorer-panel">
                <h3>Statistics</h3>
                <div class="scorer-stats">
                    <div class="stat-row">
                        <span>Marked (this item):</span>
                        <strong id="stat-marked">0</strong>
                    </div>
                    <div class="stat-row">
                        <span>Correct (this item):</span>
                        <strong id="stat-correct">0</strong>
                    </div>
                    <div class="stat-row">
                        <span>Total points awarded:</span>
                        <strong id="stat-total-points">0</strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="scorer-footer">
        <p>Scorer Interface &middot; Powered by Sci-Math Competition System</p>
    </footer>
</div>

<script src="assets/scorer.js"></script>

<?php require __DIR__ . '/../admin/includes/footer.php'; ?>
