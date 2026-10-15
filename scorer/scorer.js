// scorer/scorer.js - scoring UI, tabulation and multi-scorer sync (polling).
(function () {
  var POLL_MS = 2000;
  var RESULT_FOR_BUTTON = { correct: 'correct', wrong: 'incorrect', none: 'no_answer' };
  var CELL_CLASS = { correct: 'cell-correct', incorrect: 'cell-wrong', no_answer: 'cell-none' };

  var questions = DATA.questions;
  var contestants = DATA.contestants;
  var qById = {};
  questions.forEach(function (q) { qById[q.id] = q; });

  var scores = {};          // "questionId_contestantId" -> {r, p, by}
  var lastServerJson = '';  // used to skip re-rendering when nothing changed
  var pending = 0;          // saves in flight; polls are ignored meanwhile so they can't undo a click
  var currentId = null;

  var el = {
    select: document.getElementById('q-select'),
    points: document.getElementById('q-points'),
    cards: document.getElementById('team-cards'),
    table: document.getElementById('tabulation'),
    name: document.getElementById('scorer-name'),
    status: document.getElementById('sync-status'),
    prev: document.getElementById('prev-q'),
    next: document.getElementById('next-q')
  };

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function key(q, c) { return q + '_' + c; }
  function store(get, set) {
    try { return set === undefined ? localStorage.getItem(get) : localStorage.setItem(get, set); } catch (e) { return null; }
  }

  // ---------- Collapse / expand sections ----------
  document.querySelectorAll('[data-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var collapsed = document.getElementById(btn.dataset.toggle).classList.toggle('is-collapsed');
      btn.textContent = collapsed ? 'Show' : 'Hide';
    });
  });

  // ---------- Sync status ----------
  function setStatus(ok, text) {
    el.status.textContent = text;
    el.status.classList.toggle('is-error', !ok);
  }

  // ---------- Applying server state ----------
  function applyScores(list, clincher) {
    if (clincher) DATA.clincher = clincher;
    var json = JSON.stringify([list, DATA.clincher]);
    if (json === lastServerJson) return;
    lastServerJson = json;
    scores = {};
    list.forEach(function (s) { scores[key(s.q, s.c)] = s; });
    renderCards();
    renderTable();
  }

  function poll() {
    if (pending > 0) return;
    fetch('api.php?event=' + DATA.event, { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) throw new Error(d.error || 'error');
        if (pending === 0) applyScores(d.scores, d.clincher);
        setStatus(true, 'Synced ' + new Date().toLocaleTimeString());
      })
      .catch(function () { setStatus(false, 'Offline - retrying...'); });
  }

  // ---------- Question picker (grouped per round) ----------
  function buildSelect() {
    var html = '';
    DATA.rounds.forEach(function (round) {
      var inRound = questions.filter(function (q) { return q.round === round; });
      if (!inRound.length) return;
      html += '<optgroup label="' + esc(DATA.labels[round]) + '">';
      inRound.forEach(function (q) {
        html += '<option value="' + q.id + '">' + esc(q.name) + ' (' + q.points + ' pt' + (q.points === 1 ? '' : 's') + ')</option>';
      });
      html += '</optgroup>';
    });
    el.select.innerHTML = html;
  }

  function setQuestion(id) {
    currentId = id;
    el.select.value = String(id);
    var idx = questions.indexOf(qById[id]);
    el.prev.disabled = idx <= 0;
    el.next.disabled = idx >= questions.length - 1;
    el.points.textContent = qById[id].points;
    renderCards();
    renderTable();
  }

  function step(delta) {
    var idx = questions.indexOf(qById[currentId]) + delta;
    if (idx >= 0 && idx < questions.length) setQuestion(questions[idx].id);
  }

  // ---------- Team cards for the selected question ----------
  function renderCards() {
    if (currentId === null) return;
    var q = qById[currentId];
    var teams = contestants;
    el.cards.innerHTML = teams.map(function (c) {
      var s = scores[key(q.id, c.id)];
      var res = s ? s.r : null;
      function btn(cls, state, label) {
        return '<button class="btn ' + cls + ' btn-toggle' + (RESULT_FOR_BUTTON[state] === res ? ' is-active' : '') +
          '" type="button" data-state="' + state + '">' + label + '</button>';
      }
      return '<div class="card card-row" data-contestant="' + c.id + '">' +
        '<span class="card-row-label">' + esc(c.label) +
        (c.name !== c.label ? '<span class="team-name">' + esc(c.name) + '</span>' : '') + '</span>' +
        '<span class="scored-by">' + (s && s.by ? 'by ' + esc(s.by) : '') + '</span>' +
        '<div class="btn-group">' +
        btn('btn-success', 'correct', 'Correct') +
        btn('btn-wrong', 'wrong', 'Wrong') +
        btn('btn-muted', 'none', 'No answer') +
        '</div></div>';
    }).join('');
  }

  // ---------- Saving a score ----------
  if (el.cards) el.cards.addEventListener('click', function (e) {
    var b = e.target.closest('[data-state]');
    if (!b) return;
    var contestantId = Number(b.closest('[data-contestant]').dataset.contestant);
    var k = key(currentId, contestantId);
    var result = RESULT_FOR_BUTTON[b.dataset.state];
    var existing = scores[k];
    if (existing && existing.r === result) result = 'clear'; // clicking the active button again un-scores it
    var scorer = el.name.value.trim();

    // Optimistic update
    if (result === 'clear') delete scores[k];
    else scores[k] = { q: currentId, c: contestantId, r: result, p: result === 'correct' ? qById[currentId].points : 0, by: scorer || null };
    renderCards();
    renderTable();

    pending++;
    fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ event: DATA.event, question: currentId, contestant: contestantId, result: result, scorer: scorer })
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) throw new Error(d.error || 'error');
        pending--;
        lastServerJson = '';
        if (pending === 0) applyScores(d.scores, d.clincher);
        setStatus(true, 'Saved ' + new Date().toLocaleTimeString());
      })
      .catch(function (err) {
        pending--;
        lastServerJson = ''; // force the next poll to restore the real server state
        setStatus(false, 'Save failed - ' + err.message);
        poll();
      });
  });

  // ---------- Tabulation ----------
  function renderTable() {
    var groups = DATA.rounds.map(function (round) {
      return { round: round, qs: questions.filter(function (q) { return q.round === round; }) };
    }).filter(function (g) { return g.qs.length; });

    var hasStart = contestants.some(function (c) { return c.start; });

    var totals = contestants.map(function (c) {
      var t = c.start;
      questions.forEach(function (q) {
        var s = scores[key(q.id, c.id)];
        if (s) t += s.p;
      });
      return t;
    });
    // Clincher wins settle ties on the total
    var wins = contestants.map(function (c) {
      return Object.keys(DATA.clincher).filter(function (qid) { return DATA.clincher[qid].winners.indexOf(c.id) !== -1; }).length;
    });
    var ranks = totals.map(function (t, i) {
      return 1 + totals.filter(function (o, j) { return o > t || (o === t && wins[j] > wins[i]); }).length;
    });

    var html = '<thead><tr><th class="col-sticky" rowspan="2">School</th>';
    if (hasStart) html += '<th rowspan="2">Start</th>';
    groups.forEach(function (g) {
      html += '<th class="th-' + DATA.css[g.round] + '" colspan="' + g.qs.length + '">' + esc(DATA.labels[g.round]) + '</th>';
    });
    html += '<th class="col-total" rowspan="2">Total</th><th class="col-rank" rowspan="2">Rank</th></tr><tr>';
    groups.forEach(function (g) {
      g.qs.forEach(function (q) {
        html += '<th class="th-' + DATA.css[g.round] + (q.id === currentId ? ' is-current' : '') +
          '" data-q="' + q.id + '" title="' + q.points + ' pts">' + esc(q.name) + '</th>';
      });
    });
    html += '</tr></thead><tbody>';

    contestants.forEach(function (c, i) {
      html += '<tr><th class="col-sticky" scope="row" title="' + esc(c.name) + '">' + esc(c.label) + '</th>';
      if (hasStart) html += '<td>' + c.start + '</td>';
      questions.forEach(function (q) {
        var s = scores[key(q.id, c.id)];
        var cls = s ? CELL_CLASS[s.r] : '';
        var txt = !s ? '' : (s.r === 'correct' ? s.p : (s.r === 'incorrect' ? '0' : '-'));
        html += '<td class="' + cls + (q.id === currentId ? ' is-current' : '') + '">' + txt + '</td>';
      });
      html += '<td class="col-total">' + totals[i] + '</td><td class="col-rank"><span class="rank-badge rank-' + Math.min(ranks[i], 4) + '">' + ranks[i] + '</span></td></tr>';
    });
    el.table.innerHTML = html + '</tbody>';
  }

  el.table.addEventListener('click', function (e) {
    var th = e.target.closest('th[data-q]');
    if (th) setQuestion(Number(th.dataset.q));
  });

  // ---------- Init ----------
  if (el.name) {
    el.name.value = store('irc_scorer_name') || '';
    el.name.addEventListener('input', function () { store('irc_scorer_name', el.name.value); });
  }

  scores = {};
  DATA.scores.forEach(function (s) { scores[key(s.q, s.c)] = s; });
  lastServerJson = JSON.stringify([DATA.scores, DATA.clincher]);

  if (questions.length && contestants.length) {
    buildSelect();
    el.select.addEventListener('change', function () { setQuestion(Number(el.select.value)); });
    el.prev.addEventListener('click', function () { step(-1); });
    el.next.addEventListener('click', function () { step(1); });
    // Start on the question the operator is showing, if any
    setQuestion(DATA.current && qById[DATA.current] ? DATA.current : questions[0].id);
  } else {
    renderTable();
  }

  setStatus(true, 'Synced ' + new Date().toLocaleTimeString());
  setInterval(poll, POLL_MS);
})();
