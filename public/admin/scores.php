<?php
require_once __DIR__ . '/../../src/bootstrap.php';
Auth::requireAdmin();

$eventId = (int) ($_GET['id'] ?? 0);
$event = $eventId > 0 ? Event::find($eventId) : null;
if ($event === null) {
    Flash::error('That event could not be found.');
    header('Location: /admin/index.php');
    exit;
}

$settings = EventSetting::forEvent($eventId);
$categories = Category::forEvent($eventId);
$questions = Question::forEvent($eventId, true);   // active only, in display order
$contestants = Contestant::forEvent($eventId, true); // active only, in display order

// Group active questions under their category, preserving category display
// order; questions with no category (or a category that's since been
// deleted) fall into a trailing "Uncategorized" group. Categories with zero
// active questions are dropped so the table doesn't grow an empty column
// group for them.
$groups = [];
foreach ($categories as $cat) {
    $groups[$cat['id']] = ['id' => $cat['id'], 'name' => $cat['name'], 'questions' => []];
}
$uncategorized = ['id' => null, 'name' => 'Uncategorized', 'questions' => []];
foreach ($questions as $q) {
    if ($q['category_id'] !== null && isset($groups[$q['category_id']])) {
        $groups[$q['category_id']]['questions'][] = $q;
    } else {
        $uncategorized['questions'][] = $q;
    }
}
$groups = array_values(array_filter($groups, fn ($g) => $g['questions'] !== []));
if ($uncategorized['questions'] !== []) {
    $groups[] = $uncategorized;
}

// Score lookup: [contestant_id][question_id] => score_entries row (or absent
// if that question/contestant pair hasn't been scored yet).
$matrix = [];
foreach (ScoreEntry::forEvent($eventId) as $s) {
    $matrix[$s['contestant_id']][$s['question_id']] = $s;
}

// Rank comes from the same ranking engine the operator/public display use,
// so this report can never disagree with what's shown live.
$rankByContestant = [];
foreach (ScoreEntry::rankingForEvent($eventId) as $r) {
    $rankByContestant[$r['contestant_id']] = $r['rank'];
}

function scoreboard_cell($entry): string
{
    if ($entry === null) {
        return '<span class="muted">—</span>';
    }
    $resultClass = match ($entry['result']) {
        AnswerResult::CORRECT => 'sb-correct',
        AnswerResult::INCORRECT => 'sb-incorrect',
        AnswerResult::NO_ANSWER => 'sb-noanswer',
        default => 'sb-adjustment',
    };
    return '<span class="sb-cell ' . $resultClass . '">' . (int) $entry['points_awarded'] . '</span>';
}

$pageTitle = $event['name'] . ' — Scores';
require __DIR__ . '/includes/header.php';
?>
<style>
    .sb-scroll { overflow-x: auto; }
    table.sb-table { border-collapse: collapse; font-size: 0.88rem; white-space: nowrap; }
    table.sb-table th, table.sb-table td { border: 1px solid var(--color-border); padding: 7px 12px; text-align: center; }
    table.sb-table th.sb-name-col, table.sb-table td.sb-name-col { text-align: left; position: sticky; left: 0; background: var(--color-surface); z-index: 1; }
    table.sb-table thead th { background: #f4f5f7; font-weight: 700; }
    table.sb-table th.sb-group-header { background: #eef0f3; text-transform: uppercase; letter-spacing: 0.03em; font-size: 0.75rem; }
    td.sb-subtotal, th.sb-subtotal-header { background: #f7f8fa; font-weight: 700; }
    td.sb-grand-total { background: var(--color-primary); color: #fff; font-weight: 800; font-size: 0.95rem; }
    th.sb-grand-total-header { background: #1a3fd6; color: #fff; }
    .sb-cell { display: inline-block; min-width: 1.6em; font-weight: 700; }
    .sb-correct { color: var(--color-success); }
    .sb-incorrect { color: var(--color-danger); }
    .sb-noanswer { color: var(--color-muted); font-weight: 500; }
    .sb-adjustment { color: #a15c00; }
    .sb-legend { display: flex; gap: 18px; flex-wrap: wrap; font-size: 0.82rem; margin-bottom: 14px; }
    .sb-legend span { display: inline-flex; align-items: center; gap: 6px; }
    .sb-rank-col { font-weight: 800; color: var(--color-muted); }
</style>

<div class="event-header">
    <div>
        <h1><?= htmlspecialchars($event['name']) ?> — Scores</h1>
        <p class="subtitle">Every recorded result, grouped by category, with subtotals and grand totals.</p>
    </div>
    <div>
        <a href="/admin/event.php?id=<?= $eventId ?>" class="btn btn-small">&larr; Back to event</a>
    </div>
</div>

<div class="sb-legend">
    <span><span class="sb-cell sb-correct">10</span> Correct</span>
    <span><span class="sb-cell sb-incorrect">0</span> Incorrect</span>
    <span><span class="sb-cell sb-noanswer">0</span> No answer</span>
    <span><span class="sb-cell sb-adjustment">±</span> Manual adjustment</span>
    <span><span class="muted">—</span> Not yet scored</span>
</div>

<?php if ($questions === [] || $contestants === []): ?>
    <div class="card">
        <p class="muted">This event needs at least one active contestant and one active question before a score breakdown can be shown.</p>
    </div>
<?php else: ?>
<div class="card sb-scroll">
    <table class="sb-table">
        <thead>
            <tr>
                <th class="sb-name-col" rowspan="2">Contestant</th>
                <?php foreach ($groups as $g): ?>
                    <th class="sb-group-header" colspan="<?= count($g['questions']) ?>"><?= htmlspecialchars($g['name']) ?></th>
                    <th class="sb-subtotal-header" rowspan="2">Subtotal<br><span class="muted" style="font-weight:400;"><?= htmlspecialchars($g['name']) ?></span></th>
                <?php endforeach; ?>
                <th class="sb-grand-total-header" rowspan="2">Grand<br>Total</th>
                <th rowspan="2">Rank</th>
            </tr>
            <tr>
                <?php foreach ($groups as $g): ?>
                    <?php foreach ($g['questions'] as $q): ?>
                        <th>Q<?= (int) $q['question_number'] ?></th>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($contestants as $c): ?>
            <?php
                $grandTotal = (int) $c['starting_score'];
            ?>
            <tr>
                <td class="sb-name-col"><?= htmlspecialchars($c['name']) ?><?php if ($c['team_code']): ?> <span class="muted">(<?= htmlspecialchars($c['team_code']) ?>)</span><?php endif; ?></td>
                <?php foreach ($groups as $g): ?>
                    <?php
                        $subtotal = 0;
                        $cellsHtml = '';
                        foreach ($g['questions'] as $q) {
                            $entry = $matrix[$c['id']][$q['id']] ?? null;
                            if ($entry !== null) {
                                $subtotal += (int) $entry['points_awarded'];
                            }
                            $cellsHtml .= '<td>' . scoreboard_cell($entry) . '</td>';
                        }
                        $grandTotal += $subtotal;
                        echo $cellsHtml;
                    ?>
                    <td class="sb-subtotal"><?= $subtotal ?></td>
                <?php endforeach; ?>
                <td class="sb-grand-total"><?= $grandTotal ?></td>
                <td class="sb-rank-col">#<?= (int) ($rankByContestant[$c['id']] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php if (array_sum(array_column($contestants, 'starting_score')) != 0): ?>
<p class="hint">Grand Total includes each contestant's configured starting score, matching the live ranking.</p>
<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>