(function () {
    'use strict';

    // ---- Tabs -------------------------------------------------------
    function initTabs() {
        var tabLinks = document.querySelectorAll('[data-tab-link]');
        var tabPanels = document.querySelectorAll('[data-tab-panel]');
        if (tabLinks.length === 0) return;

        function activate(tabId) {
            tabLinks.forEach(function (link) {
                link.classList.toggle('active', link.getAttribute('data-tab-link') === tabId);
            });
            tabPanels.forEach(function (panel) {
                panel.classList.toggle('active', panel.getAttribute('data-tab-panel') === tabId);
            });
        }

        tabLinks.forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                var tabId = link.getAttribute('data-tab-link');
                activate(tabId);
                history.replaceState(null, '', '#' + tabId);
            });
        });

        var initial = (window.location.hash || '').replace('#', '');
        var validIds = Array.prototype.map.call(tabLinks, function (l) { return l.getAttribute('data-tab-link'); });
        activate(validIds.indexOf(initial) !== -1 ? initial : validIds[0]);
    }

    // ---- Confirm destructive actions ---------------------------------
    function initConfirm() {
        document.querySelectorAll('[data-confirm]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                var message = el.getAttribute('data-confirm') || 'Are you sure?';
                if (!window.confirm(message)) {
                    e.preventDefault();
                }
            });
        });
    }

    // ---- Image upload preview -----------------------------------------
    function initImagePreviews() {
        document.querySelectorAll('input[type=file][data-preview-target]').forEach(function (input) {
            input.addEventListener('change', function () {
                var targetId = input.getAttribute('data-preview-target');
                var target = document.getElementById(targetId);
                if (!target || !input.files || !input.files[0]) return;

                var file = input.files[0];
                var validTypes = ['image/png', 'image/jpeg', 'image/webp'];
                if (validTypes.indexOf(file.type) === -1) {
                    return; // server will reject; don't bother previewing
                }

                var reader = new FileReader();
                reader.onload = function (e) {
                    target.src = e.target.result;
                    target.style.display = 'block';
                };
                reader.readAsDataURL(file);
            });
        });
    }

    // ---- Batch Slide Upload -----------------------------------------
    function initSlideUpload() {
        var dropZone = document.getElementById('slide-upload-area');
        var zipInput = document.getElementById('slide-zip-input');
        var multiInput = document.getElementById('slide-multi-input');
        var previewGrid = document.getElementById('slide-preview-grid');
        var classifyPanel = document.getElementById('slide-classify-panel');
        var classifyList = document.getElementById('slide-classify-list');
        var saveBtn = document.getElementById('slide-save-all');
        var clearBtn = document.getElementById('slide-clear-all');
        var statusEl = document.getElementById('slide-save-status');
        var fileInfoEl = document.getElementById('slide-file-info');
        var errorEl = document.getElementById('slide-upload-error');

        function showError(msg) {
            console.error('[slide-upload] Error:', msg);
            if (errorEl) {
                errorEl.textContent = msg;
                errorEl.style.display = 'block';
            }
            if (statusEl) {
                statusEl.style.color = 'var(--error)';
                statusEl.textContent = 'Error: ' + msg;
            }
        }

        function clearError() {
            if (errorEl) errorEl.style.display = 'none';
            if (statusEl) {
                statusEl.style.color = '';
                statusEl.textContent = '';
            }
        }

        if (!dropZone) return;

        var stagedImages = [];
        var sessionId = null;
        var eventId = (new URLSearchParams(window.location.search)).get('id');

        // Show selected file names
        multiInput.addEventListener('change', function() {
            if (multiInput.files && multiInput.files.length > 0) {
                var names = [];
                for (var i = 0; i < multiInput.files.length; i++) {
                    names.push(multiInput.files[i].name);
                }
                if (fileInfoEl) fileInfoEl.textContent = 'Selected: ' + names.join(', ');
                handleFiles(multiInput.files);
            }
        });

        zipInput.addEventListener('change', function() {
            if (zipInput.files && zipInput.files.length > 0) {
                if (fileInfoEl) fileInfoEl.textContent = 'Selected: ' + zipInput.files[0].name;
                handleZip(zipInput.files[0]);
            }
        });

        // Drag and drop
        dropZone.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.add('drag-over');
        });
        dropZone.addEventListener('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.remove('drag-over');
        });
        dropZone.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.remove('drag-over');
            var files = e.dataTransfer.files;
            if (!files || files.length === 0) return;

            // If exactly one ZIP, use zip handler; otherwise multi
            if (files.length === 1 && files[0].name.toLowerCase().endsWith('.zip')) {
                handleZip(files[0]);
            } else {
                handleFiles(files);
            }
        });

        function log(msg) {
            console.log('[slide-upload]', msg);
            if (statusEl) statusEl.textContent = msg;
        }

        function handleError(msg) {
            showError(msg);
        }

        function handleZip(file) {
            log('Uploading ZIP: ' + file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)');
            var csrf = getCsrfToken();
            console.log('[slide-upload] CSRF token present:', !!csrf);
            console.log('[slide-upload] Event ID:', eventId);
            
            if (!csrf) {
                handleError('CSRF token missing. Please refresh the page.');
                return;
            }
            
            var form = new FormData();
            form.append('upload', file);
            form.append('csrf_token', csrf);
            form.append('event_id', eventId || '');
            
            console.log('[slide-upload] Sending request...');
            fetch('actions/batch_upload.php', { method: 'POST', body: form })
                .then(function(r) {
                    console.log('[slide-upload] Response status:', r.status, 'OK?', r.ok);
                    if (!r.ok) return r.text().then(function(t) { throw new Error('HTTP ' + r.status + ': ' + t.substring(0, 300)); });
                    return r.json();
                })
                .then(function(data) {
                    console.log('[slide-upload] Response data:', JSON.stringify(data).substring(0, 500));
                    if (data.error) { handleError(data.error); return; }
                    stagedImages = data.images || [];
                    sessionId = data.sessionId;
                    log('Uploaded ' + stagedImages.length + ' image(s). Classify below.');
                    renderPreviews(stagedImages);
                })
                .catch(function(err) { 
                    console.error('[slide-upload] Fetch error:', err);
                    handleError(err.message); 
                });
        }

        function handleFiles(files) {
            var zipFile = null;
            var imageFiles = [];
            for (var i = 0; i < files.length; i++) {
                var name = files[i].name.toLowerCase();
                if (name.endsWith('.zip')) {
                    zipFile = files[i];
                } else if (/\.(png|jpe?g|webp)$/i.test(name)) {
                    imageFiles.push(files[i]);
                }
            }
            console.log('[slide-upload] Files received:', files.length, 'ZIP:', zipFile ? zipFile.name : 'none', 'Images:', imageFiles.length);
            if (zipFile) {
                handleZip(zipFile);
            } else if (imageFiles.length > 0) {
                uploadMulti(imageFiles);
            } else {
                handleError('No valid image files found. Use PNG, JPG, or WebP.');
            }
        }

        function uploadMulti(files) {
            log('Uploading ' + files.length + ' file(s)...');
            var form = new FormData();
            for (var i = 0; i < files.length; i++) {
                form.append('files[]', files[i]);
            }
            form.append('csrf_token', getCsrfToken());
            form.append('event_id', eventId || '');
            fetch('actions/batch_upload.php', { method: 'POST', body: form })
                .then(function(r) {
                    if (!r.ok) return r.text().then(function(t) { throw new Error('HTTP ' + r.status + ': ' + t); });
                    return r.json();
                })
                .then(function(data) {
                    if (data.error) { handleError(data.error); return; }
                    stagedImages = data.images || [];
                    sessionId = data.sessionId;
                    log('Uploaded ' + stagedImages.length + ' image(s). Classify below.');
                    renderPreviews(stagedImages);
                })
                .catch(function(err) { handleError(err.message); });
        }

        function renderPreviews(images) {
            if (!previewGrid) return;
            previewGrid.innerHTML = '';
            if (!classifyList) return;
            classifyList.innerHTML = '';
            images.forEach(function(img) {
                var card = document.createElement('div');
                card.className = 'slide-thumb-card';
                card.innerHTML = '<img src="' + img.preview + '" alt=""><div class="slide-thumb-info"><div class="slide-thumb-name" title="' + esc(img.filename) + '">' + esc(img.filename) + '</div></div>';
                previewGrid.appendChild(card);

                var row = document.createElement('div');
                row.className = 'slide-classify-row';
                row.innerHTML =
                    '<img src="' + img.preview + '" alt=""' + '>' +
                    '<span style="flex:1;font-size:0.82rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(img.filename) + '">' + esc(img.filename) + '</span>' +
                    '<select class="slide-type-select" data-index="' + img.index + '" style="width:auto;margin:0;">' +
                        '<option value="">— classify —</option>' +
                        '<option value="banner">Banner / Cover</option>' +
                        '<option value="promotional">Promotional Slide</option>' +
                        '<option value="question">Question Slide</option>' +
                        '<option value="answer">Answer Slide</option>' +
                    '</select>';
                classifyList.appendChild(row);
            });
            if (classifyPanel) classifyPanel.style.display = 'block';
            if (previewGrid) previewGrid.style.display = 'grid';
        }

        if (saveBtn) {
            saveBtn.addEventListener('click', function() {
                if (!classifyList) return;
                var selects = classifyList.querySelectorAll('.slide-type-select');
                var classified = [];
                var hasQuestion = false, hasAnswer = false, hasBanner = false;
                selects.forEach(function(sel) {
                    if (!sel.value) return;
                    classified.push({ index: parseInt(sel.dataset.index), type: sel.value });
                    if (sel.value === 'question') hasQuestion = true;
                    if (sel.value === 'answer') hasAnswer = true;
                    if (sel.value === 'banner') hasBanner = true;
                });
                if (classified.length === 0) {
                    log('Please classify at least one slide.');
                    return;
                }
                if (hasAnswer && !hasQuestion) {
                    log('Answer slides require at least one question slide to pair with.');
                    return;
                }
                log('Saving...');
                var form = new FormData();
                form.append('event_id', eventId);
                form.append('session_id', sessionId);
                form.append('save_banner', hasBanner ? '1' : '0');
                form.append('slides', JSON.stringify(classified));
                form.append('csrf_token', getCsrfToken());
                fetch('actions/batch_save.php', { method: 'POST', body: form })
                    .then(function(r) {
                        if (!r.ok) return r.text().then(function(t) { throw new Error('HTTP ' + r.status + ': ' + t); });
                        return r.json();
                    })
                    .then(function(result) {
                        if (result.error) { handleError(result.error); return; }
                        log('Saved ' + result.created_questions + ' question(s), ' +
                            result.created_promo + ' promo slide(s), ' +
                            result.created_answers + ' answer slide(s).' +
                            (result.banner_saved ? ' Banner saved.' : ''));
                        setTimeout(function() { location.reload(); }, 1500);
                    })
                    .catch(function(err) { handleError(err.message); });
            });
        }

        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                stagedImages = [];
                sessionId = null;
                if (previewGrid) previewGrid.innerHTML = '';
                if (classifyList) classifyList.innerHTML = '';
                if (previewGrid) previewGrid.style.display = 'none';
                if (classifyPanel) classifyPanel.style.display = 'none';
                if (statusEl) { statusEl.textContent = ''; statusEl.style.color = ''; }
                if (fileInfoEl) fileInfoEl.textContent = '';
                clearError();
                if (zipInput) zipInput.value = '';
                if (multiInput) multiInput.value = '';
            });
        }
    }

    function getCsrfToken() {
        var input = document.querySelector('input[name="csrf_token"]');
        return input ? input.value : '';
    }

    function esc(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    document.addEventListener('DOMContentLoaded', function () {
        initTabs();
        initConfirm();
        initImagePreviews();
        initSlideUpload();
    });
})();
