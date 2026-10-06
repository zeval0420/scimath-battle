(function () {
    'use strict';

    var root = document.getElementById('op-root');
    if (!root) return;

    var eventId = root.getAttribute('data-event-id');
    var csrfToken = root.getAttribute('data-csrf');
    var dashboard = JSON.parse(root.getAttribute('data-initial'));

    var POLL_MS = 1000;
    var pollTimer = null;
    var actionInFlight = false; // prevents overlapping requests / double-submission

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

    /**
     * Wraps a mutating action: prevents overlapping submissions (the
     * "accidental double-click" guard on the client side, backing up the
     * server-side idempotency already in CompetitionRuntime), disables the
     * triggering button while in flight, and re-renders from the response.
     */
    function doAction(action, params, triggerEl) {
        if (actionInFlight) return Promise.resolve();
        actionInFlight = true;
        if (triggerEl) triggerEl.disabled = true;

        return apiPost(action, params).then(function (res) {
            actionInFlight = false;
            if (triggerEl) triggerEl.disabled = false;

            if (!res.ok) {
                alert(res.error || 'Action failed.');
                // Refresh state anyway -- the failed action may have been
                // blocked by a state change we haven't seen yet.
                return refresh();
            }

            if (res.state && res.current_question_scores !== undefined) {
                applyDashboard(res);
            } else if (res.state) {
                // Action endpoints that only return {state:...} -- follow up
                // with a full dashboard fetch to keep scores/rankings in sync
                // (e.g. navigating changes the current question's scores).
                return refresh();
            }
        }).catch(function () {
            actionInFlight = false;
            if (triggerEl) triggerEl.disabled = false;
            setPollIndicator(false);
        });
    }

    function refresh() {
        return apiGet('state').then(function (res) {
            if (res.ok) {
                applyDashboard(res);
                setPollIndicator(true);
            } else {
                setPollIndicator(false);
            }
        }).catch(function () { setPollIndicator(false); });
    }

    function setPollIndicator(ok) {
        var el = document.getElementById('op-poll-indicator');
        if (!el) return;
        if (ok) {
            el.textContent = 'Live — updating every second';
            el.classList.remove('stale');
        } else {
            el.textContent = 'Connection issue — retrying…';
            el.classList.add('stale');
        }
    }

    // ---- Rendering ------------------------------------------------------

    function applyDashboard(d) {
        dashboard = d;
        render();
    }

    function fmtTime(seconds) {
        if (seconds === null || seconds === undefined) return '--';
        var m = Math.floor(seconds / 60);
        var s = seconds % 60;
        return m + ':' + String(s).padStart(2, '0');
    }

    function render() {
        var state = dashboard.state;
        var q = state.current_question;
        var timer = state.timer;
        var progress = state.question_progress;

        // Progress line
        document.getElementById('op-progress').textContent =
            (state.is_active ? 'Active' : 'Not started') +
            (progress.position ? ' — Question ' + progress.position + ' of ' + progress.total : ' — ' + progress.total + ' question(s) configured');

        // Public display state banner
        var stateBanner = document.getElementById('op-display-state');
        var stateLabels = { cover: 'Cover', question: 'Question', time_up: "Time's Up", ranking: 'Ranking', final_results: 'Final Results' };
        stateBanner.className = 'op-display-state state-' + state.display_state;
        document.getElementById('op-display-state-text').textContent = stateLabels[state.display_state] || state.display_state;

        // Event controls
        document.getElementById('op-btn-start-event').style.display = state.is_active ? 'none' : '';
        document.getElementById('op-btn-end-event').style.display = state.is_active ? '' : 'none';

        // Question panel
        if (q) {
            document.getElementById('op-q-number').textContent = 'Q' + q.question_number;
            document.getElementById('op-q-category').textContent = q.category || '—';
            document.getElementById('op-q-points').textContent = q.points;
            document.getElementById('op-q-time').textContent = q.time_seconds + 's';
            var img = document.getElementById('op-q-preview');
            img.src = '../' + q.image_path;
            img.style.display = '';
            document.getElementById('op-q-none').style.display = 'none';
        } else {
            document.getElementById('op-q-number').textContent = '—';
            document.getElementById('op-q-category').textContent = '—';
            document.getElementById('op-q-points').textContent = '—';
            document.getElementById('op-q-time').textContent = '—';
            document.getElementById('op-q-preview').style.display = 'none';
            document.getElementById('op-q-none').style.display = '';
        }

        // Navigation buttons: disabled states prevent invalid actions before
        // the server even has to reject them.
        document.getElementById('op-btn-start-first').disabled = !state.is_active || q !== null;
        document.getElementById('op-btn-prev').disabled = !state.is_active || q === null || progress.is_first;
        document.getElementById('op-btn-next').disabled = !state.is_active || q === null || progress.is_last;
        document.getElementById('op-goto-select').disabled = !state.is_active;

        // Timer
        var timerEl = document.getElementById('op-timer-display');
        timerEl.textContent = fmtTime(timer.remaining_seconds);
        timerEl.className = 'op-timer-display ' + (
            timer.is_running ? 'timer-running' :
            timer.is_paused ? 'timer-paused' :
            (state.display_state === 'time_up' ? 'timer-expired' : 'timer-idle')
        );
        document.getElementById('op-btn-timer-start').disabled = !state.is_active || q === null || timer.is_running || timer.is_paused;
        document.getElementById('op-btn-timer-pause').disabled = !state.is_active || !timer.is_running;
        document.getElementById('op-btn-timer-resume').disabled = !state.is_active || !timer.is_paused;
        document.getElementById('op-btn-timer-reset').disabled = !state.is_active || q === null;

        // Ranking controls
        document.getElementById('op-btn-show-ranking').disabled = !state.is_active;
        document.getElementById('op-btn-return-to-question').disabled = !state.is_active || q === null;
        document.getElementById('op-btn-show-final').disabled = !state.is_active;
        document.getElementById('op-btn-return-to-cover').disabled = !state.is_active;
        document.getElementById('op-auto-rank-hint').style.display = state.auto_show_ranking_after_score ? '' : 'none';

        // Question controls
        document.getElementById('op-btn-hide-question').disabled = !state.is_active || q === null;
        document.getElementById('op-btn-show-question').disabled = !state.is_active;
        document.getElementById('op-btn-preview-next').disabled = !state.is_active;

        // Round controls
        document.getElementById('op-btn-start-round').disabled = !state.is_active;
        document.getElementById('op-btn-end-round').disabled = !state.is_active;

        // Next preview
        renderNextPreview(state, allQuestions);

        // Score entry
        renderScores(q, state.is_active);

        // Rankings
        renderRankings();
    }

    function renderNextPreview(state, questions) {
        var nextQ = null;
        if (state.current_question) {
            var currentId = state.current_question.id;
            for (var i = 0; i < questions.length; i++) {
                if (questions[i].id === currentId && i < questions.length - 1) {
                    nextQ = questions[i + 1];
                    break;
                }
            }
        } else if (questions.length > 0) {
            nextQ = questions[0];
        }

        var img = document.getElementById('op-next-image');
        var none = document.getElementById('op-next-none');
        if (nextQ) {
            img.src = '../' + nextQ.image_path;
            img.style.display = '';
            none.style.display = 'none';
        } else {
            img.style.display = 'none';
            none.style.display = '';
        }
    }

    function renderScores(q, isActive) {
        var container = document.getElementById('op-score-list');
        var noneMsg = document.getElementById('op-score-none');

        document.getElementById('op-score-q-number').textContent = q ? ('Q' + q.question_number) : '—';
        document.getElementById('op-score-q-points').textContent = q ? q.points : '—';

        if (!q) {
            container.innerHTML = '';
            noneMsg.style.display = '';
            return;
        }
        noneMsg.style.display = 'none';

        var scoresByContestant = {};
        (dashboard.current_question_scores || []).forEach(function (s) {
            scoresByContestant[s.contestant_id] = s;
        });
        var totalsByContestant = {};
        (dashboard.rankings || []).forEach(function (r) {
            totalsByContestant[r.contestant_id] = r.total_score;
        });

        var rows = (dashboard.rankings || []).slice().sort(function (a, b) {
            return a.contestant_id - b.contestant_id; // stable order for scoring (not rank order, which reshuffles)
        });

        container.innerHTML = rows.map(function (r) {
            var existing = scoresByContestant[r.contestant_id];
            var total = totalsByContestant[r.contestant_id] !== undefined ? totalsByContestant[r.contestant_id] : 0;

            function btn(result, label, cls) {
                var selected = existing && existing.result === result;
                return '<button type="button" class="op-score-btn ' + cls + (selected ? ' selected' : '') + '" ' +
                    'data-contestant="' + r.contestant_id + '" data-result="' + result + '" ' +
                    (isActive ? '' : 'disabled') + '>' + label + '</button>';
            }

            return '<div class="op-score-row" data-row-contestant="' + r.contestant_id + '">' +
                '<div class="op-score-name"><span class="name">' + escapeHtml(r.name) + '</span>' +
                '<span class="total">Total: ' + total + ' pts<span class="op-saved-flash" id="flash-' + r.contestant_id + '">Saved</span></span></div>' +
                '<div class="op-score-buttons">' +
                    btn('correct', 'Correct', 'correct') +
                    btn('incorrect', 'Incorrect', 'incorrect') +
                    btn('no_answer', 'No Answer', 'no-answer') +
                '</div></div>';
        }).join('');

        container.querySelectorAll('.op-score-btn').forEach(function (b) {
            b.addEventListener('click', onScoreClick);
        });
    }

    function renderRankings() {
        var container = document.getElementById('op-rank-list');
        container.innerHTML = (dashboard.rankings || []).map(function (r) {
            return '<div class="op-rank-row"><span><span class="op-rank-num">' + r.rank + '</span>' +
                escapeHtml(r.name) + '</span><span>' + r.total_score + ' pts</span></div>';
        }).join('');
    }

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    // ---- Event handlers -------------------------------------------------

    function onScoreClick(e) {
        var btn = e.currentTarget;
        var contestantId = btn.getAttribute('data-contestant');
        var result = btn.getAttribute('data-result');
        var q = dashboard.state.current_question;
        if (!q) return;

        var existing = (dashboard.current_question_scores || []).find(function (s) {
            return String(s.contestant_id) === String(contestantId);
        });

        // Already showing this exact result -- nothing to do, and avoids a
        // pointless network round trip on a redundant click.
        if (existing && existing.result === result) return;

        // Changing an already-recorded result is a correction -- make it
        // deliberate with a confirmation, per spec.
        if (existing) {
            var labels = { correct: 'Correct', incorrect: 'Incorrect', no_answer: 'No Answer' };
            var ok = confirm('Change this score from ' + labels[existing.result] + ' to ' + labels[result] + '?');
            if (!ok) return;
        }

        doAction('score', {
            question_id: q.id,
            contestant_id: contestantId,
            result: result
        }, btn).then(function () {
            var flash = document.getElementById('flash-' + contestantId);
            if (flash) {
                flash.classList.add('show');
                setTimeout(function () { flash.classList.remove('show'); }, 1500);
            }
        });
    }

    function confirmAndAct(action, message, triggerEl) {
        if (message && !confirm(message)) return;
        doAction(action, {}, triggerEl);
    }

    document.getElementById('op-btn-start-event').addEventListener('click', function (e) {
        confirmAndAct('start_event', null, e.currentTarget);
    });
    document.getElementById('op-btn-end-event').addEventListener('click', function (e) {
        confirmAndAct('end_event', 'End the competition? This shows final results and locks normal navigation.', e.currentTarget);
    });
    document.getElementById('op-btn-start-first').addEventListener('click', function (e) {
        confirmAndAct('start_first_question', null, e.currentTarget);
    });
    document.getElementById('op-btn-prev').addEventListener('click', function (e) {
        confirmAndAct('previous_question', null, e.currentTarget);
    });
    document.getElementById('op-btn-next').addEventListener('click', function (e) {
        confirmAndAct('next_question', null, e.currentTarget);
    });
    document.getElementById('op-goto-select').addEventListener('change', function (e) {
        var id = e.currentTarget.value;
        e.currentTarget.value = '';
        if (id) doAction('go_to_question', { question_id: id }, null);
    });

    document.getElementById('op-btn-timer-start').addEventListener('click', function (e) {
        doAction('start_timer', {}, e.currentTarget);
    });
    document.getElementById('op-btn-timer-pause').addEventListener('click', function (e) {
        doAction('pause_timer', {}, e.currentTarget);
    });
    document.getElementById('op-btn-timer-resume').addEventListener('click', function (e) {
        doAction('resume_timer', {}, e.currentTarget);
    });
    document.getElementById('op-btn-timer-reset').addEventListener('click', function (e) {
        confirmAndAct('reset_timer', 'Reset the timer back to the full time limit for this question?', e.currentTarget);
    });

    document.getElementById('op-btn-show-ranking').addEventListener('click', function (e) {
        doAction('show_ranking', {}, e.currentTarget);
    });
    document.getElementById('op-btn-return-to-question').addEventListener('click', function (e) {
        doAction('return_to_question', {}, e.currentTarget);
    });
    document.getElementById('op-btn-show-final').addEventListener('click', function (e) {
        confirmAndAct('show_final_results', 'Show final results on the public display?', e.currentTarget);
    });
    document.getElementById('op-btn-return-to-cover').addEventListener('click', function (e) {
        confirmAndAct('return_to_cover', 'Return the public display to the cover screen? (This only changes what is shown -- no scores or progress are affected.)', e.currentTarget);
    });

    // Question controls
    document.getElementById('op-btn-hide-question').addEventListener('click', function (e) {
        doAction('hide_question', {}, e.currentTarget);
    });
    document.getElementById('op-btn-show-question').addEventListener('click', function (e) {
        doAction('show_question', {}, e.currentTarget);
    });
    document.getElementById('op-btn-preview-next').addEventListener('click', function (e) {
        doAction('preview_next', {}, e.currentTarget).then(function() {
            // Preview is handled in renderNextPreview via state update
        });
    });

    // Round controls
    document.getElementById('op-btn-start-round').addEventListener('click', function (e) {
        doAction('start_round', {}, e.currentTarget);
    });
    document.getElementById('op-btn-end-round').addEventListener('click', function (e) {
        doAction('end_round', {}, e.currentTarget);
    });

    // Promotional slide
    document.getElementById('op-promo-select').addEventListener('change', function (e) {
        var id = e.currentTarget.value;
        e.currentTarget.value = '';
        if (id) doAction('show_promotional', { slide_id: id }, null);
    });

    // Answer slide
    document.getElementById('op-answer-select').addEventListener('change', function (e) {
        var id = e.currentTarget.value;
        e.currentTarget.value = '';
        if (id) doAction('show_answer', { slide_id: id }, null);
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
