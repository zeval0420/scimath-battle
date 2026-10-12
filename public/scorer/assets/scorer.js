(function () {
    'use strict';

    var root = document.getElementById('scorer-root');
    if (!root) return;

    var eventId = root.getAttribute('data-event-id');
    var csrfToken = root.getAttribute('data-csrf');
    var initial = JSON.parse(root.getAttribute('data-initial'));
    var POLL_MS = parseInt(root.getAttribute('data-poll-ms'), 10) || 3000;

    var pollTimer = null;
    var actionInFlight = false;

    var state = {
        selectedQuestionId: initial.selected_question_id,
        questions: initial.questions || [],
        contestants: initial.contestants || [],
        cells: initial.cells || {},
        ranks: initial.ranks || {},
        totals: initial.totals || {},
        question_sums: initial.question_sums || {}
    };

    // ---- API helpers --------------------------------------------------
    function apiGet(action, params) {
        var qs = new URLSearchParams(Object.assign({ action: action, event_id: eventId }, params || {}));
        return fetch('../api/runtime.php?' + qs.toString(), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); });
    }

    function apiPost(action, params) {
        var body = new URLSearchParams(Object.assign({
            action: action, event_id: eventId, csrf_token: csrfToken
        }, params || {}));
        return fetch('../api/runtime.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }

    function refresh() {
        return apiGet('report').then(function (res) {
            if (res.ok && res.report) {
                applyReport(res.report);
            }
        }).catch(function () {});
    }

    function applyReport(report) {
        state.cells = report.cells || {};
        state.ranks = report.ranks || {};
        state.totals = report.totals || {};
        state.question_sums = report.question_sums || {};
        render();
    }

    // ---- State helpers --------------------------------------------------
    function cell(contestantId, questionId) {
        var byContestant = state.cells[String(contestantId)];
        if (!byContestant) return null;
        return byContestant[String(questionId)] || null;
    }

    function total(contestantId) {
        var t = state.totals[String(contestantId)];
        if (t !== undefined) return t;
        var c = findContestant(contestantId);
        return c ? c.starting_score : 0;
    }

    function rank(contestantId) {
        var r = state.ranks[String(contestantId)];
        return r === undefined ? null : r;
    }

    function findContestant(id) {
        for (var i = 0; i < state.contestants.length; i++) {
            if (String(state.contestants[i].id) === String(id)) return state.contestants[i];
        }
        return null;
    }

    function contestantLabel(c) {
        if (!c) return '—';
        return c.name;
    }

    function tag(c) {
        if (!c) return '?';
        return c.acronym || c.team_code || '?';
    }

    var ROUND_LABELS = { easy: 'Easy', medium: 'Medium', hard: 'Hard', clincher: 'Clincher' };
    function roundLabel(r) {
        if (!r) return '';
        return ROUND_LABELS[r] || (r.charAt(0).toUpperCase() + r.slice(1));
    }

    // In the clincher round only the manually selected teams take part, so
    // the marking panel is limited to those teams. Other rounds include
    // every active contestant.
    function contestantsForQuestion(q) {
        if (q && q.round === 'clincher') {
            return state.contestants.filter(function (c) { return !!c.is_clincher; });
        }
        return state.contestants;
    }

    function selectedQuestion() {
        var id = state.selectedQuestionId;
        for (var i = 0; i < state.questions.length; i++) {
            if (String(state.questions[i].id) === String(id)) return state.questions[i];
        }
        return null;
    }

    function escapeHtml(value) {
        if (value === null || value === undefined) return '';
        var d = document.createElement('div');
        d.textContent = String(value);
        return d.innerHTML;
    }

    // ---- Rendering --------------------------------------------------
    function render() {
        renderItemSummary();
        renderQuickScore();
        renderScoreboard();
        renderMatrix();
        renderStats();
    }

    function renderItemSummary() {
        var el = document.getElementById('scorer-item-summary');
        var badge = document.getElementById('scorer-round-badge');
        var q = selectedQuestion();
        var refPoints = document.getElementById('ref-points');

        document.getElementById('ref-contestants').textContent = state.contestants.length;
        document.getElementById('ref-questions').textContent = state.questions.length;

        if (badge) {
            if (q) {
                badge.textContent = roundLabel(q.round) + ' Round';
                badge.className = 'scorer-round-badge round-' + q.round;
                badge.style.display = '';
            } else {
                badge.style.display = 'none';
            }
        }

        if (!q) {
            el.textContent = state.questions.length === 0
                ? 'No active questions are configured for this event.'
                : '';
            if (refPoints) refPoints.textContent = '—';
            return;
        }

        var eligible = contestantsForQuestion(q);
        var marked = 0;
        for (var i = 0; i < eligible.length; i++) {
            if (cell(eligible[i].id, q.id)) marked++;
        }
        el.textContent = 'Marked ' + marked + ' of ' + eligible.length
            + (q.category ? ' · ' + q.category : '');
        if (refPoints) refPoints.textContent = q.points;
    }

    function renderQuickScore() {
        var container = document.getElementById('scorer-quick-score');
        var q = selectedQuestion();

        if (!q) {
            container.innerHTML = '<p class="muted">Select an item above to begin marking.</p>';
            return;
        }
        if (state.contestants.length === 0) {
            container.innerHTML = '<p class="muted">No active contestants are configured for this event.</p>';
            return;
        }

        var eligible = contestantsForQuestion(q);
        var clincherNote = '';
        if (q.round === 'clincher') {
            clincherNote = '<p class="scorer-clincher-note">Clincher round — only the ' + eligible.length
                + ' selected team(s) can be marked.</p>';
            if (eligible.length === 0) {
                container.innerHTML = clincherNote
                    + '<p class="muted">No teams have been selected for the clincher round yet.</p>';
                return;
            }
        }

        container.innerHTML = clincherNote + eligible.map(function (c) {
            var existing = cell(c.id, q.id);
            var result = existing ? existing.result : null;
            var points = existing ? existing.points_awarded : null;

            function btn(value, label, cls) {
                var selected = result === value;
                return '<button type="button" class="scorer-quick-btn ' + cls + (selected ? ' selected' : '') + '" '
                    + 'data-contestant="' + c.id + '" data-result="' + value + '">' + label + '</button>';
            }

            var pointsLabel = existing
                ? '<span class="scorer-quick-points">' + points + ' pt' + (points === 1 ? '' : 's') + '</span>'
                : '<span class="scorer-quick-points muted">unmarked</span>';

            return '<div class="scorer-quick-row" data-contestant="' + c.id + '">'
                + '<span class="scorer-quick-name"><span class="acronym">' + escapeHtml(tag(c)) + '</span>'
                + escapeHtml(contestantLabel(c)) + '</span>'
                + pointsLabel
                + '<div class="scorer-quick-buttons">'
                + btn('correct', 'Correct', 'correct')
                + btn('incorrect', 'Wrong', 'incorrect')
                + btn('no_answer', 'No Answer', 'no-answer')
                + '</div>'
                + '</div>';
        }).join('');

        container.querySelectorAll('.scorer-quick-btn').forEach(function (b) {
            b.addEventListener('click', onScoreClick);
        });
    }

    function renderScoreboard() {
        var tbody = document.getElementById('scorer-table-body');
        if (!tbody) return;

        if (state.contestants.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="muted" style="text-align:center;padding:24px;">No contestants configured yet.</td></tr>';
            return;
        }

        var rows = state.contestants.slice().sort(function (a, b) {
            return total(b.id) - total(a.id);
        });

        tbody.innerHTML = rows.map(function (c, idx) {
            var r = rank(c.id);
            var rankClass = r === 1 ? ' scorer-row-leader' : '';
            return '<tr class="' + rankClass + '">'
                + '<td class="col-rank"><span class="rank-badge">' + (r === null ? idx + 1 : r) + '</span></td>'
                + '<td class="col-acronym">' + escapeHtml(tag(c)) + '</td>'
                + '<td class="col-name">' + escapeHtml(contestantLabel(c)) + '</td>'
                + '<td class="col-total">' + total(c.id) + '</td>'
                + '</tr>';
        }).join('');
    }

    function renderMatrix() {
        var wrap = document.getElementById('scorer-matrix-wrap');
        if (!wrap) return;

        if (state.questions.length === 0 || state.contestants.length === 0) {
            wrap.innerHTML = '<p class="muted">This event needs at least one active contestant and one active question to show a score table.</p>';
            return;
        }

        var selectedId = String(state.selectedQuestionId);

        // Group questions by round, in canonical order.
        var ORDER = ['easy', 'medium', 'hard', 'clincher'];
        var byRound = {};
        state.questions.forEach(function (q) {
            var r = q.round || 'easy';
            (byRound[r] = byRound[r] || []).push(q);
        });
        var groupKeys = ORDER.filter(function (r) { return byRound[r]; });
        Object.keys(byRound).forEach(function (r) {
            if (groupKeys.indexOf(r) === -1) groupKeys.push(r);
        });

        var headTop = '<th class="name-col" rowspan="2">Contestant</th>';
        groupKeys.forEach(function (r) {
            headTop += '<th class="round-group round-' + r + '" colspan="' + byRound[r].length + '">'
                + roundLabel(r) + '</th>';
        });
        headTop += '<th class="total-col" rowspan="2">Total</th><th rowspan="2">Rank</th>';

        var headBottom = '';
        state.questions.forEach(function (q) {
            var cls = String(q.id) === selectedId ? ' class="is-selected"' : '';
            headBottom += '<th' + cls + '>Q' + q.question_number + '</th>';
        });

        var body = state.contestants.map(function (c) {
            var cells = '<td class="name-col">' + escapeHtml(contestantLabel(c)) + '</td>';
            state.questions.forEach(function (q) {
                var entry = cell(c.id, q.id);
                var cls = String(q.id) === selectedId ? ' class="is-selected"' : '';
                cells += '<td' + cls + '>' + (entry ? entry.points_awarded : '—') + '</td>';
            });
            var r = rank(c.id);
            cells += '<td class="total-col">' + total(c.id) + '</td>';
            cells += '<td>' + (r === null ? '—' : '#' + r) + '</td>';
            return '<tr>' + cells + '</tr>';
        }).join('');

        var foot = '<td class="name-col">Total per question</td>';
        state.questions.forEach(function (q) {
            var cls = String(q.id) === selectedId ? ' class="is-selected"' : '';
            foot += '<td' + cls + '>' + (state.question_sums[String(q.id)] || 0) + '</td>';
        });
        var grand = 0;
        state.contestants.forEach(function (c) { grand += total(c.id); });
        foot += '<td class="total-col">' + grand + '</td><td></td>';

        wrap.innerHTML = '<table class="scorer-matrix">'
            + '<thead><tr>' + headTop + '</tr><tr>' + headBottom + '</tr></thead>'
            + '<tbody>' + body + '</tbody>'
            + '<tfoot><tr>' + foot + '</tr></tfoot>'
            + '</table>';
    }

    function renderStats() {
        var q = selectedQuestion();
        var marked = 0;
        var correct = 0;

        if (q) {
            contestantsForQuestion(q).forEach(function (c) {
                var entry = cell(c.id, q.id);
                if (entry) {
                    marked++;
                    if (entry.result === 'correct') correct++;
                }
            });
        }

        var totalPoints = 0;
        Object.keys(state.question_sums).forEach(function (k) {
            totalPoints += Number(state.question_sums[k]) || 0;
        });

        document.getElementById('stat-marked').textContent = marked;
        document.getElementById('stat-correct').textContent = correct;
        document.getElementById('stat-total-points').textContent = totalPoints;
    }

    // ---- Event handlers -------------------------------------------------
    function onScoreClick(e) {
        var btn = e.currentTarget;
        var contestantId = btn.getAttribute('data-contestant');
        var result = btn.getAttribute('data-result');
        var q = selectedQuestion();
        if (!q) return;

        var existing = cell(contestantId, q.id);

        if (existing && existing.result === result) {
            return; // already marked this way
        }

        doScore(q, contestantId, result, btn);
    }

    function doScore(question, contestantId, result, triggerEl) {
        if (actionInFlight) return;
        actionInFlight = true;
        if (triggerEl) triggerEl.disabled = true;

        apiPost('score', {
            question_id: question.id,
            contestant_id: contestantId,
            result: result
        }).then(function (res) {
            actionInFlight = false;
            if (triggerEl) triggerEl.disabled = false;

            if (!res.ok) {
                alert(res.error || 'Could not record the score.');
                return;
            }

            // Apply the returned entry directly, then refresh totals/ranks
            // from the authoritative report.
            state.cells[String(contestantId)] = state.cells[String(contestantId)] || {};
            state.cells[String(contestantId)][String(question.id)] = res.entry;

            flashRow(contestantId, result);
            render();
            refresh();
        }).catch(function () {
            actionInFlight = false;
            if (triggerEl) triggerEl.disabled = false;
        });
    }

    function flashRow(contestantId, result) {
        var row = document.querySelector('.scorer-quick-row[data-contestant="' + contestantId + '"]');
        if (!row) return;
        var cls = result === 'correct' ? 'scorer-flash-correct' : 'scorer-flash-incorrect';
        row.classList.add(cls);
        setTimeout(function () { row.classList.remove('scorer-flash-correct', 'scorer-flash-incorrect'); }, 1000);
    }

    function onQuestionChange(e) {
        var id = e.target.value;
        state.selectedQuestionId = id === '' ? null : id;
        render();
    }

    document.getElementById('scorer-question-select').addEventListener('change', onQuestionChange);
    document.getElementById('scorer-btn-refresh').addEventListener('click', function () {
        refresh();
    });

    // ---- Polling ----------------------------------------------------------
    function startPolling() {
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(function () {
            if (!actionInFlight) refresh();
        }, POLL_MS);
    }

    render();
    startPolling();
})();
