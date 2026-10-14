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
    // Can be called with an eventId parameter for use on index.php
    function initSlideUpload(eventId) {
        console.log('[slide-upload] initSlideUpload called with eventId:', eventId);
        
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
        var progressEl = document.getElementById('slide-upload-progress');
        var progressBar = document.getElementById('slide-progress-bar');
        var progressText = document.getElementById('slide-progress-text');
        var progressPercent = document.getElementById('slide-progress-percent');
        // New file status elements
        var fileStatusEl = document.getElementById('slide-file-status');
        var statusFilenameEl = document.getElementById('slide-status-filename');
        var statusSizeEl = document.getElementById('slide-status-size');
        var statusBadgeEl = document.getElementById('slide-status-badge');
        var clearFileBtn = document.getElementById('slide-clear-file');
        var sessionFieldEl = document.getElementById('slide-session-id');

        console.log('[slide-upload] DOM elements found:', {
            dropZone: !!dropZone,
            zipInput: !!zipInput,
            multiInput: !!multiInput,
            previewGrid: !!previewGrid,
            classifyPanel: !!classifyPanel,
            classifyList: !!classifyList,
            saveBtn: !!saveBtn,
            clearBtn: !!clearBtn,
            statusEl: !!statusEl,
            fileInfoEl: !!fileInfoEl,
            errorEl: !!errorEl,
            progressEl: !!progressEl,
            progressBar: !!progressBar,
            progressText: !!progressText,
            progressPercent: !!progressPercent,
            fileStatusEl: !!fileStatusEl,
            statusFilenameEl: !!statusFilenameEl,
            statusSizeEl: !!statusSizeEl,
            statusBadgeEl: !!statusBadgeEl,
            clearFileBtn: !!clearFileBtn
        });

        if (!dropZone) {
            console.error('[slide-upload] slide-upload-area NOT FOUND in DOM!');
            return;
        }

        // Use provided eventId or get from URL
        if (!eventId) {
            var urlParams = new URLSearchParams(window.location.search);
            eventId = urlParams.get('id');
        }

        function showProgress(text) {
            if (progressEl) progressEl.style.display = 'block';
            if (progressText) progressText.textContent = text || 'Uploading...';
            if (progressPercent) progressPercent.textContent = '0%';
            if (progressBar) progressBar.style.width = '0%';
        }

        function updateProgress(percent, text) {
            if (progressBar) progressBar.style.width = percent + '%';
            if (progressPercent) progressPercent.textContent = percent + '%';
            if (text && progressText) progressText.textContent = text;
        }

        function hideProgress() {
            if (progressEl) progressEl.style.display = 'none';
        }

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
         // Set above from constructor param or URL

        // Show selected file names
        multiInput.addEventListener('change', function() {
            console.log('[slide-upload] multiInput change event fired');
            if (multiInput.files && multiInput.files.length > 0) {
                var names = [];
                var totalSize = 0;
                for (var i = 0; i < multiInput.files.length; i++) {
                    names.push(multiInput.files[i].name);
                    totalSize += multiInput.files[i].size;
                }
                console.log('[slide-upload] Selected files:', names);
                showFileStatus(names.join(', '), totalSize);
                handleFiles(multiInput.files);
            } else {
                console.log('[slide-upload] No files in multiInput');
                hideFileStatus();
            }
        });

        zipInput.addEventListener('change', function() {
            console.log('[slide-upload] zipInput change event fired');
            if (zipInput.files && zipInput.files.length > 0) {
                console.log('[slide-upload] ZIP file selected:', zipInput.files[0].name, 'size:', zipInput.files[0].size);
                showFileStatus(zipInput.files[0].name, zipInput.files[0].size, 'zip');
                handleZip(zipInput.files[0]);
            } else {
                console.log('[slide-upload] No files in zipInput');
                hideFileStatus();
            }
        });

        function showFileStatus(name, size, type) {
            if (!fileStatusEl || !statusFilenameEl || !statusSizeEl || !statusBadgeEl) return;
            statusFilenameEl.textContent = name;
            statusSizeEl.textContent = formatSize(size);
            statusBadgeEl.textContent = type === 'zip' ? 'ZIP Archive' : (type === 'multi' ? 'Multiple Images' : 'Image');
            statusBadgeEl.className = 'badge badge-ready';
            fileStatusEl.style.display = 'block';
        }

        function hideFileStatus() {
            if (fileStatusEl) fileStatusEl.style.display = 'none';
        }

        function formatSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        }

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
                showFileStatus(files[0].name, files[0].size, 'zip');
                handleZip(files[0]);
            } else {
                var totalSize = 0;
                for (var i = 0; i < files.length; i++) totalSize += files[i].size;
                showFileStatus(files.length + ' file(s)', totalSize, 'multi');
                handleFiles(files);
            }
        });

        function log(msg) {
            console.log('[slide-upload]', msg);
            if (statusEl) statusEl.textContent = msg;
        }

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

        function handleZip(file) {
            log('Uploading ZIP: ' + file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)');
            var csrf = getCsrfToken();
            console.log('[slide-upload] CSRF token present:', !!csrf);
            console.log('[slide-upload] Event ID:', eventId);
            
            if (!csrf) {
                showError('CSRF token missing. Please refresh the page.');
                return;
            }
            
            var form = new FormData();
            form.append('upload', file);
            form.append('csrf_token', csrf);
            form.append('event_id', eventId || '');
            
            showProgress('Uploading ' + file.name + '...');
            
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'actions/batch_upload.php', true);
            
            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    var percent = Math.round((e.loaded / e.total) * 100);
                    updateProgress(percent, 'Uploading... ' + percent + '%');
                }
            });
            
            xhr.addEventListener('load', function() {
                console.log('[slide-upload] Response status:', xhr.status);
                if (xhr.status !== 200) {
                    showError('Upload failed with HTTP ' + xhr.status);
                    return;
                }
                try {
                    var data = JSON.parse(xhr.responseText);
                    console.log('[slide-upload] Response data:', JSON.stringify(data).substring(0, 500));
                    if (data.error) { showError(data.error); return; }
                    stagedImages = data.images || [];
                    sessionId = data.sessionId;
                    if (sessionFieldEl) sessionFieldEl.value = sessionId || '';
                    hideProgress();
                    log('Uploaded ' + stagedImages.length + ' image(s). Classify below.');
                    renderPreviews(stagedImages);
                } catch(e) {
                    showError('Invalid response from server');
                }
            });
            
            xhr.addEventListener('error', function() {
                showError('Network error during upload');
            });
            
            xhr.send(form);
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
                showError('No valid image files found. Use PNG, JPG, or WebP.');
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
            
            showProgress('Uploading ' + files.length + ' file(s)...');
            
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'actions/batch_upload.php', true);
            
            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    var percent = Math.round((e.loaded / e.total) * 100);
                    updateProgress(percent, 'Uploading... ' + percent + '%');
                }
            });
            
            xhr.addEventListener('load', function() {
                console.log('[slide-upload] Response status:', xhr.status);
                if (xhr.status !== 200) {
                    showError('Upload failed with HTTP ' + xhr.status);
                    return;
                }
                try {
                    var data = JSON.parse(xhr.responseText);
                    console.log('[slide-upload] Response data:', JSON.stringify(data).substring(0, 500));
                    if (data.error) { showError(data.error); return; }
                    stagedImages = data.images || [];
                    sessionId = data.sessionId;
                    if (sessionFieldEl) sessionFieldEl.value = sessionId || '';
                    hideProgress();
                    log('Uploaded ' + stagedImages.length + ' image(s). Classify below.');
                    renderPreviews(stagedImages);
                } catch(e) {
                    showError('Invalid response from server');
                }
            });
            
            xhr.addEventListener('error', function() {
                showError('Network error during upload');
            });
            
            xhr.send(form);
        }

        function renderPreviews(images) {
            console.log('[slide-upload] renderPreviews called with', images.length, 'images');
            if (!previewGrid) {
                console.error('[slide-upload] previewGrid not found!');
                return;
            }
            if (!classifyList) {
                console.error('[slide-upload] classifyList not found!');
                return;
            }
            previewGrid.innerHTML = '';
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
                    '</select>' +
                    '<select class="slide-difficulty-select" data-index="' + img.index + '" style="width:auto;margin:0;display:none;">' +
                        '<option value="easy">Easy</option>' +
                        '<option value="medium">Medium</option>' +
                        '<option value="hard">Hard</option>' +
                        '<option value="clincher">Clincher</option>' +
                    '</select>';
                classifyList.appendChild(row);
            });
            if (classifyPanel) classifyPanel.style.display = 'block';
            if (previewGrid) previewGrid.style.display = 'grid';
            
            // Toggle difficulty dropdown visibility based on type selection
            classifyList.querySelectorAll('.slide-type-select').forEach(function(sel) {
                sel.addEventListener('change', function() {
                    var idx = this.dataset.index;
                    var diffSel = classifyList.querySelector('.slide-difficulty-select[data-index="' + idx + '"]');
                    if (diffSel) {
                        diffSel.style.display = this.value === 'question' ? 'block' : 'none';
                    }
                });
            });
        }

        // Only wire the batch_save handler on the management page (event_id known).
        // On the creation page, index.js collects classifications and submits the form instead.
        if (saveBtn && eventId) {
            saveBtn.addEventListener('click', function() {
                if (!classifyList) return;
                var typeSelects = classifyList.querySelectorAll('.slide-type-select');
                var diffSelects = classifyList.querySelectorAll('.slide-difficulty-select');
                var classified = [];
                var hasQuestion = false, hasAnswer = false, hasBanner = false;
                typeSelects.forEach(function(sel, idx) {
                    if (!sel.value) return;
                    var item = { index: parseInt(sel.dataset.index), type: sel.value };
                    // Get difficulty if question slide
                    if (sel.value === 'question' && diffSelects[idx]) {
                        item.difficulty = diffSelects[idx].value || 'easy';
                    }
                    classified.push(item);
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
                // Add redirect_to if set (for single-page workflow)
                if (window.__slide_upload_redirect_to) {
                    form.append('redirect_to', window.__slide_upload_redirect_to);
                }
                fetch('actions/batch_save.php', { method: 'POST', body: form })
                    .then(function(r) {
                        if (!r.ok) return r.text().then(function(t) { throw new Error('HTTP ' + r.status + ': ' + t); });
                        return r.json();
                    })
                    .then(function(result) {
                        if (result.error) { showError(result.error); return; }
                        log('Saved ' + result.created_questions + ' question(s), ' +
                            result.created_promo + ' promo slide(s), ' +
                            result.created_answers + ' answer slide(s).' +
                            (result.banner_saved ? ' Banner saved.' : ''));
                        // If redirect_to is provided, navigate there; otherwise reload
                        if (result.redirect_to) {
                            window.location.href = result.redirect_to;
                        } else {
                            setTimeout(function() { location.reload(); }, 1500);
                        }
                    })
                    .catch(function(err) { showError(err.message); });
            });
        }

        // Clear file button handler
        if (clearFileBtn) {
            clearFileBtn.addEventListener('click', function() {
                hideFileStatus();
                if (multiInput) multiInput.value = '';
                if (zipInput) zipInput.value = '';
            });
        }

        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                stagedImages = [];
                sessionId = null;
                if (sessionFieldEl) sessionFieldEl.value = '';
                if (previewGrid) previewGrid.innerHTML = '';
                if (classifyList) classifyList.innerHTML = '';
                if (previewGrid) previewGrid.style.display = 'none';
                if (classifyPanel) classifyPanel.style.display = 'none';
                if (statusEl) { statusEl.textContent = ''; statusEl.style.color = ''; }
                if (fileInfoEl) fileInfoEl.textContent = '';
                hideFileStatus();
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

    // Expose for use from other scripts (e.g. index.js)
    window.initSlideUpload = initSlideUpload;
})();
