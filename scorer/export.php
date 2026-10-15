<?php
// scorer/export.php - exports the tabulation.
//   ?event=ID&format=sheets -> CSV download (opens in Google Sheets / Excel)
//   ?event=ID&format=pdf    -> print-ready page that opens the browser's "Save as PDF" dialog
require_once __DIR__ . '/../config/auth.php';
require_login();
require_once __DIR__ . '/lib.php';

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$eventId = (int)($_GET['event'] ?? 0);
$format  = (string)($_GET['format'] ?? 'sheets');
$event   = $eventId ? load_event($conn, $eventId) : null;
if (!$event) {
    http_response_code(404);
    echo 'Event not found.';
    exit;
}

$questions   = load_questions($conn, $eventId, (int)$event['default_points']);
$contestants = load_contestants($conn, $eventId);
$scores      = [];
foreach (load_scores($conn, $eventId) as $s) $scores[$s['q'] . '_' . $s['c']] = $s;
$wins = clincher_wins(load_clincher($conn, $eventId));

// Totals and ranks, same rules as the tabulation on the scorer page (clincher wins break ties).
$totals = [];
foreach ($contestants as $c) {
    $t = $c['start'];
    foreach ($questions as $q) $t += $scores[$q['id'] . '_' . $c['id']]['p'] ?? 0;
    $totals[$c['id']] = $t;
}
$ranks = [];
foreach ($contestants as $c) {
    $rank = 1;
    foreach ($contestants as $o) {
        $ot = $totals[$o['id']]; $ct = $totals[$c['id']];
        if ($ot > $ct || ($ot === $ct && ($wins[$o['id']] ?? 0) > ($wins[$c['id']] ?? 0))) $rank++;
    }
    $ranks[$c['id']] = $rank;
}

$hasStart = (bool)array_filter($contestants, fn($c) => $c['start']);

// Header rows
$roundRow = [''];
$numRow   = ['School'];
if ($hasStart) { $roundRow[] = ''; $numRow[] = 'Start'; }
foreach ($questions as $q) {
    $roundRow[] = ROUND_LABELS[$q['round']];
    $numRow[]   = $q['name'] . ' (' . $q['points'] . ' pt' . ($q['points'] === 1 ? '' : 's') . ')';
}
$roundRow[] = ''; $numRow[] = 'Total';
$roundRow[] = ''; $numRow[] = 'Rank';

// Body rows
$rows = [];
foreach ($contestants as $c) {
    $row = [$c['label']];
    if ($hasStart) $row[] = $c['start'];
    foreach ($questions as $q) {
        $s = $scores[$q['id'] . '_' . $c['id']] ?? null;
        $row[] = !$s ? '' : ($s['r'] === 'correct' ? $s['p'] : ($s['r'] === 'incorrect' ? 0 : '-'));
    }
    $row[] = $totals[$c['id']];
    $row[] = $ranks[$c['id']];
    $rows[] = $row;
}

$slug = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $event['name']), '-') ?: 'event';

if ($format === 'sheets') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $slug . '-scores.csv"');
    echo "\xEF\xBB\xBF"; // BOM so Excel/Sheets read UTF-8 correctly
    $out = fopen('php://output', 'w');
    fputcsv($out, [$event['name']]);
    fputcsv($out, $roundRow);
    fputcsv($out, $numRow);
    foreach ($rows as $row) fputcsv($out, $row);
    fclose($out);
    exit;
}

if ($format !== 'pdf') {
    http_response_code(400);
    echo 'Unknown export format.';
    exit;
}

// PDF: group the round header cells by colspan
$groups = [];
foreach ($questions as $q) {
    $last = count($groups) - 1;
    if ($last >= 0 && $groups[$last]['round'] === $q['round']) $groups[$last]['n']++;
    else $groups[] = ['round' => $q['round'], 'n' => 1];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= h($event['name']) ?> - Scores</title>
  <style>
    @page { size: A4 landscape; margin: 12mm; }
    body { font-family: Arial, Helvetica, sans-serif; color: #111; margin: 0; }
    h1 { font-size: 20px; margin: 0 0 4px; }
    .meta { font-size: 12px; color: #555; margin-bottom: 12px; }
    table { border-collapse: collapse; width: 100%; font-size: 11px; }
    th, td { border: 1px solid #888; padding: 4px 6px; text-align: center; }
    th.school, td.school { text-align: left; font-weight: 700; }
    thead th { background: #e8e8e8; }
    td.total, td.rank { font-weight: 700; background: #f3f3f3; }
    .noprint { margin-bottom: 12px; }
    @media print { .noprint { display: none; } thead { display: table-header-group; } tr { page-break-inside: avoid; } }
  </style>
</head>
<body>
  <div class="noprint"><button onclick="window.print()">Print / Save as PDF</button></div>
  <h1><?= h($event['name']) ?></h1>
  <div class="meta">Scores as of <?= h(date('F j, Y g:i A')) ?></div>
  <?php if (!$questions || !$contestants): ?>
    <p>There are no scores to export yet.</p>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th class="school" rowspan="2">School</th>
        <?php if ($hasStart): ?><th rowspan="2">Start</th><?php endif; ?>
        <?php foreach ($groups as $g): ?><th colspan="<?= $g['n'] ?>"><?= h(ROUND_LABELS[$g['round']]) ?></th><?php endforeach; ?>
        <th rowspan="2">Total</th><th rowspan="2">Rank</th>
      </tr>
      <tr><?php foreach ($questions as $q): ?><th><?= h($q['name']) ?></th><?php endforeach; ?></tr>
    </thead>
    <tbody>
      <?php foreach ($contestants as $i => $c): $row = $rows[$i]; ?>
      <tr>
        <td class="school"><?= h($row[0]) ?></td>
        <?php for ($j = 1; $j < count($row) - 2; $j++): ?><td><?= h($row[$j]) ?></td><?php endfor; ?>
        <td class="total"><?= h($row[count($row) - 2]) ?></td>
        <td class="rank"><?= h($row[count($row) - 1]) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <script>window.addEventListener('load', function () { window.print(); });</script>
</body>
</html>
