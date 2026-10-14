(function () {
    'use strict';

    var createForm = document.getElementById('event-create-form');
    var createError = document.getElementById('create-error');
    var uploadBtn = document.getElementById('slide-save-all'); // "Save All Slides" in classify panel
    var submitBtn = document.getElementById('btn-create-event');
    var stepUpload = document.getElementById('slide-classify-panel'); // classification panel
    var slideEventId = document.getElementById('slide-event-id'); // hidden input for event ID

    function showError(msg) {
        var createError = document.getElementById('create-error');
        if (createError) {
            createError.textContent = msg;
            createError.style.display = 'block';
        }
    }

    function hideError() {
        var createError = document.getElementById('create-error');
        if (createError) createError.style.display = 'none';
    }

    // Initialize slide upload when page loads
    document.addEventListener('DOMContentLoaded', function() {
        initSlideUpload();
    });

    // "Save All Slides" button in classification panel
    var slideSaveBtn = document.getElementById('slide-save-all');
    if (slideSaveBtn) {
        slideSaveBtn.addEventListener('click', function() {
            var classified = collectClassifications();
            if (classified.length === 0) {
                showError('Please classify at least one slide.');
                return;
            }
            
            // Copy to hidden inputs for final submit
            document.getElementById('slides-classification-final').value = JSON.stringify(classified);
            document.getElementById('save-banner-final').value = '1';
            
            // Show success message
            var statusEl = document.getElementById('slide-save-status');
            if (statusEl) {
                statusEl.textContent = 'Slides classified! Ready to create event.';
                statusEl.style.color = 'var(--success)';
            }
            
            // Scroll to submit button
            var btn = document.getElementById('btn-create-event');
            if (btn) btn.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    }

    // Clear button
    var clearBtn = document.getElementById('slide-clear-all');
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            var classifyList = document.getElementById('slide-classify-list');
            var previewGrid = document.getElementById('slide-preview-grid');
            var classifyPanel = document.getElementById('slide-classify-panel');
            var previewGridEl = document.getElementById('slide-preview-grid');
            var classifyListEl = document.getElementById('slide-classify-list');
            var statusEl = document.getElementById('slide-save-status');
            
            // Clear inputs
            document.getElementById('slides-classification-final').value = '[]';
            document.getElementById('save-banner-final').value = '0';
            
            // Reset UI
            if (previewGridEl) previewGridEl.innerHTML = '';
            if (classifyList) classifyList.innerHTML = '';
            if (previewGridEl) previewGridEl.style.display = 'none';
            if (statusEl) { statusEl.textContent = ''; statusEl.style.color = ''; }
            
            // Reset file inputs
            var multiInput = document.getElementById('slide-multi-input');
            var zipInput = document.getElementById('slide-zip-input');
            if (multiInput) multiInput.value = '';
            if (zipInput) zipInput.value = '';
        });
    }

    // Final form submit
    var createForm = document.getElementById('event-create-form');
    var createError = document.getElementById('create-error');
    var submitBtn = document.getElementById('btn-create-event');

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
            var btn = submitBtn;
            if (btn) btn.disabled = true;

            fetch('actions/event_create_with_slides.php', {
                method: 'POST',
                body: formData
            })
            .then(function(r) {
                console.log('[index.js] Response status:', r.status);
                if (!r.ok) return r.text().then(function(t) { throw new Error('HTTP ' + r.status + ': ' + t.substring(0, 200)); });
                return r.json();
            })
            .then(function(data) {
                console.log('[index.js] Response data:', data);
                if (data.error) {
                    showError(data.error);
                    if (submitBtn) submitBtn.disabled = false;
                    if (submitBtn) submitBtn.textContent = 'Create Event & Save Slides';
                    return;
                }
                // Success - redirect to event page
                window.location.href = data.redirect_to || '/admin/event.php?id=' + data.event_id;
            })
            .catch(function(err) {
                console.error('[index.js] Error:', err);
                showError('Failed to create event: ' + err.message);
                if (submitBtn) submitBtn.disabled = false;
                if (submitBtn) submitBtn.textContent = 'Create Event & Save Slides';
            });
        });
    }

    function showError(msg) {
        var createError = document.getElementById('create-error');
        if (createError) {
            createError.textContent = msg;
            createError.style.display = 'block';
        }
    }

    function hideError() {
        var createError = document.getElementById('create-error');
        if (createError) createError.style.display = 'none';
    }

})();