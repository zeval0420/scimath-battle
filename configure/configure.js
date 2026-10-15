// configure/configure.js - renders the configuration page from api.php state and saves changes.
(function () {
  var EVENT = Number(document.body.dataset.event);
  var API = 'api.php';
  var state = null;
  var ROUND_LABEL = { easy: 'Easy', medium: 'Average', hard: 'Difficult', clincher: 'Clincher' };
  var ROUNDS = Object.keys(ROUND_LABEL);
  var statusTimer;

  // ---------- helpers ----------
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function url(path) { return '../' + encodeURI(path); }
  function fileName(path) { return path ? path.split('/').pop() : ''; }
  function $(id) { return document.getElementById(id); }

  function setStatus(msg, isError) {
    var el = $('status');
    el.textContent = msg;
    el.classList.toggle('is-error', !!isError);
    clearTimeout(statusTimer);
    if (!isError && msg !== 'Saving...') statusTimer = setTimeout(function () { el.textContent = ''; }, 2500);
  }

  // Sends an action. opts: {file, render:false} (render:false keeps the DOM, so tabbing between inputs isn't interrupted).
  async function call(action, payload, opts) {
    opts = opts || {};
    setStatus('Saving...');
    var fd = new FormData();
    fd.append('action', action);
    fd.append('event', EVENT);
    fd.append('payload', JSON.stringify(payload || {}));
    if (opts.file) fd.append('file', opts.file);
    try {
      var res = await fetch(API, { method: 'POST', body: fd });
      var json = await res.json().catch(function () { return null; });
      if (!res.ok || !json || json.error) {
        setStatus((json && json.error) || 'Request failed. The file may exceed the server upload limit.', true);
        if (state) render(); // revert inputs to the saved values
        return false;
      }
      state = json.state;
      if (opts.render !== false) render();
      setStatus(action === 'load' ? '' : 'Saved');
      return true;
    } catch (e) {
      setStatus('Network error.', true);
      return false;
    }
  }

  function roundOptions(selected) {
    return ROUNDS.map(function (r) {
      return '<option value="' + r + '"' + (r === selected ? ' selected' : '') + '>' + ROUND_LABEL[r] + '</option>';
    }).join('');
  }

  // ---------- sections ----------
  function renderBranding() {
    function panel(key, title, path, hint) {
      return '<div class="panel"><h3>' + title + '</h3>' +
        (path ? '<img class="preview" src="' + url(path) + '" alt="">' : '<div class="preview-empty">Not set</div>') +
        '<input class="file-input" type="file" accept="image/*" data-act="upload" data-key="' + key + '">' +
        '<span class="field-hint">' + hint + '</span>' +
        (path ? '<div><button class="btn btn-sm" type="button" data-act="clear" data-key="' + key + '">Remove</button></div>' : '') +
        '</div>';
    }
    $('body-branding').innerHTML = '<div class="split">' +
      panel('logo', 'Event logo', state.event.logo_path, 'Shown on the score overlay.') +
      panel('cover', 'Cover slide', state.event.cover_image_path, 'Shown first on the display screen.') +
      '</div>';
  }

  function renderAudio() {
    var s = state.settings;
    function audioPanel(key, title, path, hint, volKey, vol) {
      return '<div class="panel"><h3>' + title + '</h3>' +
        (path ? '<audio controls preload="none" src="' + url(path) + '"></audio><span class="hint">' + esc(fileName(path)) + '</span>'
              : '<div class="preview-empty">Not set</div>') +
        '<input class="file-input" type="file" accept="audio/*" data-act="upload" data-key="' + key + '">' +
        '<span class="field-hint">' + hint + '</span>' +
        (volKey ? '<div class="field"><label class="field-label">Volume: <span id="vol-' + volKey + '">' + vol + '</span>%</label>' +
          '<input type="range" min="0" max="100" value="' + vol + '" data-act="volume" data-key="' + volKey + '"></div>' : '') +
        (path && key !== 'timer_audio' ? '<div><button class="btn btn-sm" type="button" data-act="clear" data-key="' + key + '">Remove</button></div>' : '') +
        '</div>';
    }
    var timerPath = s.timer_audio_path || s.default_timer_audio;
    var timer = '<div class="panel"><h3>Timer audio</h3>' +
      '<audio controls preload="none" src="' + url(timerPath) + '"></audio>' +
      '<span class="hint">' + (s.timer_audio_path ? esc(fileName(s.timer_audio_path)) : 'Default: ' + esc(fileName(s.default_timer_audio))) + '</span>' +
      '<input class="file-input" type="file" accept="audio/*" data-act="upload" data-key="timer_audio">' +
      '<span class="field-hint">Plays while the question timer runs.</span>' +
      (s.timer_audio_path ? '<div><button class="btn btn-sm" type="button" data-act="clear" data-key="timer_audio">Reset to default</button></div>' : '') +
      '</div>';
    $('body-audio').innerHTML = '<div class="split">' + timer +
      audioPanel('game_audio', 'Background music', s.game_audio_path, 'Loops during the competition.', 'game', s.game_audio_volume) +
      audioPanel('banner_audio', 'Cover / banner music', s.banner_audio_path, 'Plays on the cover slide.', 'banner', s.banner_audio_volume) +
      '</div>';
  }

  function renderRounds() {
    var s = state.settings;
    $('body-rounds').innerHTML =
      '<p class="hint">Each question uses its round\'s defaults unless you override them in the Questions table.</p>' +
      '<div class="rounds-grid">' + ROUNDS.map(function (r) {
        return '<div class="round-box round-' + r + '"><h3>' + ROUND_LABEL[r] + '</h3>' +
          '<div class="field"><label class="field-label">Points</label>' +
          '<input class="input" type="number" min="0" data-act="round-default" data-round="' + r + '" data-prop="points" value="' + s.rounds[r].points + '"></div>' +
          '<div class="field"><label class="field-label">Time limit (seconds)</label>' +
          '<input class="input" type="number" min="1" data-act="round-default" data-round="' + r + '" data-prop="time" value="' + s.rounds[r].time + '"></div>' +
          '</div>';
      }).join('') + '</div>';
  }

  function renderSlides() {
    var slides = state.slides;
    if (!slides.length) {
      $('body-slides').innerHTML = '<div class="empty">No slides uploaded for this event.</div>';
      return;
    }
    var counts = { promo: 0, question: 0, answer: 0 };
    slides.forEach(function (s) { counts[s.kind]++; });
    var KIND = { promo: 'Promotional', question: 'Question', answer: 'Answer' };
    $('body-slides').innerHTML =
      '<p class="hint">Slides are listed in upload order. Each answer slide is paired with the question slide in the same position of appearance (1st answer &rarr; 1st question, and so on).</p>' +
      '<div class="row">' +
      '<button class="btn" type="button" data-act="alternate">Alternate: question, answer, question, answer...</button>' +
      '<button class="btn" type="button" data-act="all-promo">Set all as promotional</button>' +
      '<button class="btn btn-danger" type="button" data-act="delete-all-slides">Delete all slides</button>' +
      '<span class="hint">' + counts.question + ' question(s), ' + counts.answer + ' answer(s), ' + counts.promo + ' promotional</span>' +
      '</div>' +
      '<div class="tile-grid">' + slides.map(function (s, i) {
        return '<div class="tile tile-' + s.kind + '"><img loading="lazy" src="' + url(s.path) + '" alt="">' +
          '<span class="tile-name">' + (i + 1) + '. ' + esc(fileName(s.path)) + '</span>' +
          '<select class="dropdown dropdown-sm" data-act="kind" data-kind="' + s.kind + '" data-id="' + s.id + '">' +
          Object.keys(KIND).map(function (k) {
            return '<option value="' + k + '"' + (k === s.kind ? ' selected' : '') + '>' + KIND[k] + '</option>';
          }).join('') + '</select>' +
          '<button class="btn btn-sm btn-danger" type="button" data-act="delete-slide" data-kind="' + s.kind + '" data-id="' + s.id + '">Delete</button></div>';
      }).join('') + '</div>';
  }

  function renderQuestions() {
    var qs = state.questions;
    if (!qs.length) {
      $('body-questions').innerHTML = '<div class="empty">No questions yet. Classify slides as "Question" above.</div>';
      return;
    }
    var d = state.settings.rounds;
    $('body-questions').innerHTML =
      '<div class="row">' +
      '<span class="field-label">Set questions</span>' +
      '<input class="input input-sm num" type="number" min="1" id="bulk-from" placeholder="from #">' +
      '<span class="field-label">to</span>' +
      '<input class="input input-sm num" type="number" min="1" id="bulk-to" placeholder="to #">' +
      '<span class="field-label">as</span>' +
      '<select class="dropdown dropdown-sm" id="bulk-round">' + roundOptions('easy') + '</select>' +
      '<button class="btn btn-sm" type="button" data-act="bulk-round">Apply</button>' +
      '</div>' +
      '<p class="hint">Leave points or time blank to use the round default (shown in grey).</p>' +
      '<div class="table-wrap"><table class="table"><thead><tr>' +
      '<th>#</th><th>Question</th><th>Answer</th><th>Round</th><th>Points</th><th>Time (s)</th></tr></thead><tbody>' +
      qs.map(function (q) {
        return '<tr class="tr-' + q.round + '" data-qid="' + q.id + '">' +
          '<td><b>' + q.number + '</b></td>' +
          '<td><img class="thumb" loading="lazy" src="' + url(q.image_path) + '" alt=""></td>' +
          '<td>' + (q.answer_image_path ? '<img class="thumb" loading="lazy" src="' + url(q.answer_image_path) + '" alt="">' : '<span class="cell-none">none</span>') + '</td>' +
          '<td><select class="dropdown dropdown-sm" data-act="q-field" data-prop="round">' + roundOptions(q.round) + '</select></td>' +
          '<td><input class="input input-sm num" type="number" min="0" data-act="q-field" data-prop="points" placeholder="' + d[q.round].points + '" value="' + (q.points == null ? '' : q.points) + '"></td>' +
          '<td><input class="input input-sm num" type="number" min="1" data-act="q-field" data-prop="time" placeholder="' + d[q.round].time + '" value="' + (q.time_seconds == null ? '' : q.time_seconds) + '"></td>' +
          '</tr>';
      }).join('') + '</tbody></table></div>';
  }

  function renderPromo() {
    var promo = state.slides.filter(function (s) { return s.kind === 'promo'; });
    if (!promo.length) {
      $('body-promo').innerHTML = '<div class="empty">No promotional slides. Classify slides as "Promotional" above.</div>';
      return;
    }
    $('body-promo').innerHTML = promo.map(function (s) {
      return '<div class="list-row" data-pid="' + s.id + '">' +
        '<img class="thumb" loading="lazy" src="' + url(s.path) + '" alt="">' +
        '<input class="input" type="text" maxlength="255" placeholder="Slide name" data-act="promo-field" value="' + esc(s.title) + '">' +
        '<label class="switch"><input type="checkbox" data-act="promo-field"' + (Number(s.is_active) ? ' checked' : '') +
        '><span class="switch-track"></span>Shown</label></div>';
    }).join('');
  }

  function renderContestants() {
    var cs = state.contestants;
    $('body-contestants').innerHTML =
      (cs.length ? cs.map(function (c) {
        return '<div class="list-row" data-cid="' + c.id + '">' +
          '<input class="input" type="text" maxlength="255" placeholder="Acronym" data-act="c-field" data-prop="acronym" value="' + esc(c.acronym) + '">' +
          '<input class="input" type="text" maxlength="150" placeholder="Name" data-act="c-field" data-prop="name" value="' + esc(c.name) + '">' +
          '<button class="btn btn-sm btn-danger" type="button" data-act="c-del">Delete</button></div>';
      }).join('') : '<div class="empty">No contestants yet.</div>') +
      '<form class="row-form" id="c-add-form">' +
      '<div class="field"><label class="field-label" for="c-acr">Acronym</label><input class="input" id="c-acr" maxlength="255"></div>' +
      '<div class="field"><label class="field-label" for="c-name">Name</label><input class="input" id="c-name" maxlength="150" required></div>' +
      '<button class="btn btn-primary" type="submit">Add contestant</button></form>' +
      '<div class="field"><label class="field-label" for="c-csv">Or import from a CSV file</label>' +
      '<input type="file" id="c-csv" accept=".csv,text/csv,text/plain" data-act="c-csv">' +
      '<span class="hint">One school per row: <b>acronym,name</b> (or just the name). A header row with "acronym" and "name" columns also works. Schools already listed are skipped. <a href="../assets/schools_template.csv" download>Download CSV template</a></span></div>';
  }

  // Minimal CSV parser (quoted fields, "" escapes, CRLF, BOM).
  function parseCsv(text) {
    text = text.replace(/^﻿/, '');
    var rows = [], row = [], cell = '', q = false;
    for (var i = 0; i < text.length; i++) {
      var ch = text[i];
      if (q) {
        if (ch === '"' && text[i + 1] === '"') { cell += '"'; i++; }
        else if (ch === '"') q = false;
        else cell += ch;
      } else if (ch === '"') q = true;
      else if (ch === ',' || ch === ';' || ch === '\t') { row.push(cell); cell = ''; }
      else if (ch === '\n' || ch === '\r') {
        if (ch === '\r' && text[i + 1] === '\n') i++;
        row.push(cell); cell = '';
        rows.push(row); row = [];
      } else cell += ch;
    }
    row.push(cell);
    rows.push(row);
    return rows.filter(function (r) { return r.some(function (c) { return c.trim() !== ''; }); });
  }

  function csvToContestants(text) {
    var rows = parseCsv(text), nameCol = -1, acrCol = -1;
    if (rows.length) {
      var head = rows[0].map(function (c) { return c.trim().toLowerCase(); });
      nameCol = head.findIndex(function (c) { return /^(name|school|school name|team|team name|contestant)$/.test(c); });
      acrCol = head.findIndex(function (c) { return /^(acronym|code|abbreviation|short name)$/.test(c); });
      if (nameCol !== -1 || acrCol !== -1) rows.shift(); else { nameCol = -1; acrCol = -1; }
    }
    return rows.map(function (r) {
      if (nameCol === -1 && acrCol === -1) { // no header: "acronym,name" or just "name"
        return r.length > 1 ? { acronym: r[0].trim(), name: r[1].trim() } : { acronym: '', name: r[0].trim() };
      }
      return { acronym: acrCol === -1 ? '' : (r[acrCol] || '').trim(), name: nameCol === -1 ? '' : (r[nameCol] || '').trim() };
    });
  }

  function render() {
    renderBranding();
    renderAudio();
    renderRounds();
    renderSlides();
    renderQuestions();
    renderPromo();
    renderContestants();
  }

  // ---------- events ----------
  function qRow(el) { return el.closest('[data-qid]'); }

  document.addEventListener('change', function (e) {
    var el = e.target, act = el.dataset.act;
    if (!act) return;

    if (act === 'c-csv') {
      var f = el.files[0];
      if (!f) return;
      var before = state.contestants.length;
      f.text().then(function (text) {
        return call('import_contestants', { rows: csvToContestants(text) });
      }).then(function (ok) {
        if (ok) setStatus('Imported ' + (state.contestants.length - before) + ' school(s).');
      });
      return;
    }
    if (act === 'upload') {
      if (el.files[0]) call('upload_asset', { key: el.dataset.key }, { file: el.files[0] });
    } else if (act === 'volume') {
      $('vol-' + el.dataset.key).textContent = el.value;
      var p = {}; p[el.dataset.key] = el.value;
      call('save_volumes', p, { render: false });
    } else if (act === 'round-default') {
      var rounds = {};
      ROUNDS.forEach(function (r) { rounds[r] = {}; });
      document.querySelectorAll('[data-act="round-default"]').forEach(function (i) {
        rounds[i.dataset.round][i.dataset.prop] = i.value;
      });
      // Re-render so the question placeholders show the new defaults.
      call('save_round_defaults', { rounds: rounds });
    } else if (act === 'kind') {
      call('classify', { items: [{ kind: el.dataset.kind, id: Number(el.dataset.id), to: el.value }] });
    } else if (act === 'q-field') {
      var row = qRow(el);
      var round = row.querySelector('[data-prop=round]').value;
      call('save_question', {
        id: Number(row.dataset.qid), round: round,
        points: row.querySelector('[data-prop=points]').value,
        time: row.querySelector('[data-prop=time]').value
      }, { render: el.dataset.prop === 'round' });
    } else if (act === 'promo-field') {
      var pr = el.closest('[data-pid]');
      call('save_promo', {
        id: Number(pr.dataset.pid),
        title: pr.querySelector('input[type=text]').value,
        active: pr.querySelector('input[type=checkbox]').checked
      }, { render: false });
    } else if (act === 'c-field') {
      var cr = el.closest('[data-cid]');
      call('save_contestant', {
        id: Number(cr.dataset.cid),
        acronym: cr.querySelector('[data-prop=acronym]').value,
        name: cr.querySelector('[data-prop=name]').value
      }, { render: false });
    }
  });

  document.addEventListener('click', function (e) {
    var tgt = e.target.closest('[data-act], [data-toggle]');
    if (!tgt) return;

    if (tgt.dataset.toggle) {
      var card = $(tgt.dataset.toggle);
      tgt.textContent = card.classList.toggle('is-collapsed') ? 'Show' : 'Hide';
      return;
    }
    var act = tgt.dataset.act;
    if (act === 'clear') {
      call('clear_asset', { key: tgt.dataset.key });
    } else if (act === 'alternate' || act === 'all-promo') {
      var msg = act === 'alternate'
        ? 'Re-classify ALL slides as alternating question / answer?'
        : 'Re-classify ALL slides as promotional? Questions will be removed.';
      if (!confirm(msg)) return;
      call('classify', {
        items: state.slides.map(function (s, i) {
          return { kind: s.kind, id: s.id, to: act === 'all-promo' ? 'promo' : (i % 2 === 0 ? 'question' : 'answer') };
        })
      });
    } else if (act === 'delete-slide') {
      var sid = Number(tgt.dataset.id), sk = tgt.dataset.kind;
      var sl = state.slides.filter(function (s) { return s.kind === sk && Number(s.id) === sid; })[0];
      var sc = sl ? Number(sl.score_count || 0) : 0;
      if (confirm('Delete this slide?' + (sc ? '\n\nIt is a question with ' + sc + ' recorded score(s); those are deleted too.' : ''))) {
        call('delete_slide', { kind: sk, id: sid, force: sc > 0 });
      }
    } else if (act === 'delete-all-slides') {
      var scored = state.slides.reduce(function (n, s) { return n + Number(s.score_count || 0); }, 0);
      if (confirm('Delete ALL ' + state.slides.length + ' slides? This cannot be undone.' + (scored ? '\n\nRecorded scores for the questions will be deleted too.' : ''))) {
        call('delete_all_slides', { force: scored > 0 });
      }
    } else if (act === 'bulk-round') {
      call('bulk_round', { from: $('bulk-from').value, to: $('bulk-to').value, round: $('bulk-round').value });
    } else if (act === 'c-del') {
      var row = tgt.closest('[data-cid]');
      var name = row.querySelector('[data-prop=name]').value;
      var cid = Number(row.dataset.cid);
      var info = state.contestants.filter(function (c) { return Number(c.id) === cid; })[0];
      var scored = info ? Number(info.score_count) : 0;
      var msg = 'Delete contestant "' + name + '"?' + (scored ? '\n\nThey have ' + scored + ' recorded score(s), which will be deleted too.' : '');
      if (confirm(msg)) call('delete_contestant', { id: cid, force: scored > 0 });
    }
  });

  document.addEventListener('submit', function (e) {
    if (e.target.id !== 'c-add-form') return;
    e.preventDefault();
    call('add_contestant', { acronym: $('c-acr').value, name: $('c-name').value });
  });

  call('load');
})();
