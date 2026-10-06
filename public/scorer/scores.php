<?php
require_once __DIR__ . '/../../src/bootstrap.php';
Auth::requireAdmin();

$eventId = (int) ($_GET['id'] ?? 0);
$event = $eventId > 0 ? Event::find($eventId) : null;
if ($event === null) {
    Flash::error('That event could not be found.');
    header('Location: ' . relative_url('/scorer/index.php'));
    exit;
}

$dashboard = CompetitionRuntime::getDashboard($eventId);
$settings = EventSetting::forEvent($eventId);
$contestants = Contestant::forEvent($eventId);
$questions = Question::forEvent($eventId, true);

$pageTitle = $event['name'] . ' — Scorer';
require __DIR__ . '/../admin/includes/header.php';
?>
<link rel="stylesheet" href="assets/scorer.css">

<div id="scorer-root"
     data-event-id="<?= $eventId ?>"
     data-csrf="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>"
     data-initial='<?= htmlspecialchars(json_encode($dashboard), ENT_QUOTES) ?>'
     data-poll-ms="1000">

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
            <button type="button" class="btn btn-small" id="scorer-btn-export-excel">Export Excel</button>
            <button type="button" class="btn btn-small" id="scorer-btn-export-pdf">Export PDF</button>
        </div>
    </div>

    <div class="scorer-layout">
        <!-- LEFT: Live Scoreboard -->
        <div class="scorer-main">
            <div class="scorer-section">
                <h2>Live Scoreboard</h2>
                <div class="scorer-status-bar" id="scorer-status-bar">
                    <span id="scorer-event-status">Not Started</span>
                    <span id="scorer-question-progress" class="muted"></span>
                </div>
                <div class="scorer-table-wrap">
                    <table class="scorer-table" id="scorer-table">
                        <thead>
                            <tr>
                                <th class="col-rank">Rank</th>
                                <th class="col-number">#</th>
                                <th class="col-acronym">Acro.</th>
                                <th class="col-name">Team/Contestant</th>
                                <th class="col-total">Total</th>
                            </tr>
                        </thead>
                        <tbody id="scorer-table-body">
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- QUESTION DETAIL SECTION -->
            <div class="scorer-section" id="scorer-question-section">
                <h2>Current Question Detail</h2>
                <div id="scorer-question-detail">
                    <p class="muted">No question currently active.</p>
                </div>
                <div id="scorer-question-scores" class="scorer-question-scores"></div>
            </div>

            <!-- ROUND BREAKDOWN -->
            <div class="scorer-section" id="scorer-round-section">
                <h2>Round Breakdown</h2>
                <div id="scorer-round-breakdown">
                    <p class="muted">Round data will appear here once scoring begins.</p>
                </div>
            </div>
        </div>

        <!-- RIGHT: Quick Actions -->
        <div class="scorer-sidebar">
            <div class="scorer-panel">
                <h3>Quick Reference</h3>
                <div class="scorer-ref-info">
                    <p><strong>Contestants:</strong> <span id="ref-contestants">0</span></p>
                    <p><strong>Questions:</strong> <span id="ref-questions">0</span></p>
                    <p><strong>Points per Q:</strong> <span id="ref-points">0</span></p>
                    <p><strong>Time per Q:</strong> <span id="ref-time">0s</span></p>
                </div>
            </div>

            <div class="scorer-panel">
                <h3>Score Entry (Quick)</h3>
                <p class="hint">Select a contestant and mark their result for the current question.</p>
                <div id="scorer-quick-score">
                    <p class="muted">Start a question to begin scoring.</p>
                </div>
            </div>

            <div class="scorer-panel">
                <h3>Statistics</h3>
                <div class="scorer-stats" id="scorer-stats">
                    <div class="stat-row">
                        <span>Questions Answered:</span>
                        <strong id="stat-questions-answered">0</strong>
                    </div>
                    <div class="stat-row">
                        <span>Total Points Awarded:</span>
                        <strong id="stat-total-points">0</strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="scorer-footer">
        <p>Scorer Interface by <strong>Developer Name</strong> &middot; Powered by Sci-Math Competition System</p>
    </footer>
</div>

<script src="assets/scorer.js"></script>

<?php require __DIR__ . '/../admin/includes/footer.php'; ?>
