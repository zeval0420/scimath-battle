<?php
// scorer/index.php - scorer page. No header or footer.
// Anything marked AUTOFILLED is a single sample component to be repeated/filled from data.

$eventId = $_GET['event'] ?? '';
// AUTOFILLED: load the event by $eventId and set its name
$eventName = 'Event name';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Scorer - <?= htmlspecialchars($eventName) ?></title>
  <link rel="stylesheet" href="../../src/universal/components.css">
  <style>
    .page { max-width: 1100px; margin: 0 auto; padding: var(--pad); }
    .event-name { margin: 0; font-size: 26px; font-weight: 700; }
  </style>
</head>
<body>
<div class="page stack">

  <div class="row row-spread">
    <h1 class="event-name"><?= htmlspecialchars($eventName) ?></h1>
    <div class="row">
      <!-- Point these at your export handlers -->
      <a class="btn" href="export.php?event=<?= urlencode($eventId) ?>&format=sheets">Export to sheets</a>
      <a class="btn" href="export.php?event=<?= urlencode($eventId) ?>&format=pdf">Export PDF</a>
    </div>
  </div>

  <!-- ===== Scoring ===== -->
  <section class="card" id="sec-scoring">
    <div class="card-head">
      <h2 class="card-title">Scoring</h2>
      <button class="btn btn-sm btn-ghost" type="button" data-toggle="sec-scoring">Hide</button>
    </div>
    <div class="card-body stack">

      <div class="row">
        <div class="field">
          <span class="field-label">Question</span>
          <div class="row">
            <button class="btn" type="button" id="prev-q">Prev</button>
            <!-- AUTOFILLED: current question number and its round/points (set data-points from the question) -->
            <span class="card-row-label" id="q-number" data-points="1">1</span>
            <button class="btn" type="button" id="next-q">Next</button>
          </div>
        </div>
      </div>

      <div class="stack">
        <!-- AUTOFILLED: one .card per school. To add more, copy this block and change
             data-school and the acronym. data-school must match the data-school
             on the matching row in the tabulation table. -->
        <div class="card card-row" data-school="ABC">
          <span class="card-row-label">ABC</span>
          <div class="btn-group">
            <button class="btn btn-success btn-toggle" type="button" data-state="correct">Correct</button>
            <button class="btn btn-wrong btn-toggle" type="button" data-state="wrong">Wrong</button>
            <button class="btn btn-muted btn-toggle" type="button" data-state="none">No answer</button>
          </div>
        </div>
      </div>

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
        <table class="table">
          <thead>
            <!-- AUTOFILLED: one group header per round. Set colspan to the number of
                 questions in that round. Keep the order easy, average, difficult, clincher. -->
            <tr>
              <th class="col-sticky" rowspan="2">School</th>
              <th class="th-easy" colspan="1">Easy</th>
              <th class="th-average" colspan="1">Average</th>
              <th class="th-difficult" colspan="1">Difficult</th>
              <th class="th-clincher" colspan="1">Clincher</th>
              <th class="col-total" rowspan="2">Total</th>
            </tr>
            <!-- AUTOFILLED: one <th> per question number, in the same order as the groups above.
                 Add data-q (question number) and the round's th-* class. -->
            <tr>
              <th class="th-easy">1</th>
              <th class="th-average">2</th>
              <th class="th-difficult">3</th>
              <th class="th-clincher">4</th>
            </tr>
          </thead>
          <tbody>
            <!-- AUTOFILLED: one <tr> per school. To add more, copy this row, change
                 data-school, and add one <td data-q="N"> per question column. -->
            <tr data-school="ABC">
              <th class="col-sticky" scope="row">ABC</th>
              <td data-q="1" data-points="1" class="cell-none">-</td>
              <td data-q="2" data-points="2" class="cell-none">-</td>
              <td data-q="3" data-points="3" class="cell-none">-</td>
              <td data-q="4" data-points="5" class="cell-none">-</td>
              <td class="col-total" data-total>0</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>

</div>

<script>
  // Collapse / expand sections
  document.querySelectorAll('[data-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var card = document.getElementById(btn.dataset.toggle);
      var collapsed = card.classList.toggle('is-collapsed');
      btn.textContent = collapsed ? 'Show' : 'Hide';
    });
  });

  // Question navigation (set max from your question count)
  var qNumber = document.getElementById('q-number');
  var current = 1, maxQ = 4;

  function showQuestion() {
    qNumber.textContent = current;
    // Restore the button state for this question on every school card
    document.querySelectorAll('.card[data-school]').forEach(function (card) {
      var cell = document.querySelector('tr[data-school="' + card.dataset.school + '"] td[data-q="' + current + '"]');
      var state = cell ? cell.dataset.state : null;
      card.querySelectorAll('[data-state]').forEach(function (b) {
        b.classList.toggle('is-active', b.dataset.state === state);
      });
    });
  }

  document.getElementById('prev-q').addEventListener('click', function () {
    if (current > 1) { current--; showQuestion(); }
  });
  document.getElementById('next-q').addEventListener('click', function () {
    if (current < maxQ) { current++; showQuestion(); }
  });

  // Score clicks: fill the button, then update the tabulation cell and the row total
  document.querySelectorAll('.card[data-school] [data-state]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var card = btn.closest('.card');
      var school = card.dataset.school;
      var state = btn.dataset.state;

      card.querySelectorAll('[data-state]').forEach(function (b) {
        b.classList.toggle('is-active', b === btn);
      });

      var row = document.querySelector('tr[data-school="' + school + '"]');
      var cell = row.querySelector('td[data-q="' + current + '"]');
      cell.dataset.state = state;
      cell.className = 'cell-' + state;
      cell.textContent = state === 'correct' ? cell.dataset.points : (state === 'wrong' ? '0' : '-');

      var total = 0;
      row.querySelectorAll('td[data-q][data-state="correct"]').forEach(function (c) {
        total += Number(c.dataset.points);
      });
      row.querySelector('[data-total]').textContent = total;

      // TODO: save {event, school, question: current, state} to the server here
    });
  });
</script>
</body>
</html>