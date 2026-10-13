(function () {
    'use strict';

    // ---- Step 1: Create Event via AJAX ----------------------------
    var createForm = document.getElementById('event-create-form');
    var createError = document.getElementById('create-error');
    var stepCreate = document.getElementById('step-create-section');
    var stepUpload = document.getElementById('step-upload-section');
    var newEventName = document.getElementById('new-event-display-name');
    var slideEventId = document.getElementById('slide-event-id');

    function showError(msg) {
        if (createError) {
            createError.textContent = msg;
            createError.style.display = 'block';
        }
    }

    function hideError() {
        if (createError) createError.style.display = 'none';
    }

    if (createForm) {
        createForm.addEventListener('submit', function(e) {
            e.preventDefault();
            hideError();

            var formData = new FormData(createForm);
            var btn = createForm.querySelector('button[type="submit"]');
            if (btn) btn.disabled = true;

            fetch('actions/event_create_ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(function(r) {
                if (!r.ok) return r.text().then(function(t) { throw new Error('HTTP ' + r.status + ': ' + t.substring(0, 200)); });
                return r.json();
            })
            .then(function(data) {
                if (data.error) {
                    showError(data.error);
                    if (btn) btn.disabled = false;
                    return;
                }
                // Success — show upload section
                if (slideEventId) slideEventId.value = data.event_id;
                if (newEventName) newEventName.textContent = data.event_name;
                if (stepCreate) stepCreate.style.display = 'none';
                if (stepUpload) stepUpload.style.display = 'block';
                if (btn) btn.disabled = false;

                // Initialize slide upload with the new event ID
                setTimeout(function() {
                    initSlideUpload(data.event_id);
                }, 100);
            })
            .catch(function(err) {
                showError('Failed to create event: ' + err.message);
                if (btn) btn.disabled = false;
            });
        });
    }

    // ---- Handle save from upload section (redirect to event page) ---
    var slideSaveBtn = document.getElementById('slide-save-all');
    if (slideSaveBtn) {
        slideSaveBtn.addEventListener('click', function() {
            // Override the save handler to redirect instead of reload
            window.__slide_upload_redirect_to = '/admin/event.php?id=' + (slideEventId ? slideEventId.value : '');
        });
    }

    // ---- Re-initialize upload area when shown --------------------
    var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.target.id === 'step-upload-section' && mutation.target.style.display !== 'none') {
                var eid = slideEventId ? slideEventId.value : null;
                if (eid) {
                    initSlideUpload(eid);
                }
            }
        });
    });
    if (stepUpload) {
        observer.observe(stepUpload, { attributes: true, attributeFilter: ['style'] });
    }

})();
