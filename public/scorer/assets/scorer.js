(function () {
    'use strict';

    var root = document.getElementById('scorer-root');
    if (!root) return;

    var eventId = root.getAttribute('data-event-id');
    var csrfToken = root.getAttribute('data-csrf');
    var dashboard = JSON.parse(root.getAttribute('data-initial'));
    var POLL_MS = parseInt(root.getAttribute('data-poll-ms')) || 1000;
    var pollTimer = null;
    var actionInFlight = false;

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

    function doAction(action, params, triggerEl) {
        if (actionInFlight) return Promise.resolve();
        actionInFlight = true;
        if (triggerEl) triggerEl.disabled = true;

        return apiPost(action, params).then(function (res) {
            actionInFlight = false;
            if (triggerEl) triggerEl.disabled = false;

            if (!res.ok) {
                console.warn('Action failed:', res.error);
                return refresh();
            }

            if (res.state && res.current_question_scores !== undefined) {
                applyDashboard(res);
            } else if (res.state) {
                return refresh();
            }
        }).catch(function () {
            actionInFlight = false;
            if (triggerEl) triggerEl.disabled = false;
        });
    }

    function refresh() {
        return apiGet('state').then(function (res) {
            if (res.ok) {
                applyDashboard(res);
            }
        }).catch(function () {});
    }

    // ---- Rendering ------------------------------------------------------
    function applyDashboard(d) {
        dashboard = d;
        render();
    }

    function render() {
        var state = dashboard.state;
        var q = state.current_question;
        var rankings = dashboard.rankings || [];

        // Status bar
        document.getElementById('scorer-event-status').textContent =
            state.is_active ? '● LIVE' : (state.display_state === 'final_results' ? 'EVENT ENDED' : 'NOT STARTED');

        var progress = state.question_progress;
        var progressText = '';
        if (progress.position) {
            progressText = 'Question ' + progress.position + ' of ' + progress.total;
        } else {
            progressText = progress.total + ' question(s) configured';
        }
        document.getElementById('scorer-question-progress').textContent = progressText;

        // Reference info
        document.getElementById('ref-contestants').textContent = rankings.length;
        document.getElementById('ref-questions').textContent = progress.total;
        document.getElementById('ref-points').textContent = q ? q.points : (dashboard.state.event ? '—' : '0');
        document.getElementById('ref-time').textContent = q ? (q.time_seconds + 's') : '—';

        // Stats
        var totalEntries = (dashboard.current_question_scores || []).length;
        var totalPoints = 0;
        rankings.forEach(function (r) {
            totalPoints += r.total_score;
        });
        document.getElementById('stat-questions-answered').textContent = totalEntries;
        document.getElementById('stat-total-points').textContent = totalPoints;

        // Render main table
        renderScoreboard(rankings);

        // Render question detail
        renderQuestionDetail(q);

        // Render quick score
        renderQuickScore(q, rankings);

        // Render round breakdown
        renderRoundBreakdown(rankings, state);
    }

    function renderScoreboard(rankings) {
        var tbody = document.getElementById('scorer-table-body');
        if (!rankings || rankings.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="muted" style="text-align:center;padding:24px;">No contestants configured yet.</td></tr>';
            return;
        }

        tbody.innerHTML = rankings.map(function (r, idx) {
            var rankClass = '';
            if (r.rank === 1) rankClass = ' scorer-row-leader';
            return '<tr class="' + rankClass + '" data-contestant="' + r.contestant_id + '">' +
                '<td class="col-rank"><span class="rank-badge">' + r.rank + '</span></td>' +
                '<td class="col-number">' + (idx + 1) + '</td>' +
                '<td class="col-acronym">' + escapeHtml(r.acronym || r.team_code || '—') + '</td>' +
                '<td class="col-name">' + escapeHtml(r.name) + '</td>' +
                '<td class="col-total">' + r.total_score + '</td>' +
                '</tr>';
        }).join('');
    }

    function renderQuestionDetail(q) {
        var container = document.getElementById('scorer-question-detail');
        var scoresContainer = document.getElementById('scorer-question-scores');

        if (!q) {
            container.innerHTML = '<p class="muted">No question currently active.</p>';
            scoresContainer.innerHTML = '';
            return;
        }

        var imgHtml = q.image_path
            ? '<img src="../' + escapeHtml(q.image_path) + '" alt="Question ' + q.question_number + '">'
            : '';

        container.innerHTML =
            '<div class="scorer-question-meta">' +
                '<div class="meta-item"><strong>Q' + q.question_number + '</strong></div>' +
                '<div class="meta-item">Category: <span>' + escapeHtml(q.category || '—') + '</span></div>' +
                '<div class="meta-item">Points: <span>' + q.points + '</span></div>' +
                '<div class="meta-item">Time: <span>' + q.time_seconds + 's</span></div>' +
            '</div>' +
            imgHtml;

        // Show current question scores
        var scores = dashboard.current_question_scores || [];
        if (scores.length === 0) {
            scoresContainer.innerHTML = '<p class="muted">No scores recorded yet for this question.</p>';
            return;
        }

        var scoresByContestant = {};
        scores.forEach(function (s) {
            scoresByContestant[s.contestant_id] = s;
        });

        var rankingsMap = {};
        (dashboard.rankings || []).forEach(function (r) {
            rankingsMap[r.contestant_id] = r;
        });

        scoresContainer.innerHTML = '<table class="scorer-round-table">' +
            '<thead><tr><th>Contestant</th><th>Acro.</th><th>Result</th><th>Points</th></tr></thead>' +
            '<tbody>' +
            scores.map(function (s) {
                var r = rankingsMap[s.contestant_id] || {};
                var resultClass = s.result === 'correct' ? 'correct' : s.result === 'incorrect' ? 'incorrect' : 'no-answer';
                var resultLabel = s.result.charAt(0).toUpperCase() + s.result.slice(1).replace('_', ' ');
                return '<tr>' +
                    '<td>' + escapeHtml(r.name || '—') + '</td>' +
                    '<td>' + escapeHtml(r.acronym || r.team_code || '—') + '</td>' +
                    '<td><span class="result-' + resultClass + '">' + resultLabel + '</span></td>' +
                    '<td>' + s.points_awarded + '</td>' +
                    '</tr>';
            }).join('') +
            '</tbody></table>';
    }

    function renderQuickScore(q, rankings) {
        var container = document.getElementById('scorer-quick-score');

        if (!q) {
            container.innerHTML = '<p class="muted">Start a question to begin scoring.</p>';
            return;
        }

        var scoresByContestant = {};
        (dashboard.current_question_scores || []).forEach(function (s) {
            scoresByContestant[s.contestant_id] = s;
        });

        container.innerHTML = rankings.map(function (r) {
            var existing = scoresByContestant[r.contestant_id];

            function btn(result, label, cls) {
                var selected = existing && existing.result === result;
                return '<button type="button" class="scorer-quick-btn ' + cls + (selected ? ' selected' : '') + '" ' +
                    'data-contestant="' + r.contestant_id + '" data-result="' + result + '">' + label + '</button>';
            }

            return '<div class="scorer-quick-row">' +
                '<span class="scorer-quick-name"><span class="acronym">' + escapeHtml(r.acronym || r.team_code || '?') + '</span>' +
                escapeHtml(r.name) + '</span>' +
                '<div class="scorer-quick-buttons">' +
                    btn('correct', '+', 'correct') +
                    btn('incorrect', '−', 'incorrect') +
                    btn('no_answer', '—', 'no-answer') +
                '</div>' +
            '</div>';
        }).join('');

        container.querySelectorAll('.scorer-quick-btn').forEach(function (b) {
            b.addEventListener('click', onQuickScoreClick);
        });
    }

    function renderRoundBreakdown(rankings, state) {
        var container = document.getElementById('scorer-round-breakdown');
        
        // For now, show all scores grouped by question
        var allScores = dashboard.all_scores || [];
        if (allScores.length === 0) {
            container.innerHTML = '<p class="muted">Round data will appear here once scoring begins.</p>';
            return;
        }

        // Group by question
        var byQuestion = {};
        allScores.forEach(function (s) {
            if (!byQuestion[s.question_id]) {
                byQuestion[s.question_id] = {
                    question_number: s.question_number,
                    scores: []
                };
            }
            byQuestion[s.question_id].scores.push(s);
        });

        var rows = rankings.map(function (r) {
            var cells = '<td>' + escapeHtml(r.name) + '</td>';
            Object.keys(byQuestion).sort(function(a,b) { return a-b; }).forEach(function(qId) {
                var s = byQuestion[qId].scores.find(function(x) { return x.contestant_id === r.contestant_id; });
                cells += '<td>' + (s ? s.points_awarded : '—') + '</td>';
            });
            cells += '<td class="total-col">' + r.total_score + '</td>';
            return '<tr>' + cells + '</tr>';
        });

        var headers = '<th>Contestant</th>';
        Object.keys(byQuestion).sort(function(a,b) { return a-b; }).forEach(function(qId) {
            headers += '<th>Q' + byQuestion[qId].question_number + '</th>';
        });
        headers += '<th>Total</th>';

        container.innerHTML = '<table class="scorer-round-table"><thead><tr>' + headers + '</tr></thead><tbody>' + rows.join('') + '</tbody></table>';
    }

    function escapeHtml(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    // ---- Event handlers -------------------------------------------------
    function onQuickScoreClick(e) {
        var btn = e.currentTarget;
        var contestantId = btn.getAttribute('data-contestant');
        var result = btn.getAttribute('data-result');
        var q = dashboard.state.current_question;
        if (!q) return;

        var existing = (dashboard.current_question_scores || []).find(function (s) {
            return String(s.contestant_id) === String(contestantId);
        });

        if (existing && existing.result === result) return;

        if (existing) {
            var labels = { correct: 'Correct', incorrect: 'Incorrect', no_answer: 'No Answer' };
            if (!confirm('Change from ' + labels[existing.result] + ' to ' + labels[result] + '?')) return;
        }

        doAction('score', {
            question_id: q.id,
            contestant_id: contestantId,
            result: result
        }, btn).then(function () {
            // Flash animation
            var row = btn.closest('.scorer-quick-row');
            if (row) {
                row.classList.add(result === 'correct' ? 'scorer-flash-correct' : 'scorer-flash-incorrect');
                setTimeout(function() { row.classList.remove('scorer-flash-correct', 'scorer-flash-incorrect'); }, 1000);
            }
        });
    }

    document.getElementById('scorer-btn-refresh').addEventListener('click', function() {
        refresh();
    });

    document.getElementById('scorer-btn-export-excel').addEventListener('click', function() {
        window.open('../api/runtime.php?action=export_scores&format=excel&event_id=' + eventId, '_blank');
    });

    document.getElementById('scorer-btn-export-pdf').addEventListener('click', function() {
        window.open('../api/runtime.php?action=export_scores&format=pdf&event_id=' + eventId, '_blank');
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
