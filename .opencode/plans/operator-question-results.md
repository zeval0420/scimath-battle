# Operator: Question Results Highlighting

## Goal
Add three main operator buttons (Show Question, Show Answer, Show Results) in the Question Controls section, and display a round breakdown matrix with green/red cell highlighting for the most recently-scored question.

---

## Changes

### 1. `src/Models/ScoreEntry.php`
Add method to find the most recently scored question:

```php
public static function lastScoredQuestionId(int $eventId): ?int
{
    $sql = <<<SQL
        SELECT question_id
        FROM scimath_score_entries
        WHERE event_id = :event_id
        ORDER BY scored_at DESC
        LIMIT 1
    SQL;

    $stmt = self::db()->prepare($sql);
    $stmt->execute(['event_id' => $eventId]);
    $row = $stmt->fetch();

    return $row !== false ? (int) $row['question_id'] : null;
}
```

### 2. `src/Services/CompetitionRuntime.php`
In `getDashboard()` (line ~610), add `last_scored_question_id` to the returned array:

```php
return [
    'state'                   => $state,
    'current_question_scores' => $currentQuestionScores,
    'rankings'                => self::getRankings($eventId),
    'all_scores'              => $allScores,
    'last_scored_question_id' => ScoreEntry::lastScoredQuestionId($eventId),
];
```

### 3. `public/operator/event.php`
In the "Question Controls" panel, after the existing slide preview strip and above the show/hide toggle, add three prominent action buttons:

```php
<div class="op-action-buttons">
    <button type="button" class="btn btn-primary op-action-btn" id="op-btn-show-question">Show Question</button>
    <button type="button" class="btn btn-primary op-action-btn" id="op-btn-show-answer">Show Answer</button>
    <button type="button" class="btn btn-success op-action-btn" id="op-btn-show-results">Show Results</button>
</div>
```

Add a new panel below the existing panel row (after the promotional slides panel, before the closing `</div class="op-wrap">`):

```php
<div class="op-panel op-panel--wide" data-op-component="results" id="op-results-panel" style="display:none;">
    <h2>Question Results — <span id="op-results-round-label">—</span></h2>
    <div class="op-results-info">
        <span>Most recent: <strong id="op-results-last-q">—</strong></span>
    </div>
    <div class="op-results-matrix-wrap" id="op-results-matrix-wrap">
        <p class="muted">Select a round above or wait for results to load.</p>
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
```

Also pass `last_scored_question_id` from the dashboard to the JS via data attribute:

```html
data-last-scored-question-id='<?= $dashboard['last_scored_question_id'] ?? "null" ?>'
```

### 4. `public/assets/operator.css`
Add styles for the new action buttons and highlighted matrix:

```css
/* ─── ACTION BUTTONS ─────────────────────────────────────── */
.op-action-buttons {
    display: flex;
    gap: 10px;
    margin: 16px 0;
    flex-wrap: wrap;
}
.op-action-btn {
    flex: 1;
    min-width: 140px;
    padding: 12px 16px;
    font-size: 0.95rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    border: none;
    border-radius: var(--radius-btn);
    cursor: pointer;
    transition: background 0.15s ease, transform 0.1s ease;
}
.op-action-btn:hover:not(:disabled) { transform: translateY(-1px); }
.op-action-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.op-action-btn.btn-success { background: var(--success); color: #fff; }
.op-action-btn.btn-success:hover:not(:disabled) { background: #15803d; }

/* ─── RESULTS PANEL ──────────────────────────────────────── */
.op-results-info {
    font-size: 0.88rem;
    color: var(--text-secondary);
    margin-bottom: 12px;
}
.op-results-matrix-wrap {
    overflow-x: auto;
    margin-bottom: 14px;
}
.op-result-matrix {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.88rem;
    min-width: 400px;
}
.op-result-matrix th,
.op-result-matrix td {
    padding: 8px 10px;
    border: 1px solid var(--border);
    text-align: center;
}
.op-result-matrix th {
    background: var(--bg-elevated);
    font-weight: 700;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-secondary);
}
.op-result-matrix .name-col {
    text-align: left;
    font-weight: 600;
    position: sticky;
    left: 0;
    background: var(--bg-card);
    z-index: 1;
}
.op-result-matrix .round-group {
    text-align: center;
}
.op-result-matrix .total-col {
    font-weight: 700;
    background: var(--bg-elevated);
}

/* Highlighted cells for the most recent question */
.op-result-matrix .highlight-col {
    background: rgba(59, 130, 246, 0.08);
}
.op-result-matrix td.highlight-correct {
    background: #dcfce7;
    color: #166534;
    font-weight: 700;
}
.op-result-matrix td.highlight-incorrect {
    background: #fee2e2;
    color: #991b1b;
    font-weight: 700;
}
.op-result-matrix td.highlight-no-answer {
    background: #f1f5f9;
    color: #64748b;
}
.op-result-matrix td.highlight-col.highlight-correct,
.op-result-matrix td.highlight-col.highlight-incorrect,
.op-result-matrix td.highlight-col.highlight-no-answer {
    background: inherit; /* keep highlight from parent col, add text color */
}
```

### 5. `public/assets/operator.js`
Add the following logic:

**Read initial data:**
```javascript
var lastScoredQuestionId = parseInt(root.getAttribute('data-last-scored-question-id'), 10) || null;
```

**Render function additions** (inside `render()`, after existing code):
```javascript
// Update last-scored question display in results panel
var lastQEl = document.getElementById('op-results-last-q');
if (lastQEl) {
    if (lastScoredQuestionId) {
        var lastQ = state.current_question && String(state.current_question.id) === String(lastScoredQuestionId)
            ? state.current_question
            : findQuestionById(allQuestions, lastScoredQuestionId);
        lastQEl.textContent = lastQ
            ? 'Q' + lastQ.question_number + (lastQ.round ? ' (' + roundLabel(lastQ.round) + ')' : '')
            : '—';
    } else {
        lastQEl.textContent = '—';
    }
}
```

**Add helper to find question by ID:**
```javascript
function findQuestionById(questions, id) {
    for (var i = 0; i < questions.length; i++) {
        if (String(questions[i].id) === String(id)) return questions[i];
    }
    return null;
}
```

**Render results matrix (called when results panel is shown):**
```javascript
function renderResultsPanel() {
    var wrap = document.getElementById('op-results-matrix-wrap');
    if (!wrap) return;

    var round = document.getElementById('op-results-round-select') ? document.getElementById('op-results-round-select').value : (dashboard.state.current_round || 'easy');
    var roundLabelEl = document.getElementById('op-results-round-label');
    if (roundLabelEl) roundLabelEl.textContent = roundLabel(round) + ' Round';

    // Fetch report data
    apiGet('report').then(function (res) {
        if (!res.ok || !res.report) return;
        var report = res.report;
        var questions = report.questions || [];
        var contestants = report.contestants || [];
        var cells = report.cells || {};

        // Filter to selected round
        var ORDER = ['easy', 'medium', 'hard', 'clincher'];
        var roundQuestions = questions.filter(function (q) { return q.round === round; });
        if (roundQuestions.length === 0) {
            wrap.innerHTML = '<p class="muted">No ' + roundLabel(round) + ' questions configured.</p>';
            return;
        }

        // Build matrix
        var headTop = '<th class="name-col" rowspan="2">Team</th>';
        headTop += '<th class="round-group" colspan="' + roundQuestions.length + '">' + roundLabel(round) + '</th>';
        headTop += '<th class="total-col" rowspan="2">Total</th>';

        var headBottom = '';
        roundQuestions.forEach(function (q) {
            var isHighlight = lastScoredQuestionId && String(q.id) === String(lastScoredQuestionId);
            headBottom += '<th class="' + (isHighlight ? 'highlight-col' : '') + '">Q' + q.question_number + '</th>';
        });

        var body = contestants.map(function (c) {
            var cellsHtml = '<td class="name-col">' + escapeHtml(c.name) + '</td>';
            roundQuestions.forEach(function (q) {
                var entry = cells[c.id] && cells[c.id][q.id];
                var isHighlight = lastScoredQuestionId && String(q.id) === String(lastScoredQuestionId);
                var resultClass = '';
                if (isHighlight && entry) {
                    if (entry.result === 'correct') resultClass = 'highlight-correct';
                    else if (entry.result === 'incorrect' || entry.result === 'no_answer') resultClass = 'highlight-' + entry.result;
                }
                var val = entry ? entry.points_awarded : '—';
                cellsHtml += '<td class="' + (isHighlight ? 'highlight-col ' : '') + resultClass + '">' + val + '</td>';
            });
            cellsHtml += '<td class="total-col">' + c.total + '</td>';
            return '<tr>' + cellsHtml + '</tr>';
        }).join('');

        wrap.innerHTML = '<table class="op-result-matrix">'
            + '<thead><tr>' + headTop + '</tr><tr>' + headBottom + '</tr></thead>'
            + '<tbody>' + body + '</tbody>'
            + '</table>';
    }).catch(function () {});
}
```

**Button event listeners:**
```javascript
// Show Question button
document.getElementById('op-btn-show-question').addEventListener('click', function (e) {
    doAction('show_question', {}, e.currentTarget);
});

// Show Answer button
document.getElementById('op-btn-show-answer').addEventListener('click', function (e) {
    doAction('show_answer', { question_id: dashboard.state.current_question ? dashboard.state.current_question.id : null }, e.currentTarget);
});

// Show Results button
document.getElementById('op-btn-show-results').addEventListener('click', function (e) {
    var round = document.getElementById('op-round-select').value || dashboard.state.current_round || 'easy';
    doAction('show_round_ranking', { round: round }, e.currentTarget).then(function () {
        var panel = document.getElementById('op-results-panel');
        if (panel) { panel.style.display = ''; renderResultsPanel(); }
    });
});

// Close Results button
document.getElementById('op-btn-close-results').addEventListener('click', function () {
    var panel = document.getElementById('op-results-panel');
    if (panel) panel.style.display = 'none';
});

// Refresh Results button
document.getElementById('op-btn-refresh-results').addEventListener('click', function () {
    renderResultsPanel();
});

// Round select change in results panel
document.getElementById('op-results-round-select').addEventListener('change', function () {
    renderResultsPanel();
});
```

**Update doAction to also refresh lastScoredQuestionId when scoring happens:**
```javascript
// In the scoring response handler within doAction, after res is received:
if (res.last_scored_question_id !== undefined) {
    lastScoredQuestionId = res.last_scored_question_id;
}
```

Note: The `score` action already returns scores and rankings. We need to also return `last_scored_question_id` from the score endpoint in `runtime.php`:

In `public/api/runtime.php`, in the `score` case, add `'last_scored_question_id' => ScoreEntry::lastScoredQuestionId($eventId)` to the response.

---

## File List
1. `src/Models/ScoreEntry.php` — add `lastScoredQuestionId()`
2. `src/Services/CompetitionRuntime.php` — add `last_scored_question_id` to `getDashboard()`
3. `public/api/runtime.php` — add `last_scored_question_id` to score response
4. `public/operator/event.php` — add 3 buttons + results panel HTML
5. `public/assets/operator.css` — add button + matrix styles
6. `public/assets/operator.js` — add rendering + event handlers
