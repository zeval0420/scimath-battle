(function () {
    'use strict';

    var root = document.getElementById('disp-root');
    if (!root) return;

    var eventId = root.getAttribute('data-event-id');
    var current = JSON.parse(root.getAttribute('data-initial'));

    var POLL_MS = 1000;
    var TICK_MS = 200; // local render tick, purely for smoothness between polls

    // Timer anchor: always re-set from the server's own remaining_seconds on
    // every poll. Between polls, the displayed value is derived as
    // `anchorRemaining - secondsElapsedSinceAnchor` -- this can never drift
    // beyond one poll interval, because it's re-synchronized every second
    // from the same authoritative value the operator's timer reads. There
    // is no separate countdown that runs independently of the server.
    var timerAnchor = { remaining: 0, receivedAt: Date.now(), isRunning: false };

    function setTimerAnchor(timer) {
        timerAnchor = {
            remaining: timer.remaining_seconds,
            receivedAt: Date.now(),
            isRunning: timer.is_running,
        };
    }

    function computeDisplayedRemaining() {
        if (!timerAnchor.isRunning) return timerAnchor.remaining;
        var elapsedSec = Math.floor((Date.now() - timerAnchor.receivedAt) / 1000);
        return Math.max(0, timerAnchor.remaining - elapsedSec);
    }

    function fmtTime(seconds) {
        var m = Math.floor(seconds / 60);
        var s = seconds % 60;
        return m + ':' + String(s).padStart(2, '0');
    }

    // ---- Section switching (crossfade) ---------------------------------

    var lastActiveState = null;
    function setActiveSection(stateName) {
        if (stateName === lastActiveState) return;
        document.querySelectorAll('.disp-state').forEach(function (el) {
            el.classList.toggle('is-active', el.getAttribute('data-state-section') === stateName);
        });
        lastActiveState = stateName;
    }

    // ---- Rendering ------------------------------------------------------

    function renderCover(eventInfo) {
        var bg = document.getElementById('disp-cover-bg');
        if (eventInfo.cover_image_path) {
            bg.style.backgroundImage = "url('../" + eventInfo.cover_image_path + "')";
            bg.style.display = '';
        } else {
            bg.style.display = 'none';
        }

        var logo = document.getElementById('disp-cover-logo');
        if (eventInfo.logo_path) {
logo.src = '../' + eventInfo.logo_path;
        logo.style.display = '';
    } else {
        logo.style.display = 'none';
    }

    document.getElementById('disp-cover-name').textContent = eventInfo.name;
        var subtitleEl = document.getElementById('disp-cover-subtitle');
        subtitleEl.textContent = eventInfo.subtitle || '';
        subtitleEl.style.display = eventInfo.subtitle ? '' : 'none';
    }

    function renderQuestion(q, presentation) {
        if (!q) return;

        var catEl = document.getElementById('disp-q-category');
        catEl.textContent = q.category || '';
        catEl.style.visibility = (presentation.show_category && q.category) ? 'visible' : 'hidden';

        var numEl = document.getElementById('disp-q-number');
        numEl.textContent = 'Question ' + q.question_number;
        numEl.style.visibility = presentation.show_question_number ? 'visible' : 'hidden';

        document.getElementById('disp-timer').parentElement.style.visibility = presentation.show_timer ? 'visible' : 'hidden';

        var img = document.getElementById('disp-q-image');
        var newSrc = '../' + q.image_path;
        if (!img.src.endsWith(newSrc)) img.src = newSrc;
    }

    function renderTimer(displayState) {
        var remaining = computeDisplayedRemaining();
        var el = document.getElementById('disp-timer');
        el.textContent = fmtTime(remaining);

        el.classList.remove('timer-warning', 'timer-danger');
        if (remaining <= 10) {
            el.classList.add('timer-danger');
        } else if (timerAnchor && current.timer && current.timer.warning_seconds !== null && remaining <= current.timer.warning_seconds) {
            el.classList.add('timer-warning');
        }

        document.getElementById('disp-timeup-overlay').classList.toggle('is-visible', displayState === 'time_up');
    }

    function renderRankingRows(container, rankings) {
        container.innerHTML = rankings.map(function (r) {
            var rankClass = r.rank <= 3 ? ' rank-' + r.rank : '';
            return '<div class="disp-rank-row' + rankClass + '">' +
                '<span class="disp-rank-number">' + r.rank + '</span>' +
                '<span class="disp-rank-name">' + escapeHtml(r.name) + '</span>' +
                '<span class="disp-rank-score">' + r.total_score + '</span>' +
                '</div>';
        }).join('');
    }

    function renderFinal(eventInfo, rankings) {
        var logo = document.getElementById('disp-final-logo');
        if (eventInfo.logo_path) {
logo.src = '../' + eventInfo.logo_path;
        logo.style.display = '';
    } else {
        logo.style.display = 'none';
    }
    document.getElementById('disp-final-event-name').textContent = eventInfo.name;

        var champions = rankings.filter(function (r) { return r.rank === 1; });
        var championEl = document.getElementById('disp-champion');
        if (champions.length > 0) {
            var names = champions.map(function (c) { return c.name; }).join(' & ');
            var label = champions.length > 1 ? 'Champions' : 'Champion';
            championEl.innerHTML =
                '<div class="disp-champion-label">' + label + '</div>' +
                '<div class="disp-champion-names">' + escapeHtml(names) + '</div>' +
                '<div class="disp-champion-score">' + champions[0].total_score + ' points</div>';
        } else {
            championEl.innerHTML = '';
        }

        renderRankingRows(document.getElementById('disp-final-ranking-list'), rankings);
    }

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function applyState(data) {
        current = data;
        setTimerAnchor(data.timer);
        setActiveSection(data.display_state);

        // Handle audio config changes
        if (data.audio_config) {
            updateAudioConfig(data.audio_config);
        }

        if (data.display_state === 'cover') {
            renderCover(data.event);
        } else if (data.display_state === 'question' || data.display_state === 'time_up') {
            renderQuestion(data.current_question, data.presentation);
        } else if (data.display_state === 'ranking') {
            renderRankingRows(document.getElementById('disp-ranking-list'), data.rankings);
        } else if (data.display_state === 'final_results') {
            renderFinal(data.event, data.rankings);
        } else if (data.display_state === 'promotional') {
            renderPromotional(data.promotional_slide);
        } else if (data.display_state === 'answer') {
            renderAnswer(data.answer_slide);
        } else if (data.display_state === 'hidden') {
            renderHiddenOverlay();
        }

        renderTimer(data.display_state);
    }

    // ---- Audio Management ----------------------------------------------

    var audioConfig = {};
    var bannerAudio = null;
    var gameAudio = null;
    var timerAudio = null;

    function updateAudioConfig(config) {
        audioConfig = config;

        // Banner audio (100% volume by default)
        if (config.banner_audio_path && (!bannerAudio || bannerAudio.src !== '../' + config.banner_audio_path)) {
            bannerAudio = new Audio('../' + config.banner_audio_path);
            bannerAudio.volume = (config.banner_audio_volume || 100) / 100;
            bannerAudio.loop = true;
        }

        // Game audio (30% volume by default)
        if (config.game_audio_path && (!gameAudio || gameAudio.src !== '../' + config.game_audio_path)) {
            gameAudio = new Audio('../' + config.game_audio_path);
            gameAudio.volume = (config.game_audio_volume || 30) / 100;
            gameAudio.loop = true;
        }

        // Timer audio
        if (config.timer_audio_path && (!timerAudio || timerAudio.src !== '../' + config.timer_audio_path)) {
            timerAudio = new Audio('../' + config.timer_audio_path);
            timerAudio.preload = 'auto';
        }
    }

    function playTimerWarning(seconds) {
        if (seconds <= 5 && seconds > 0 && timerAudio) {
            timerAudio.currentTime = 0;
            timerAudio.play().catch(function() {});
        }
    }

    function startBackgroundAudio(state) {
        if (state === 'cover' && bannerAudio) {
            gameAudio && gameAudio.pause();
            bannerAudio.play().catch(function() {});
        } else if ((state === 'question' || state === 'ranking' || state === 'final_results') && gameAudio) {
            bannerAudio && bannerAudio.pause();
            gameAudio.play().catch(function() {});
        } else {
            bannerAudio && bannerAudio.pause();
            gameAudio && gameAudio.pause();
        }
    }

    // ---- New Render Functions ------------------------------------------

    function renderPromotional(slide) {
        if (!slide) return;
        var img = document.getElementById('disp-promo-image');
        var desc = document.getElementById('disp-promo-desc');
        if (slide.file_path) {
            img.src = '../' + slide.file_path;
            img.style.display = 'block';
        } else {
            img.style.display = 'none';
        }
        if (slide.description) {
            desc.textContent = slide.description;
            desc.style.display = 'block';
        } else {
            desc.style.display = 'none';
        }
    }

    function renderAnswer(slide) {
        if (!slide || !slide.image_path) return;
        var img = document.getElementById('disp-answer-image');
        img.src = '../' + slide.image_path;
    }

    function renderHiddenOverlay() {
        var overlay = document.getElementById('disp-hidden-overlay');
        overlay.style.display = 'flex';
    }

    function hideHiddenOverlay() {
        var overlay = document.getElementById('disp-hidden-overlay');
        overlay.style.display = 'none';
    }

    // ---- Polling ----------------------------------------------------------
    // Silent on failure -- the audience should never see a connection error;
    // it just keeps showing the last good state and keeps retrying.

    function poll() {
        fetch('../api/display.php?event_id=' + eventId, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.ok) applyState(res);
            })
            .catch(function () { /* keep last known state; retry on next tick */ });
    }

    setInterval(poll, POLL_MS);
    setInterval(function () {
        renderTimer(current.display_state);
        // Play timer warning audio
        if (current.timer && current.timer.remaining_seconds !== null) {
            playTimerWarning(current.timer.remaining_seconds);
        }
        // Manage background audio based on state
        if (current.display_state) {
            startBackgroundAudio(current.display_state);
        }
        // Show/hide hidden overlay
        if (current.display_state === 'hidden') {
            renderHiddenOverlay();
        } else {
            hideHiddenOverlay();
        }
    }, TICK_MS);

    // ---- Fullscreen ---------------------------------------------------

    function toggleFullscreen() {
        if (document.fullscreenElement) {
            document.exitFullscreen();
        } else {
            document.documentElement.requestFullscreen().catch(function () {});
        }
    }
    document.getElementById('disp-fullscreen-btn').addEventListener('click', toggleFullscreen);
    document.addEventListener('dblclick', toggleFullscreen);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'f' || e.key === 'F') toggleFullscreen();
    });

    // ---- Initial paint (from server-rendered data, before first poll) ----
    applyState(current);
})();
