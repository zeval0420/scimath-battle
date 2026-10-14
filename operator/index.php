<?php
// operator/index.php - operator control panel. Uses the shared header and footer.
// Sections are collapsible with the button in each card head.
// The page shell is static; operator.js renders everything from operator/api.php.
require_once __DIR__ . '/../config/auth.php';
require_login();
require_once __DIR__ . '/../config/database.php'; // provides $conn (mysqli)

$eventId = (int)($_GET['event'] ?? 0);
$stmt = $conn->prepare('SELECT name FROM scimath_events WHERE id = ?');
$stmt->bind_param('i', $eventId);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
if (!$event) {
    http_response_code(404);
    exit('Event not found. <a href="../admin/index.php">Back to events</a>');
}
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function card_open($id, $title) { ?>
  <section class="card" id="sec-<?= $id ?>">
    <div class="card-head">
      <h2 class="card-title"><?= h($title) ?></h2>
      <button class="btn btn-sm btn-ghost" type="button" data-toggle="sec-<?= $id ?>">Hide</button>
    </div>
    <div class="card-body stack">
<?php }
function card_close() { ?>
    </div>
  </section>
<?php } ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Operator - <?= h($event['name']) ?></title>
  <link rel="stylesheet" href="../src/universal/components.css">
  <link rel="stylesheet" href="../src/universal/HeadFoot.css">
  <link rel="stylesheet" href="operator.css">
</head>
<body class="app-body wide" data-event="<?= $eventId ?>">
<?php
$base = '../';
$header_nav = [
  ['All events', '../admin/index.php', false],
  ['Display',   '../display/index.php?event=' . $eventId, false],
  ['Operate',   '../operator/index.php?event=' . $eventId, true],
  ['Score',     '../scorer/index.php?event=' . $eventId, false],
  ['Configure', '../configure/index.php?event=' . $eventId, false],
];
include __DIR__ . '/../src/universal/header.php';
?>
<main class="page stack" id="main">

  <div class="row row-spread">
    <div>
      <a href="../admin/index.php">&larr; All events</a>
      <h1 class="page-title">Operate: <?= h($event['name']) ?></h1>
    </div>
    <div class="row">
      <span id="status" class="status" role="status"></span>
      <a class="btn" href="../display/index.php?event=<?= $eventId ?>" target="_blank" rel="noopener">Open display</a>
    </div>
  </div>

  <div class="op-columns">
  <div class="op-col">

  <?php card_open('preview', 'Screen preview'); ?>
      <div class="screen-frame" id="screen-frame">
        <iframe id="screen-iframe" title="Live display" src="../display/index.php?event=<?= $eventId ?>&amp;preview=1"></iframe>
      </div>
      <label class="switch">
        <input type="checkbox" id="show-live">
        <span class="switch-track"></span>
        <span id="show-live-label">Show event on screen (off = cover)</span>
      </label>
  <?php card_close(); ?>

  <?php card_open('questions', 'Questions'); ?>
      <div class="row">
        <button class="btn" type="button" id="prev-q">Prev</button>
        <span class="qnum" id="q-number">-</span>
        <button class="btn" type="button" id="next-q">Next</button>
        <span class="hint" id="q-info"></span>
      </div>
      <div class="row">
        <button class="btn" type="button" data-show="question">Show question</button>
        <button class="btn btn-primary" type="button" id="start-timer">Start timer</button>
        <button class="btn" type="button" data-show="boards_up">Show Boards Up</button>
        <button class="btn" type="button" data-show="answer">Show answer</button>
      </div>
      <p class="hint">Sequence: question &rarr; start timer &rarr; Boards Up (automatic when the timer ends) &rarr; show answer &rarr; next question.</p>
      <div class="table-wrap"><table class="q-table" id="q-table"></table></div>
  <?php card_close(); ?>

  <?php card_open('boards', 'Boards Up'); ?>
      <p class="hint">Shown automatically when a question's timer ends. Use text or one of the promotional slides.</p>
      <div class="row">
        <label class="switch"><input type="radio" name="bu-type" value="text"><span class="switch-track"></span><span>Text</span></label>
        <label class="switch"><input type="radio" name="bu-type" value="slide"><span class="switch-track"></span><span>Slide</span></label>
      </div>
      <div class="row">
        <input class="input" type="text" id="bu-text" maxlength="120" placeholder="Boards Up" style="max-width:320px">
        <select class="dropdown" id="bu-slide"></select>
        <button class="btn btn-primary" type="button" id="bu-save">Save</button>
      </div>
  <?php card_close(); ?>

  </div>
  <div class="op-col">

  <?php card_open('timer', 'Timer'); ?>
      <div class="row" style="justify-content:center">
        <button class="btn" type="button" id="t-minus">-5 sec</button>
        <div class="timer-display" id="timer-display">00:00</div>
        <button class="btn" type="button" id="t-plus">+5 sec</button>
      </div>
      <div class="row" style="justify-content:center">
        <button class="btn btn-primary btn-lg" type="button" id="t-toggle">Pause</button>
        <button class="btn btn-lg" type="button" id="t-reset">Reset</button>
      </div>
  <?php card_close(); ?>

  <?php card_open('results', 'Results'); ?>
      <div class="row">
        <button class="btn btn-primary" type="button" data-result="question">Show question results</button>
        <div class="row">
          <select class="dropdown" id="result-round"></select>
          <button class="btn btn-primary" type="button" data-result="round">Show round results</button>
        </div>
        <button class="btn btn-primary" type="button" data-result="final">Show final results</button>
        <button class="btn" type="button" id="results-clear">Hide results</button>
      </div>
      <div class="row">
        <span class="field-label">Scroll the results on screen</span>
        <button class="btn btn-sm" type="button" data-scroll="top">Top</button>
        <button class="btn btn-sm" type="button" data-scroll="up">&uarr; Up</button>
        <button class="btn btn-sm" type="button" data-scroll="down">&darr; Down</button>
        <span class="hint">You can also scroll the list in the preview above.</span>
      </div>
  <?php card_close(); ?>

  <?php card_open('slides', 'Additional slides'); ?>
      <div class="row">
        <button class="btn" type="button" id="clear-overlay">Back to question / answer</button>
        <span class="hint" id="overlay-info"></span>
      </div>
      <div class="grid" id="promo-grid"></div>
  <?php card_close(); ?>

  </div>
  </div>

</main>
<?php include __DIR__ . '/../src/universal/footer.php'; ?>
<script src="operator.js"></script>
</body>
</html>
