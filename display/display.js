// display/display.js - polls operator/api.php and renders whatever the operator has put on screen.
(function () {
  var EVENT = Number(document.body.dataset.event);
  var PREVIEW = document.body.dataset.preview === '1';
  var API = '../operator/api.php';
  var ME = 'display-' + Math.random().toString(36).slice(2, 8) + (PREVIEW ? '-preview' : '');
  var ROUND_CLASS = { easy: 'easy', medium: 'average', hard: 'difficult', clincher: 'clincher' };
  var ROUND_LABEL = { easy: 'Easy', medium: 'Average', hard: 'Difficult', clincher: 'Clincher' };

  var slide = document.getElementById('reusable-slide');
  var textScreen = document.getElementById('text-screen');
  var badge = document.getElementById('timer-badge');
  var overlay = document.getElementById('overlay');
  var list = document.getElementById('score-list');
  var snap = null, recvAt = 0, lastScreenKey = '', lastScroll = null, programmaticUntil = 0;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function url(path) { return '../' + encodeURI(path); }

  // ---------- rendering ----------
  // Question results: one tile per school, green with a check or red with a cross.
  var LEGEND = '<div class="result-legend">' +
    '<span class="legend-item is-correct"><span class="legend-mark">&#10003;</span>Correct</span>' +
    '<span class="legend-item is-wrong"><span class="legend-mark">&#10007;</span>Wrong / no answer</span></div>';

  function statusCard(r) {
    var cls = 'is-unscored', mark = '';
    if (r.result === 'correct') { cls = 'is-correct'; mark = '&#10003;'; }
    else if (r.result === 'incorrect' || r.result === 'no_answer') { cls = 'is-wrong'; mark = '&#10007;'; }
    return '<div class="result-tile ' + cls + '"><span class="result-tile-school">' + esc(r.label) + '</span>' +
      '<span class="result-tile-mark">' + mark + '</span></div>';
  }

  function roundCard(r, i) {
    return '<div class="score-card is-ranked"><span class="score-card-rank">' + r.rank + '</span>' +
      '<span class="score-card-school">' + esc(r.label) + '</span>' +
      '<div class="score-card-breakdown is-wrap">' + r.cells.map(function (c) {
        var cls = c.result === 'correct' ? ' is-correct' : (c.result === 'incorrect' || c.result === 'no_answer' ? ' is-wrong' : '');
        return '<div class="score-card-part part-q' + cls + '"><span class="score-card-part-label">' + c.n + '</span>' +
          '<span class="score-card-part-value">' + (c.points == null ? '-' : c.points) + '</span></div>';
      }).join('') + '</div>' +
      '<span class="score-card-final">' + r.total + '</span></div>';
  }

  var finalHasStart = false; // set per screen so every row has the same columns

  function finalCard(r, i) {
    var parts = ['easy', 'medium', 'hard'].map(function (k) {
      return '<div class="score-card-part part-' + ROUND_CLASS[k] + '"><span class="score-card-part-label">' + ROUND_LABEL[k] +
        '</span><span class="score-card-part-value">' + r.parts[k] + '</span></div>';
    }).join('');
    if (finalHasStart) parts = '<div class="score-card-part part-start"><span class="score-card-part-label">Start</span><span class="score-card-part-value">' + r.start + '</span></div>' + parts;
    return '<div class="score-card is-ranked"><span class="score-card-rank">' + r.rank + '</span>' +
      '<span class="score-card-school">' + esc(r.label) + '</span>' +
      '<div class="score-card-breakdown" style="--cols:' + (finalHasStart ? 4 : 3) + '">' + parts + '</div>' +
      '<span class="score-card-final">' + r.total + '</span></div>';
  }

  // Question results: 8 columns x 5 rows sized to the space under the logo, title and legend.
  function fitGrid() {
    var tiles = list.querySelectorAll('.result-tile');
    var n = tiles.length;
    if (!n || !list.classList.contains('is-grid')) return;
    var legend = list.querySelector('.result-legend');
    var gap = 14;
    list.style.cssText = '';
    var W = list.clientWidth, H = list.clientHeight - (legend ? legend.offsetHeight + gap : 0);
    var longest = 0;
    tiles.forEach(function (t) {
      var txt = t.querySelector('.result-tile-school').textContent;
      var word = txt.split(/\s+/).reduce(function (m, w) { return Math.max(m, w.length); }, 1);
      longest = Math.max(longest, Math.max(word, Math.ceil(txt.length / 2)));
    });
    // Fixed 8 x 5 grid (more rows only if there are more than 40 schools).
    var cols = 8, rows = Math.max(5, Math.ceil(n / cols));
    var best = { cols: cols, rows: rows, w: (W - gap * (cols - 1)) / cols, h: (H - gap * (rows - 1)) / rows };
    var font = Math.max(9, Math.min(34, best.h * 0.34, (best.w - 16) / (longest * 0.6)));
    list.style.overflow = 'hidden';
    list.style.gap = gap + 'px';
    list.style.gridTemplateColumns = 'repeat(' + best.cols + ', minmax(0, 1fr))';
    // legend keeps its own short row; the schools get equal fixed rows below it
    list.style.gridTemplateRows = (legend ? 'auto ' : '') + 'repeat(' + best.rows + ', ' + Math.floor(best.h) + 'px)';
    list.style.gridAutoRows = Math.floor(best.h) + 'px';
    tiles.forEach(function (t) {
      t.style.minHeight = '0';
      t.style.padding = Math.round(font * 0.3) + 'px';
      t.style.borderRadius = Math.round(font * 0.8) + 'px';
      t.querySelector('.result-tile-school').style.fontSize = font + 'px';
      t.querySelector('.result-tile-mark').style.fontSize = (font * 1.15) + 'px';
      t.querySelector('.result-tile-mark').style.minHeight = '0';
    });
  }
  window.addEventListener('resize', fitGrid);

  function renderScreen(sc) {
    var key = JSON.stringify(sc);
    if (key === lastScreenKey) return;
    var prevScroll = list.scrollTop;
    var wasResults = !overlay.hidden;
    lastScreenKey = key;

    slide.style.backgroundImage = 'none';
    textScreen.hidden = true;
    overlay.hidden = true;

    if (sc.kind === 'cover') {
      if (sc.src) slide.style.backgroundImage = "url('" + url(sc.src) + "')";
      else { textScreen.textContent = sc.text || ''; textScreen.hidden = false; }
    } else if (sc.kind === 'image') {
      slide.style.backgroundImage = "url('" + url(sc.src) + "')";
    } else if (sc.kind === 'text') {
      textScreen.textContent = sc.text || '';
      textScreen.hidden = false;
    } else if (sc.kind === 'results') {
      document.getElementById('overlay-title').textContent = sc.title;
      var logo = document.getElementById('overlay-logo');
      if (snap.event.logo_path) { logo.src = url(snap.event.logo_path); logo.hidden = false; } else { logo.hidden = true; }
      finalHasStart = sc.rows.some(function (r) { return r.start; });
      var render = rowRenderer();
      var html = sc.rows.map(render).join('');
      var isGrid = rowRenderer() === statusCard;
      list.classList.toggle('is-grid', isGrid);
      if (isGrid && html) html = LEGEND + html;
      list.innerHTML = html || '<div class="score-card"><span class="score-card-name">No contestants yet.</span></div>';
      overlay.hidden = false;
      overlay.classList.toggle('is-compact', isGrid);
      if (isGrid) fitGrid(); else list.style.cssText = '';
      list.scrollTop = wasResults ? prevScroll : 0;
    }
  }

  // sc.kind is 'results' for the screen; the results type is carried in snap.state.overlay.kind
  function rowRenderer() {
    var k = snap.state.overlay && snap.state.overlay.kind;
    return k === 'round' ? roundCard : ((k === 'final' || k === 'overall') ? finalCard : statusCard);
  }

  function renderTimer() {
    var t = snap && snap.timer;
    if (!t || !t.visible) { badge.hidden = true; return; }
    var ms = t.running ? t.remaining_ms - (performance.now() - recvAt) : t.remaining_ms;
    var s = Math.max(0, Math.ceil(ms / 1000));
    badge.textContent = String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
    badge.classList.toggle('is-low', s <= 5);
    badge.hidden = false;
  }

  // ---------- audio (live display only) ----------
  var audioReady = false, timerRun = null;
  var aBanner = document.getElementById('audio-banner');
  var aGame = document.getElementById('audio-game');
  var aTimer = document.getElementById('audio-timer');

  function setSrc(a, path) {
    var want = path ? url(path) : '';
    if (a.dataset.path !== want) { a.dataset.path = want; if (want) a.src = want; else a.removeAttribute('src'); }
  }
  function play(a, on) {
    if (!a.getAttribute('src')) return;
    if (on && a.paused) a.play().catch(function () {});
    if (!on && !a.paused) a.pause();
  }
  function syncAudio() {
    if (PREVIEW || !audioReady || !snap) return;
    var au = snap.audio, onCover = snap.state.hidden || snap.state.banner;
    setSrc(aBanner, au.banner); setSrc(aGame, au.game); setSrc(aTimer, au.timer);
    aBanner.volume = au.banner_volume / 100; aGame.volume = au.game_volume / 100;
    play(aBanner, onCover);
    play(aGame, !onCover);
    syncTimerAudio();
  }

  // The timer sound plays only for the last 5 seconds of a running countdown.
  function syncTimerAudio() {
    if (PREVIEW || !audioReady || !snap) return;
    var t = snap.timer;
    if (t.run_id !== timerRun) { timerRun = t.run_id; aTimer.currentTime = 0; }
    if (t.ended && !t.running) return; // time is up: let the sound finish on its own
    var ms = t.running ? t.remaining_ms - (performance.now() - recvAt) : t.remaining_ms;
    var onCover = snap.state.hidden || snap.state.banner;
    var inFinal = t.running && ms > 0 && ms <= 5000 && !onCover;
    if (!t.running && ms > 5000) aTimer.currentTime = 0; // reset or extended: start over next time
    play(aTimer, inFinal);
  }

  // ---------- scroll sync ----------
  function maxScroll() { return Math.max(0, list.scrollHeight - list.clientHeight); }

  function applyScroll() {
    if (overlay.hidden || snap.state.scroll_by === ME) return;
    var target = snap.state.scroll * maxScroll();
    var key = snap.state.scroll_by + ':' + snap.state.scroll;
    if (key === lastScroll) return; // already applied this update
    lastScroll = key;
    programmaticUntil = Date.now() + 700;
    list.scrollTop = target;
  }

  var sendTimer = null;
  list.addEventListener('scroll', function () {
    if (Date.now() < programmaticUntil || sendTimer) return;
    sendTimer = setTimeout(function () {
      sendTimer = null;
      var m = maxScroll();
      var ratio = m ? list.scrollTop / m : 0;
      lastScroll = ME + ':' + ratio;
      post('set_scroll', { ratio: ratio, by: ME });
    }, 200);
  });

  // ---------- network ----------
  async function post(action, payload) {
    var fd = new FormData();
    fd.append('action', action); fd.append('event', EVENT); fd.append('payload', JSON.stringify(payload || {}));
    var res = await fetch(API, { method: 'POST', body: fd });
    var json = await res.json();
    if (json.error) throw new Error(json.error);
    return json.snapshot;
  }

  async function poll() {
    try {
      snap = await post('state');
      recvAt = performance.now();
      renderScreen(snap.screen);
      applyScroll();
      syncAudio();
    } catch (e) { /* keep showing the last screen */ }
    setTimeout(poll, 1000);
  }

  // Tapping a result row's area never needs to do anything; the gate is the only click target.
  var gate = document.getElementById('start-gate');
  if (gate) {
    gate.addEventListener('click', function () {
      audioReady = true;
      gate.hidden = true;
      if (document.documentElement.requestFullscreen) document.documentElement.requestFullscreen().catch(function () {});
      syncAudio();
    });
  }

  setInterval(function () { renderTimer(); syncTimerAudio(); }, 200);
  poll();
})();
