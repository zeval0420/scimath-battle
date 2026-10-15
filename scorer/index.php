<?php
// scorer/index.php - scorer page. Uses the shared header and footer.
// Several scorers can open this page for the same event; scores are saved to the database
// on every click and every open page polls the server so all tables stay in sync.
require_once __DIR__ . '/../config/auth.php';
require_login();
require_once __DIR__ . '/lib.php';

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$eventId = (int)($_GET['event'] ?? 0);
$event   = $eventId ? load_event($conn, $eventId) : null;
if (!$event) {
    http_response_code(404);
    echo '<!DOCTYPE html><meta charset="utf-8"><title>Scorer</title><p style="font-family:sans-serif;padding:24px">Event not found. Open this page from the admin page.</p>';
    exit;
}

$questions   = load_questions($conn, $eventId, (int)$event['default_points']);
$contestants = load_contestants($conn, $eventId);

$payload = [
    'event'       => (int)$event['id'],
    'questions'   => $questions,
    'contestants' => $contestants,
    'scores'      => load_scores($conn, $eventId),
    'clincher'    => (object)load_clincher($conn, $eventId),
    'current'     => $event['current_question_id'] === null ? null : (int)$event['current_question_id'],
    'rounds'      => ROUND_ORDER,
    'labels'      => ROUND_LABELS,
    'css'         => ROUND_CSS,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" href="../assets/PSHSlogo.png">
  <title>Scorer - <?= h($event['name']) ?></title>
  <link rel="stylesheet" href="../src/universal/components.css?v=<?= filemtime(__DIR__ . '/../src/universal/components.css') ?>">
  <link rel="stylesheet" href="../src/universal/HeadFoot.css?v=<?= filemtime(__DIR__ . '/../src/universal/HeadFoot.css') ?>">
  <style>
    .event-name { margin: 0; font-size: 26px; font-weight: 700; }
    .sync { font-size: 13px; color: var(--ink-soft); display: inline-flex; align-items: center; gap: 6px; }
    .sync::before { content: ""; width: 9px; height: 9px; border-radius: 50%; background: var(--success); }
    .sync.is-error { color: var(--danger); }
    .sync.is-error::before { background: var(--danger); }
    .q-meta { font-size: 14px; color: var(--ink-soft); }
    .team-name { font-size: 13px; color: var(--ink-soft); font-weight: 400; display: block; }
    .scored-by { font-size: 12px; color: var(--ink-soft); min-width: 90px; }
    .card-row .btn { min-width: 96px; }
    .table th.is-current, .table td.is-current { box-shadow: inset 2px 0 0 var(--accent), inset -2px 0 0 var(--accent); }
    .table thead th[data-q] { cursor: pointer; }
    .col-rank { background: var(--surface-2); font-weight: 700; }
    .scorer-name { width: 160px; }
  </style>
</head>
<body class="app-body">
<?php
$base = '../';
$header_nav = [
  ['All events', '../admin/index.php', false],
  ['Display',   '../display/index.php?event=' . $eventId, false],
  ['Operate',   '../operator/index.php?event=' . $eventId, false],
  ['Score',     '../scorer/index.php?event=' . $eventId, true],
  ['Configure', '../configure/index.php?event=' . $eventId, false],
];
include __DIR__ . '/../src/universal/header.php';
?>
<main class="page stack" id="main">

  <div class="row row-spread">
    <h1 class="event-name"><?= h($event['name']) ?></h1>
    <div class="row">
      <span class="sync" id="sync-status">Connecting...</span>
      <a class="btn" href="export.php?event=<?= $eventId ?>&format=sheets">Export to sheets</a>
      <a class="btn" href="export.php?event=<?= $eventId ?>&format=pdf">Export PDF</a>
    </div>
  </div>

  <!-- ===== Scoring ===== -->
  <section class="card" id="sec-scoring">
    <div class="card-head">
      <h2 class="card-title">Scoring</h2>
      <button class="btn btn-sm btn-ghost" type="button" data-toggle="sec-scoring">Hide</button>
    </div>
    <div class="card-body stack">

      <?php if (!$questions || !$contestants): ?>
        <div class="empty">
          <?= !$questions ? 'This event has no active questions yet.' : 'This event has no active teams yet.' ?>
        </div>
      <?php else: ?>

      <div class="row">
        <div class="field">
          <label class="field-label" for="q-select">Question</label>
          <div class="row">
            <button class="btn" type="button" id="prev-q">Prev</button>
            <select class="dropdown" id="q-select"></select>
            <button class="btn" type="button" id="next-q">Next</button>
          </div>
        </div>
        <div class="field">
          <span class="field-label">Worth</span>
          <span class="card-row-label" id="q-points"></span>
        </div>
        <div class="field">
          <label class="field-label" for="scorer-name">Scorer name</label>
          <input class="input scorer-name" type="text" id="scorer-name" maxlength="100" placeholder="e.g. Scorer 1">
        </div>
      </div>

      <div class="stack" id="team-cards"></div>

      <?php endif; ?>
    </div>
  </section>

  <!-- ===== Tabulation ===== -->
  <section class="card" id="sec-tabulation">
    <div class="card-head">
      <h2 class="card-title">Tabulation</h2>
      <button class="btn btn-sm btn-ghost" type="button" data-toggle="sec-tabulation">Hide</button>
    </div>
    <div class="card-body">
      <div class="table-wrap">
        <table class="table" id="tabulation"></table>
      </div>
    </div>
  </section>

</main>
<?php include __DIR__ . '/../src/universal/footer.php'; ?>

<script>
  var DATA = <?= json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="scorer.js?v=<?= filemtime(__DIR__ . '/scorer.js') ?>"></script>
</body>
</html>
