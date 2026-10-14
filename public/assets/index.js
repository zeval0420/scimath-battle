(function () {
    'use strict';

    var createForm = document.getElementById('event-create-form');
    var submitBtn = document.getElementById('btn-create-event');

    function showError(msg) {
        var el = document.getElementById('create-error');
        if (el) {
            el.textContent = msg;
            el.style.display = 'block';
        }
    }

    function hideError() {
        var el = document.getElementById('create-error');
        if (el) el.style.display = 'none';
    }

    function collectClassifications() {
        var list = document.getElementById('slide-classify-list');
        var out = [];
        if (!list) return out;

        list.querySelectorAll('.slide-type-select').forEach(function (sel) {
            var type = sel.value;
            if (!type) return;

            var idx = parseInt(sel.getAttribute('data-index'), 10);
            var item = { index: idx, type: type };

            if (type === 'question') {
                var diff = list.querySelector('.slide-difficulty-select[data-index="' + idx + '"]');
                item.difficulty = diff ? diff.value : 'easy';
            }
            out.push(item);
        });
        return out;
    }

    // Copy the current classification state into the hidden fields that get
    // submitted with the create form.
    function syncHiddenFields() {
        var classified = collectClassifications();
        var hasQuestion = false, hasAnswer = false, hasBanner = false;
        classified.forEach(function (c) {
            if (c.type === 'question') hasQuestion = true;
            if (c.type === 'answer') hasAnswer = true;
            if (c.type === 'banner') hasBanner = true;
        });

        var slidesField = document.getElementById('slides-classification-final');
        if (slidesField) slidesField.value = JSON.stringify(classified);

        var bannerField = document.getElementById('save-banner-final');
        if (bannerField) bannerField.value = hasBanner ? '1' : '0';

        return { classified: classified, hasQuestion: hasQuestion, hasAnswer: hasAnswer };
    }

    // "Save All Slides" button in the classification panel
    var saveAllBtn = document.getElementById('slide-save-all');
    if (saveAllBtn) {
        saveAllBtn.addEventListener('click', function () {
            var state = syncHiddenFields();
            var statusEl = document.getElementById('slide-save-status');

            if (state.classified.length === 0) {
                showError('Please classify at least one slide.');
                return;
            }
            if (state.hasAnswer && !state.hasQuestion) {
                showError('Answer slides require at least one question slide to pair with.');
                return;
            }

            hideError();
            if (statusEl) {
                statusEl.textContent = 'Slides classified (' + state.classified.length +
                    '). Click "Create Event & Save Slides" to finish.';
                statusEl.style.color = 'var(--success)';
            }
            if (submitBtn) submitBtn.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    }

    // Clear button
    var clearBtn = document.getElementById('slide-clear-all');
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            var slidesField = document.getElementById('slides-classification-final');
            if (slidesField) slidesField.value = '[]';
            var bannerField = document.getElementById('save-banner-final');
            if (bannerField) bannerField.value = '0';
            var statusEl = document.getElementById('slide-save-status');
            if (statusEl) { statusEl.textContent = ''; statusEl.style.color = ''; }
        });
    }

    // Final form submit
    if (createForm) {
        createForm.addEventListener('submit', function (e) {
            e.preventDefault();
            hideError();

            var state = syncHiddenFields();
            if (state.hasAnswer && !state.hasQuestion) {
                showError('Answer slides require at least one question slide to pair with.');
                return;
            }

            if (submitBtn) submitBtn.disabled = true;

            var formData = new FormData(createForm);
            fetch('actions/event_create_with_slides.php', {
                method: 'POST',
                body: formData
            })
            .then(function (r) {
                if (!r.ok) return r.text().then(function (t) { throw new Error('HTTP ' + r.status + ': ' + t.substring(0, 200)); });
                return r.json();
            })
            .then(function (data) {
                if (data.error) {
                    showError(data.error);
                    if (submitBtn) submitBtn.disabled = false;
                    return;
                }
                window.location.href = data.redirect_to || ('event.php?id=' + data.event_id);
            })
            .catch(function (err) {
                showError('Failed to create event: ' + err.message);
                if (submitBtn) submitBtn.disabled = false;
            });
        });
    }
})();
