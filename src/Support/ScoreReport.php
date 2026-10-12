<?php

require_once __DIR__ . '/../Models/Event.php';
require_once __DIR__ . '/../Models/Contestant.php';
require_once __DIR__ . '/../Models/Question.php';
require_once __DIR__ . '/../Models/ScoreEntry.php';
require_once __DIR__ . '/../../config/constants.php';

/**
 * Builds the plain "quiz bee" score table (rows = contestants, columns =
 * questions, final column = total) and renders it as a downloadable CSV
 * (Excel-compatible) or a self-contained printable HTML report (for
 * Print / Save-as-PDF from the browser).
 *
 * Read-only: this never mutates scores. Both the scorer page and the export
 * endpoint build from this one place so the on-screen table and the exported
 * report can never disagree.
 */
class ScoreReport
{
    /**
     * @return array{
     *   event: array,
     *   contestants: array,
     *   questions: array,
     *   cells: array<int, array<int, array>>,
     *   ranks: array<int, int>,
     *   totals: array<int, int>,
     *   question_sums: array<int, int>
     * }
     */
    public static function build(int $eventId): array
    {
        $event = Event::findOrFail($eventId);
        $contestants = Contestant::forEvent($eventId, true);
        $questions = Question::forEvent($eventId, true);

        $cells = [];
        foreach (ScoreEntry::forEvent($eventId) as $entry) {
            $cells[(int) $entry['contestant_id']][(int) $entry['question_id']] = $entry;
        }

        $ranks = [];
        foreach (ScoreEntry::rankingForEvent($eventId) as $row) {
            $ranks[(int) $row['contestant_id']] = (int) $row['rank'];
        }

        $totals = [];
        $questionSums = [];
        foreach ($contestants as $contestant) {
            $cid = (int) $contestant['id'];
            $sum = 0;
            foreach ($questions as $question) {
                $qid = (int) $question['id'];
                $entry = $cells[$cid][$qid] ?? null;
                if ($entry !== null) {
                    $points = (int) $entry['points_awarded'];
                    $sum += $points;
                    $questionSums[$qid] = ($questionSums[$qid] ?? 0) + $points;
                }
            }
            $totals[$cid] = (int) $contestant['starting_score'] + $sum;
        }

        return [
            'event'         => $event,
            'contestants'   => $contestants,
            'questions'     => $questions,
            'cells'         => $cells,
            'ranks'         => $ranks,
            'totals'        => $totals,
            'question_sums' => $questionSums,
        ];
    }

    public static function contestantLabel(array $contestant): string
    {
        $label = (string) $contestant['name'];
        $tag = $contestant['acronym'] ?: $contestant['team_code'];
        if ($tag !== null && $tag !== '') {
            $label .= ' (' . $tag . ')';
        }

        return $label;
    }

    public static function filename(array $report, string $extension): string
    {
        $safe = preg_replace('/[^A-Za-z0-9]+/', '_', (string) $report['event']['name']);
        $safe = trim((string) $safe, '_');

        return 'scimath_scores_' . ($safe !== '' ? $safe : 'event') . '.' . $extension;
    }

    /**
     * Streams the report as CSV (opens directly in Excel / Google Sheets).
     */
    public static function streamCsv(array $report): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . self::filename($report, 'csv') . '"');
        header('Cache-Control: no-store');

        $out = fopen('php://output', 'w');

        // UTF-8 BOM so Excel detects the encoding correctly.
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, [$report['event']['name'] . ' — Score Report']);
        fputcsv($out, ['Generated', date('Y-m-d H:i:s')]);
        fputcsv($out, []);

        $header = ['Contestant'];
        foreach ($report['questions'] as $question) {
            $header[] = 'Q' . (int) $question['question_number'];
        }
        $header[] = 'Total';
        $header[] = 'Rank';
        fputcsv($out, $header);

        foreach ($report['contestants'] as $contestant) {
            $cid = (int) $contestant['id'];
            $row = [self::contestantLabel($contestant)];
            foreach ($report['questions'] as $question) {
                $entry = $report['cells'][$cid][(int) $question['id']] ?? null;
                $row[] = $entry === null ? '' : (int) $entry['points_awarded'];
            }
            $row[] = $report['totals'][$cid] ?? 0;
            $row[] = $report['ranks'][$cid] ?? '';
            fputcsv($out, $row);
        }

        fputcsv($out, []);
        $footer = ['Total per question'];
        foreach ($report['questions'] as $question) {
            $footer[] = $report['question_sums'][(int) $question['id']] ?? 0;
        }
        fputcsv($out, $footer);

        fclose($out);
    }

    /**
     * Renders a standalone, printable HTML report. With $autoPrint true it
     * opens the browser's print dialog on load, where the user can choose
     * "Save as PDF" -- the plain-table output stays identical either way.
     */
    public static function renderPrintable(array $report, bool $autoPrint = true): void
    {
        $event = $report['event'];
        $esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $esc($event['name']) ?> — Score Report</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: Arial, Helvetica, sans-serif; color: #111; margin: 24px; }
    .report-head { margin-bottom: 16px; }
    .report-head h1 { margin: 0 0 4px; font-size: 22px; }
    .report-head .sub { color: #555; margin: 0; }
    .report-head .meta { color: #777; font-size: 12px; margin-top: 6px; }
    table { border-collapse: collapse; width: 100%; font-size: 13px; }
    th, td { border: 1px solid #333; padding: 6px 10px; text-align: center; }
    th { background: #f0f0f0; }
    th.name, td.name { text-align: left; white-space: nowrap; }
    td.total, th.total { font-weight: bold; background: #fafafa; }
    tfoot td { font-weight: bold; background: #f0f0f0; }
    .toolbar { margin-bottom: 16px; }
    .toolbar button { padding: 8px 16px; font-size: 14px; cursor: pointer; }
    .empty { color: #777; font-style: italic; }
    @media print {
        body { margin: 0; }
        .toolbar { display: none; }
        @page { margin: 12mm; }
    }
</style>
</head>
<body>
<div class="toolbar">
    <button type="button" onclick="window.print()">Print / Save as PDF</button>
</div>

<div class="report-head">
    <h1><?= $esc($event['name']) ?> — Score Report</h1>
    <?php if (!empty($event['subtitle'])): ?><p class="sub"><?= $esc($event['subtitle']) ?></p><?php endif; ?>
    <p class="meta">
        <?php if (!empty($event['event_date'])): ?>Event date: <?= $esc($event['event_date']) ?> &middot; <?php endif; ?>
        Generated: <?= $esc(date('Y-m-d H:i:s')) ?>
    </p>
</div>

<?php if ($report['questions'] === [] || $report['contestants'] === []): ?>
    <p class="empty">This event does not yet have active contestants and questions to report on.</p>
<?php else: ?>
<table>
    <thead>
        <tr>
            <th class="name">Contestant</th>
            <?php foreach ($report['questions'] as $question): ?>
                <th>Q<?= (int) $question['question_number'] ?></th>
            <?php endforeach; ?>
            <th class="total">Total</th>
            <th>Rank</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($report['contestants'] as $contestant): ?>
        <?php $cid = (int) $contestant['id']; ?>
        <tr>
            <td class="name"><?= $esc(self::contestantLabel($contestant)) ?></td>
            <?php foreach ($report['questions'] as $question): ?>
                <?php $entry = $report['cells'][$cid][(int) $question['id']] ?? null; ?>
                <td><?= $entry === null ? '—' : (int) $entry['points_awarded'] ?></td>
            <?php endforeach; ?>
            <td class="total"><?= (int) ($report['totals'][$cid] ?? 0) ?></td>
            <td><?= isset($report['ranks'][$cid]) ? '#' . (int) $report['ranks'][$cid] : '—' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <td class="name">Total per question</td>
            <?php foreach ($report['questions'] as $question): ?>
                <td><?= (int) ($report['question_sums'][(int) $question['id']] ?? 0) ?></td>
            <?php endforeach; ?>
            <td class="total">
                <?= (int) array_sum(array_map('intval', $report['totals'])) ?>
            </td>
            <td></td>
        </tr>
    </tfoot>
</table>
<?php endif; ?>

<?php if ($autoPrint): ?>
<script>window.addEventListener('load', function () { window.print(); });</script>
<?php endif; ?>
</body>
</html>
        <?php
    }
}
