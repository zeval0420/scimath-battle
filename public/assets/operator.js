(function () {

    'use strict';



    var root = document.getElementById('op-root');

    if (!root) return;



    var eventId = root.getAttribute('data-event-id');

    var csrfToken = root.getAttribute('data-csrf');

    var dashboard = JSON.parse(root.getAttribute('data-initial'));

    var allQuestions = JSON.parse(root.getAttribute('data-questions') || '[]');
    var answerSlides = JSON.parse(root.getAttribute('data-answer-slides') || '[]');

    var lastScoredQuestionId = parseInt(root.getAttribute('data-last-scored-question-id'), 10) || null;

    // Alternating Q→A toggle: persisted per event in localStorage
    var ALT_QA_STORAGE_KEY = 'op-alt-qa:' + eventId;
    var altQaEnabled = true;
    try {
        var _altRaw = window.localStorage.getItem(ALT_QA_STORAGE_KEY);
        if (_altRaw !== null) altQaEnabled = JSON.parse(_altRaw);
    } catch (e) { /* ignore */ }



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



            if (res.last_scored_question_id !== undefined) {

                lastScoredQuestionId = res.last_scored_question_id;

            }



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

            (state.current_round ? ' · ' + roundLabel(state.current_round) + ' round' : '') +

            (progress.position ? ' — Question ' + progress.position + ' of ' + progress.total : ' — ' + progress.total + ' question(s) configured');



        // Public display state banner

        var stateBanner = document.getElementById('op-display-state');

        var stateLabels = { cover: 'Cover', question: 'Question', time_up: "Time's Up", ranking: 'Final Rankings', round_ranking: 'Round Rankings', final_results: 'Final Results', promotional: 'Promotional', answer: 'Answer', hidden: 'Hidden' };

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

        var isShowingAnswer = state.display_state === 'answer';
        var currentAnswer = q ? findAnswerSlideByQuestionId(q.id) : null;
        document.getElementById('op-btn-start-first').disabled = !state.is_active || q !== null;
        document.getElementById('op-btn-prev').disabled = !state.is_active || q === null || (progress.is_first && !(altQaEnabled && isShowingAnswer));
        document.getElementById('op-btn-next').disabled = !state.is_active || q === null || (progress.is_last && !(altQaEnabled && currentAnswer) && !isShowingAnswer);
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

        document.getElementById('op-btn-show-round-ranking').disabled = !state.is_active;

        document.getElementById('op-btn-return-to-question').disabled = !state.is_active || q === null;

        document.getElementById('op-btn-return-to-cover').disabled = !state.is_active;

        var rankRoundSelect = document.getElementById('op-rank-round-select');

        if (rankRoundSelect) {

            rankRoundSelect.disabled = !state.is_active;

            if (state.ranking_round) rankRoundSelect.value = state.ranking_round;

        }

        document.getElementById('op-auto-rank-hint').style.display = state.auto_show_ranking_after_score ? '' : 'none';



        // Round controls

        document.getElementById('op-btn-start-round').disabled = !state.is_active;

        document.getElementById('op-btn-end-round').disabled = !state.is_active;

        var roundSelect = document.getElementById('op-round-select');

        if (roundSelect) {

            roundSelect.disabled = !state.is_active;

            if (state.current_round) roundSelect.value = state.current_round;

        }



        // Slide preview strip + hide/show override toggle

        renderSlidePreview(state);

        renderQuestionToggle(state, q);

        renderAltQaToggle();


        // Update results panel last-scored display

        var lastQEl = document.getElementById('op-results-last-q');

        if (lastQEl) {

            if (lastScoredQuestionId) {

                var lastQ = (state.current_question && String(state.current_question.id) === String(lastScoredQuestionId))

                    ? state.current_question

                    : findQuestionById(allQuestions, lastScoredQuestionId);

                lastQEl.textContent = lastQ

                    ? 'Q' + lastQ.question_number + (lastQ.round ? ' (' + roundLabel(lastQ.round) + ')' : '')

                    : '—';

            } else {

                lastQEl.textContent = '—';

            }

        }



        // Update action button disabled states

        var btnShowQ = document.getElementById('op-btn-show-question');

        var btnShowA = document.getElementById('op-btn-show-answer');

        var btnShowR = document.getElementById('op-btn-show-results');

        if (btnShowQ) btnShowQ.disabled = !state.is_active || q === null;

        if (btnShowA) btnShowA.disabled = !state.is_active || q === null;

        if (btnShowR) btnShowR.disabled = !state.is_active;

    }



    function renderSlidePreview(state) {

        var current = state.current_question;
        var isShowingAnswer = state.display_state === 'answer';

        if (isShowingAnswer && current) {
            // Current thumb: show the answer slide image
            var currentAnswer = findAnswerSlideByQuestionId(current.id);
            setAnswerThumb(
                'op-current-thumb', 'op-current-thumb-empty', 'op-current-thumb-caption',
                currentAnswer, 'Q' + current.question_number + ' — Answer'
            );
            // Next thumb: show the next question
            var nextQ = findNextQuestion(state);
            setSlideThumb(
                'op-next-thumb', 'op-next-thumb-empty', 'op-next-thumb-caption',
                nextQ, nextQ ? questionCaption(nextQ) : 'No next question'
            );
        } else {
            // Current thumb: show the current question
            setSlideThumb(
                'op-current-thumb', 'op-current-thumb-empty', 'op-current-thumb-caption',
                current, current ? questionCaption(current) : 'No question'
            );
            // Next thumb: if altQaEnabled and there's an answer slide, preview it; otherwise next question
            var nextQ = findNextQuestion(state);
            if (altQaEnabled && current) {
                var answer = findAnswerSlideByQuestionId(current.id);
                if (answer) {
                    setAnswerThumb(
                        'op-next-thumb', 'op-next-thumb-empty', 'op-next-thumb-caption',
                        answer, 'Q' + current.question_number + ' — Answer'
                    );
                } else {
                    setSlideThumb(
                        'op-next-thumb', 'op-next-thumb-empty', 'op-next-thumb-caption',
                        nextQ, nextQ ? questionCaption(nextQ) : 'No next question'
                    );
                }
            } else {
                setSlideThumb(
                    'op-next-thumb', 'op-next-thumb-empty', 'op-next-thumb-caption',
                    nextQ, nextQ ? questionCaption(nextQ) : 'No next question'
                );
            }
        }

    }



    function findNextQuestion(state) {

        var current = state.current_question;

        if (!current) {

            return allQuestions[0] || null;

        }

        for (var i = 0; i < allQuestions.length; i++) {

            if (String(allQuestions[i].id) === String(current.id) && i < allQuestions.length - 1) {

                return allQuestions[i + 1];

            }

        }

        return null;

    }



    function questionCaption(q) {

        return 'Q' + q.question_number + (q.round ? ' · ' + roundLabel(q.round) : '');

    }



    function findAnswerSlideByQuestionId(questionId) {
        for (var i = 0; i < answerSlides.length; i++) {
            if (String(answerSlides[i].question_id) === String(questionId)) {
                return answerSlides[i];
            }
        }
        return null;
    }

    function findQuestionById(questions, id) {

        for (var i = 0; i < questions.length; i++) {

            if (String(questions[i].id) === String(id)) return questions[i];

        }

        return null;

    }



    function setSlideThumb(imgId, emptyId, captionId, q, captionText) {

        var img = document.getElementById(imgId);

        var empty = document.getElementById(emptyId);

        var caption = document.getElementById(captionId);

        if (caption) caption.textContent = captionText;

        if (!img || !empty) return;



        if (q && q.image_path) {

            img.src = '../' + q.image_path;

            img.style.display = '';

            empty.style.display = 'none';

        } else {

            img.removeAttribute('src');

            img.style.display = 'none';

            empty.style.display = '';

        }

    }

    // Same as setSlideThumb but accepts an answer slide object (also has image_path).
    function setAnswerThumb(imgId, emptyId, captionId, slide, captionText) {
        setSlideThumb(imgId, emptyId, captionId, slide, captionText);
    }



    function renderQuestionToggle(state, q) {

        var toggle = document.getElementById('op-toggle-question');

        if (!toggle) return;



        var shown = state.display_state === 'question' || state.display_state === 'time_up';

        toggle.checked = shown;

        toggle.disabled = !state.is_active || q === null;



        var stateEl = document.getElementById('op-toggle-question-state');

        if (stateEl) stateEl.textContent = shown ? 'Shown' : 'Hidden';

    }



    function renderAltQaToggle() {
        var toggle = document.getElementById('op-toggle-alt-qa');
        var stateEl = document.getElementById('op-toggle-alt-qa-state');
        if (toggle) toggle.checked = altQaEnabled;
        if (stateEl) stateEl.textContent = altQaEnabled ? 'On' : 'Off';
    }



    var ROUND_LABELS = { easy: 'Easy', medium: 'Medium', hard: 'Hard', clincher: 'Clincher' };

    function roundLabel(r) {

        if (!r) return '';

        return ROUND_LABELS[r] || (r.charAt(0).toUpperCase() + r.slice(1));

    }



    // ---- Event handlers -------------------------------------------------



    document.getElementById('op-btn-start-event').addEventListener('click', function (e) {

        doAction('start_event', {}, e.currentTarget);

    });

    document.getElementById('op-btn-end-event').addEventListener('click', function (e) {

        if (confirm('End the competition? This shows final results and locks normal navigation.')) {

            doAction('end_event', {}, e.currentTarget);

        }

    });

    document.getElementById('op-btn-start-first').addEventListener('click', function (e) {

        doAction('start_first_question', {}, e.currentTarget);

    });

    document.getElementById('op-btn-prev').addEventListener('click', function (e) {
        var state = dashboard.state;
        if (altQaEnabled && state.display_state === 'answer') {
            doAction('show_question', {}, e.currentTarget);
            return;
        }
        doAction('previous_question', {}, e.currentTarget);
    });

    document.getElementById('op-btn-next').addEventListener('click', function (e) {
        var state = dashboard.state;
        var q = state.current_question;
        if (!q) return;

        if (altQaEnabled && state.display_state === 'answer') {
            doAction('next_question', {}, e.currentTarget);
            return;
        }

        var answer = altQaEnabled ? findAnswerSlideByQuestionId(q.id) : null;
        if (answer) {
            doAction('show_answer', { slide_id: answer.id }, e.currentTarget);
        } else {
            doAction('next_question', {}, e.currentTarget);
        }
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

        doAction('reset_timer', {}, e.currentTarget);

    });



    document.getElementById('op-btn-show-ranking').addEventListener('click', function (e) {

        doAction('show_ranking', {}, e.currentTarget);

    });

    document.getElementById('op-btn-show-round-ranking').addEventListener('click', function (e) {

        var round = document.getElementById('op-rank-round-select').value;

        doAction('show_round_ranking', { round: round }, e.currentTarget);

    });

    document.getElementById('op-btn-return-to-question').addEventListener('click', function (e) {

        doAction('return_to_question', {}, e.currentTarget);

    });

    document.getElementById('op-btn-return-to-cover').addEventListener('click', function (e) {

        doAction('return_to_cover', {}, e.currentTarget);

    });



    // Question show/hide override toggle

    document.getElementById('op-toggle-question').addEventListener('change', function (e) {

        var show = e.currentTarget.checked;

        doAction(show ? 'show_question' : 'hide_question', {}, e.currentTarget);

    });



    // Auto-show answer on Next → toggle
    document.getElementById('op-toggle-alt-qa').addEventListener('change', function (e) {
        altQaEnabled = e.currentTarget.checked;
        try { window.localStorage.setItem(ALT_QA_STORAGE_KEY, JSON.stringify(altQaEnabled)); } catch (_) {}
        renderAltQaToggle();
    });



    // Round controls

    document.getElementById('op-btn-start-round').addEventListener('click', function (e) {

        var round = document.getElementById('op-round-select').value;

        doAction('start_round', { round: round }, e.currentTarget);

    });

    document.getElementById('op-btn-end-round').addEventListener('click', function (e) {

        // End the round by showing that round's results + subtotal only (not

        // the cumulative all-rounds ranking), so the operator sees just what

        // was earned in the round they're closing.

        var round = document.getElementById('op-round-select').value || dashboard.state.current_round || 'easy';

        var resultsRoundSelect = document.getElementById('op-results-round-select');

        if (resultsRoundSelect) resultsRoundSelect.value = round;

        doAction('show_round_ranking', { round: round }, e.currentTarget).then(function () {

            var panel = document.getElementById('op-results-panel');

            if (panel) { panel.style.display = ''; renderResultsPanel(); }

        });

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



    // ---- Question action buttons -----------------------------------------



    document.getElementById('op-btn-show-question').addEventListener('click', function (e) {

        doAction('show_question', {}, e.currentTarget);

    });



    
document.getElementById('op-btn-show-answer').addEventListener('click', function (e) {
    var q = dashboard.state && dashboard.state.current_question;
    if (!q) return;

    var answer = findAnswerSlideByQuestionId(q.id);
    if (!answer) {
        alert('No answer slide configured for this question.');
        return;
    }

    doAction('show_answer', { slide_id: answer.id }, e.currentTarget).then(function () {
        var panel = document.getElementById('op-results-panel');
        if (panel) {
            panel.style.display = '';
            renderResultsPanel();
        }
    });
});



    document.getElementById('op-btn-show-results').addEventListener('click', function (e) {

        var round = document.getElementById('op-round-select').value || dashboard.state.current_round || 'easy';

        var resultsRoundSelect = document.getElementById('op-results-round-select');

        if (resultsRoundSelect) resultsRoundSelect.value = round;

        doAction('show_round_ranking', { round: round }, e.currentTarget).then(function () {

            var panel = document.getElementById('op-results-panel');

            if (panel) { panel.style.display = ''; renderResultsPanel(); }

        });

    });



    document.getElementById('op-btn-close-results').addEventListener('click', function () {

        var panel = document.getElementById('op-results-panel');

        if (panel) panel.style.display = 'none';

    });



    document.getElementById('op-btn-refresh-results').addEventListener('click', function () {

        renderResultsPanel();

    });



    document.getElementById('op-results-round-select').addEventListener('change', function () {

        renderResultsPanel();

    });



    // ---- Component visibility menu ---------------------------------------



    var COMPONENT_STORAGE_KEY = 'op-components:' + eventId;



    function loadHiddenComponents() {

        try {

            var raw = window.localStorage.getItem(COMPONENT_STORAGE_KEY);

            return raw ? JSON.parse(raw) : [];

        } catch (err) {

            return [];

        }

    }



    function saveHiddenComponents(hidden) {

        try {

            window.localStorage.setItem(COMPONENT_STORAGE_KEY, JSON.stringify(hidden));

        } catch (err) { /* storage unavailable -- visibility just won't persist */ }

    }



    function setupComponentsMenu() {

        var button = document.getElementById('op-btn-components');

        var menu = document.getElementById('op-components-menu');

        var list = document.getElementById('op-components-list');

        if (!button || !menu || !list) return;



        var panels = Array.prototype.slice.call(

            document.querySelectorAll('.op-panel[data-op-component]')

        );

        var hidden = loadHiddenComponents();



        list.innerHTML = '';

        panels.forEach(function (panel) {

            var id = panel.getAttribute('data-op-component');

            var titleEl = panel.querySelector('h2');

            var label = titleEl ? titleEl.textContent.trim() : id;



            var isHidden = hidden.indexOf(id) !== -1;

            panel.style.display = isHidden ? 'none' : '';



            var row = document.createElement('label');

            row.className = 'op-components-item';



            var checkbox = document.createElement('input');

            checkbox.type = 'checkbox';

            checkbox.checked = !isHidden;



            checkbox.addEventListener('change', function () {

                var off = !checkbox.checked;

                panel.style.display = off ? 'none' : '';



                var idx = hidden.indexOf(id);

                if (off && idx === -1) hidden.push(id);

                if (!off && idx !== -1) hidden.splice(idx, 1);

                saveHiddenComponents(hidden);

            });



            var text = document.createElement('span');

            text.textContent = label;



            row.appendChild(checkbox);

            row.appendChild(text);

            list.appendChild(row);

        });



        function closeMenu() {

            menu.hidden = true;

            button.setAttribute('aria-expanded', 'false');

        }

        function openMenu() {

            menu.hidden = false;

            button.setAttribute('aria-expanded', 'true');

        }



        button.addEventListener('click', function (e) {

            e.stopPropagation();

            if (menu.hidden) { openMenu(); } else { closeMenu(); }

        });

        menu.addEventListener('click', function (e) { e.stopPropagation(); });

        document.addEventListener('click', function () {

            if (!menu.hidden) closeMenu();

        });

        document.addEventListener('keydown', function (e) {

            if (e.key === 'Escape' && !menu.hidden) closeMenu();

        });

    }



    // ---- Results Panel ----------------------------------------------------



    function renderResultsPanel() {

        var wrap = document.getElementById('op-results-matrix-wrap');

        if (!wrap) return;



        var round = document.getElementById('op-results-round-select')

            ? document.getElementById('op-results-round-select').value

            : (dashboard.state.current_round || 'easy');

        var roundLabelEl = document.getElementById('op-results-round-label');

        if (roundLabelEl) roundLabelEl.textContent = roundLabel(round) + ' Round';



        apiGet('report').then(function (res) {

            if (!res.ok || !res.report) return;

            var report = res.report;

            var questions = report.questions || [];

            var contestants = report.contestants || [];

            var cells = report.cells || {};



            var roundQuestions = questions.filter(function (q) { return (q.round || 'easy') === round; });

            if (roundQuestions.length === 0) {

                wrap.innerHTML = '<p class="muted">No ' + roundLabel(round) + ' questions configured.</p>';

                return;

            }



            var headTop = '<th class="name-col" rowspan="2">Team</th>';

            headTop += '<th class="round-group" colspan="' + roundQuestions.length + '">' + roundLabel(round) + '</th>';

            headTop += '<th class="total-col" rowspan="2">Round Total</th>';



            var headBottom = '';

            roundQuestions.forEach(function (q) {

                var isHighlight = lastScoredQuestionId && String(q.id) === String(lastScoredQuestionId);

                headBottom += '<th class="' + (isHighlight ? 'highlight-col' : '') + '">Q' + q.question_number + '</th>';

            });



            var body = contestants.map(function (c) {

                var cellsHtml = '<td class="name-col">' + escapeHtml(c.name) + '</td>';

                var roundTotal = 0;

                roundQuestions.forEach(function (q) {

                    var entry = cells[c.id] && cells[c.id][q.id];

                    var isHighlight = lastScoredQuestionId && String(q.id) === String(lastScoredQuestionId);

                    var resultClass = '';

                    if (isHighlight && entry) {

                        if (entry.result === 'correct') resultClass = 'highlight-correct';

                        else if (entry.result === 'incorrect' || entry.result === 'no_answer') resultClass = 'highlight-' + entry.result;

                    }

                    var val = entry ? entry.points_awarded : '—';

                    if (entry) roundTotal += parseInt(entry.points_awarded, 10) || 0;

                    cellsHtml += '<td class="' + (isHighlight ? 'highlight-col ' : '') + resultClass + '">' + val + '</td>';

                });

                cellsHtml += '<td class="total-col">' + roundTotal + '</td>';

                return '<tr>' + cellsHtml + '</tr>';

            }).join('');



            wrap.innerHTML = '<table class="op-result-matrix">'

                + '<thead>\<tr>' + headTop + '</tr>\<tr>' + headBottom + '</tr></thead>'

                + '<tbody>' + body + '</tbody>'

                + '</table>';

        }).catch(function () {});

    }



    function escapeHtml(s) {

        if (s === null || s === undefined) return '';

        var d = document.createElement('div');

        d.textContent = String(s);

        return d.innerHTML;

    }



    // ---- Polling ----------------------------------------------------------



    function startPolling() {

        if (pollTimer) clearInterval(pollTimer);

        pollTimer = setInterval(function () {

            if (!actionInFlight) refresh();

        }, POLL_MS);

    }



    setupComponentsMenu();

    render();

    startPolling();

})();
