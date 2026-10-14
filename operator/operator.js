// operator/operator.js - operator controls. Talks to api.php; the display page follows the same state.
(function () {
  var EVENT = Number(document.body.dataset.event);
  var API = 'api.php';
  var ME = 'operator-' + Math.random().toString(36).slice(2, 8);
  var snap = null, recvAt = 0, statusTimer, busy = false;

  function $(id) { return document.getElementById(id); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function url(path) { return '../' + encodeURI(path); }

  function setStatus(msg, isError) {
    var el = $('status');
    el.textContent = msg;
    el.classList.toggle('is-error', !!isError);
    clearTimeout(statusTimer);
    if (!isError && msg) statusTimer = setTimeout(function () { el.textContent = ''; }, 2000);
  }

  // ---------- network ----------
  async function post(action, payload) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('event', EVENT);
    fd.append('payload', JSON.stringify(Object.assign({ role: 'operator' }, payload || {})));
    var res = await fetch(API, { method: 'POST', body: fd });
    var json = await res.json();
    if (json.error) throw new Error(json.error);
    return json.snapshot;
  }

  // Runs a command and re-renders. Silent polls pass quiet = true.
  async function call(action, payload, quiet) {
    try {
      var s = await post(action, payload);
      take(s);
      if (!quiet) setStatus('Done');
      return true;
    } catch (e) {
      setStatus(e.message || 'Request failed', true);
      return false;
    }
  }

  function take(s) {
    snap = s;
    recvAt = performance.now();
    render();
  }

  async function poll() {
    if (!busy) await call('state', {}, true);
    setTimeout(poll, 1500);
  }

  // ---------- rendering ----------
  function currentId() { return snap.question ? snap.question.id : null; }

  function renderQuestions() {
    var q = snap.question;
    $('q-number').textContent = q ? 'Q' + q.number + ' / ' + snap.question_count : 'No questions';
    $('q-info').textContent = q ? q.round_label + ' round - ' + q.eff_points + ' pts - ' + q.eff_time + ' s' : '';
    $('prev-q').disabled = !q || snap.question_index <= 0;
    $('next-q').disabled = !q || snap.question_index >= snap.question_count - 1;
    $('start-timer').disabled = !q;

    var table = $('q-table');
    // Don't rebuild while the operator is typing in a points/time box.
    if (table.contains(document.activeElement) && document.activeElement.tagName === 'INPUT') return;
    table.innerHTML = '<thead><tr><th>#</th><th>Question slide</th><th>Answer slide</th><th>Round</th><th>Points</th><th>Time limit (sec)</th></tr></thead><tbody>' +
      snap.questions.map(function (r) {
        return '<tr data-qid="' + r.id + '"' + (q && r.id === q.id ? ' class="is-current"' : '') + '>' +
          '<td><b>' + r.number + '</b></td>' +
          '<td class="preview"><div class="slide-thumb"><img loading="lazy" src="' + url(r.image_path) + '" alt=""></div></td>' +
          '<td class="preview"><div class="slide-thumb">' + (r.answer_image_path ? '<img loading="lazy" src="' + url(r.answer_image_path) + '" alt="">' : 'No answer slide') + '</div></td>' +
          '<td>' + esc(r.round_label) + '</td>' +
          '<td><input type="number" min="0" data-field="points" placeholder="' + (r.eff_points) + '" value="' + (r.points == null ? '' : r.points) + '"></td>' +
          '<td><input type="number" min="1" data-field="time" placeholder="' + (r.eff_time) + '" value="' + (r.time_seconds == null ? '' : r.time_seconds) + '"></td>' +
          '</tr>';
      }).join('') + '</tbody>';
  }

  function renderBoards() {
    var bu = snap.state.boards_up;
    if (document.activeElement === $('bu-text')) return;
    document.querySelectorAll('input[name=bu-type]').forEach(function (r) { r.checked = r.value === bu.type; });
    $('bu-text').value = bu.text === 'Boards Up' ? '' : bu.text;
    var sel = $('bu-slide');
    if (document.activeElement !== sel) {
      sel.innerHTML = '<option value="">Choose a slide...</option>' + snap.promos.map(function (p) {
        return '<option value="' + p.id + '"' + (Number(bu.promo_id) === Number(p.id) ? ' selected' : '') + '>' + esc(p.title || p.path) + '</option>';
      }).join('');
    }
  }

  function renderPromos() {
    var ov = snap.state.overlay;
    $('promo-grid').innerHTML = snap.promos.length ? snap.promos.map(function (p) {
      var live = !snap.state.hidden && ov && ov.type === 'promo' && Number(ov.id) === Number(p.id);
      return '<div class="stack promo-item' + (live ? ' is-live' : '') + '" style="gap:8px">' +
        '<div class="slide-thumb"><img loading="lazy" src="' + url(p.path) + '" alt=""></div>' +
        '<span class="hint">' + esc(p.title) + '</span>' +
        '<button class="btn btn-primary btn-sm btn-block" type="button" data-show-slide="' + p.id + '">Show in screen</button></div>';
    }).join('') : '<div class="empty">No promotional slides. Add some on the Configure page.</div>';
    $('overlay-info').textContent = ov ? 'Showing ' + (ov.type === 'promo' ? 'a promotional slide' : 'results') : '';
    $('clear-overlay').disabled = !ov;
    $('results-clear').disabled = !(ov && ov.type === 'results');
  }

  function renderTimer() {
    if (!snap) return;
    var t = snap.timer;
    var ms = t.running ? t.remaining_ms - (performance.now() - recvAt) : t.remaining_ms;
    if (!t.running && !t.ended && t.remaining_ms === 0 && snap.question) ms = snap.question.eff_time * 1000; // not started yet
    var s = Math.max(0, Math.ceil(ms / 1000));
    $('timer-display').textContent = String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
    $('timer-display').classList.toggle('is-low', s <= 5 && (t.running || t.ended));
    var toggle = $('t-toggle');
    toggle.textContent = t.running ? 'Pause' : 'Resume';
    toggle.disabled = !t.running && (t.remaining_ms <= 0);
  }

  function renderResultsControls() {
    var sel = $('result-round');
    if (!sel.options.length) {
      sel.innerHTML = snap.rounds.map(function (r) { return '<option value="' + r.value + '">' + esc(r.label) + '</option>'; }).join('');
      if (snap.question) sel.value = snap.question.round;
    }
  }

  function render() {
    $('show-live').checked = !snap.state.hidden;
    $('show-banner').disabled = snap.state.hidden;
    renderQuestions();
    renderBoards();
    renderPromos();
    renderResultsControls();
    renderTimer();
    document.querySelectorAll('[data-show]').forEach(function (b) {
      var active = !snap.state.hidden && !snap.state.overlay && snap.state.base === b.dataset.show;
      b.classList.toggle('btn-primary', active && b.id !== 'start-timer');
    });
  }

  // ---------- preview scaling ----------
  function scalePreview() {
    var frame = $('screen-frame');
    $('screen-iframe').style.transform = 'scale(' + (frame.clientWidth / 1280) + ')';
  }
  window.addEventListener('resize', scalePreview);

  // ---------- events ----------
  $('show-live').addEventListener('change', function () { call('set_hidden', { hidden: !this.checked }); });
  $('show-banner').addEventListener('click', function () { call('set_hidden', { hidden: true }); });
  $('prev-q').addEventListener('click', function () { call('goto_question', { dir: 'prev' }); });
  $('next-q').addEventListener('click', function () { call('goto_question', { dir: 'next' }); });
  $('start-timer').addEventListener('click', function () { call('timer_start'); });
  $('t-plus').addEventListener('click', function () { call('timer_adjust', { seconds: 5 }); });
  $('t-minus').addEventListener('click', function () { call('timer_adjust', { seconds: -5 }); });
  $('t-reset').addEventListener('click', function () { call('timer_reset'); });
  $('t-toggle').addEventListener('click', function () { call(snap.timer.running ? 'timer_pause' : 'timer_resume'); });
  $('clear-overlay').addEventListener('click', function () { call('clear_overlay'); });
  $('results-clear').addEventListener('click', function () { call('clear_overlay'); });

  $('bu-save').addEventListener('click', function () {
    var type = document.querySelector('input[name=bu-type]:checked');
    call('save_boards_up', { type: type ? type.value : 'text', text: $('bu-text').value, promo_id: $('bu-slide').value });
  });

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-toggle], [data-show], [data-show-slide], [data-result], [data-scroll], tr[data-qid]');
    if (!t) return;
    if (t.dataset.toggle) {
      var card = $(t.dataset.toggle);
      t.textContent = card.classList.toggle('is-collapsed') ? 'Show' : 'Hide';
    } else if (t.dataset.show) {
      call('show', { base: t.dataset.show });
    } else if (t.dataset.showSlide) {
      call('show_promo', { id: Number(t.dataset.showSlide) });
    } else if (t.dataset.result) {
      call('show_results', { kind: t.dataset.result, round: $('result-round').value });
    } else if (t.dataset.scroll) {
      var cur = snap.state.scroll, to = cur;
      if (t.dataset.scroll === 'top') to = 0;
      else to = Math.max(0, Math.min(1, cur + (t.dataset.scroll === 'down' ? 0.2 : -0.2)));
      call('set_scroll', { ratio: to, by: ME });
    } else if (t.matches('tr[data-qid]') && !e.target.closest('input')) {
      call('goto_question', { question_id: Number(t.dataset.qid) });
    }
  });

  // Per-question points / time overrides (blank = round default)
  document.addEventListener('change', function (e) {
    var el = e.target;
    if (!el.dataset.field) return;
    var row = el.closest('tr[data-qid]');
    busy = true;
    call('save_question_fields', {
      id: Number(row.dataset.qid),
      points: row.querySelector('[data-field=points]').value,
      time: row.querySelector('[data-field=time]').value
    }).then(function () { busy = false; });
  });

  setInterval(renderTimer, 200);
  scalePreview();
  call('state', {}, true).then(poll);
})();
