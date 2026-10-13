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

        if (!dropZone) return;

        var stagedImages = [];
        var sessionId = null;
        var eventId = (new URLSearchParams(window.location.search)).get('id');

        document.getElementById('slide-browse-btn').addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            multiInput.click();
        });

        dropZone.addEventListener('dragover', function(e) {
            e.preventDefault();
            dropZone.classList.add('drag-over');
        });
        dropZone.addEventListener('dragleave', function(e) {
            if (!dropZone.contains(e.relatedTarget)) {
                dropZone.classList.remove('drag-over');
            }
        });
        dropZone.addEventListener('drop', function(e) {
            e.preventDefault();
            dropZone.classList.remove('drag-over');
            var files = e.dataTransfer.files;
            if (files.length === 1 && files[0].name.toLowerCase().endsWith('.zip')) {
                handleZip(files[0]);
            } else if (files.length > 0) {
                handleMulti(files);
            }
        });
        zipInput.addEventListener('change', function() {
            if (zipInput.files.length) {
                handleZip(zipInput.files[0]);
                zipInput.value = '';
            }
        });
        multiInput.addEventListener('change', function() {
            if (multiInput.files.length) {
                handleMulti(multiInput.files);
                multiInput.value = '';
            }
        });

        function handleZip(file) {
            var form = new FormData();
            form.append('upload', file);
            form.append('csrf_token', getCsrfToken());
            form.append('event_id', eventId || '');
            statusEl.textContent = 'Uploading ZIP...';
            fetch('actions/batch_upload.php', { method: 'POST', body: form })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.error) { statusEl.textContent = 'Error: ' + data.error; return; }
                    stagedImages = data.images || [];
                    sessionId = data.sessionId;
                    renderPreviews(stagedImages);
                })
                .catch(function(err) { statusEl.textContent = 'Upload failed: ' + err.message; });
        }

        function handleMulti(files) {
            var form = new FormData();
            for (var i = 0; i < files.length; i++) {
                form.append('files[]', files[i]);
            }
            form.append('csrf_token', getCsrfToken());
            form.append('event_id', eventId || '');
            statusEl.textContent = 'Uploading ' + files.length + ' files...';
            fetch('actions/batch_upload.php', { method: 'POST', body: form })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.error) { statusEl.textContent = 'Error: ' + data.error; return; }
                    stagedImages = data.images || [];
                    sessionId = data.sessionId;
                    renderPreviews(stagedImages);
                })
                .catch(function(err) { statusEl.textContent = 'Upload failed: ' + err.message; });
        }

        function renderPreviews(images) {
            previewGrid.innerHTML = '';
            classifyList.innerHTML = '';
            images.forEach(function(img) {
                var card = document.createElement('div');
                card.className = 'slide-thumb-card';
                card.innerHTML = '<img src="' + img.preview + '" alt=""><div class="slide-thumb-info"><div class="slide-thumb-name" title="' + escapeHtml(img.filename) + '">' + escapeHtml(img.filename) + '</div></div>';
                previewGrid.appendChild(card);

                var row = document.createElement('div');
                row.className = 'slide-classify-row';
                row.innerHTML =
                    '<img src="' + img.preview + '" alt=""' + '>' +
                    '<span style="flex:1;font-size:0.82rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escapeHtml(img.filename) + '">' + escapeHtml(img.filename) + '</span>' +
                    '<select class="slide-type-select" data-index="' + img.index + '" style="width:auto;margin:0;">' +
                        '<option value="">— classify —</option>' +
                        '<option value="banner">Banner / Cover</option>' +
                        '<option value="promotional">Promotional Slide</option>' +
                        '<option value="question">Question Slide</option>' +
                        '<option value="answer">Answer Slide</option>' +
                    '</select>';
                classifyList.appendChild(row);
            });
            classifyPanel.style.display = 'block';
            previewGrid.style.display = 'grid';
            statusEl.textContent = images.length + ' image(s) loaded. Classify each slide below.';
        }

        saveBtn.addEventListener('click', function() {
            var selects = classifyList.querySelectorAll('.slide-type-select');
            var classified = [];
            var hasQuestion = false;
            var hasAnswer = false;
            var hasBanner = false;
            selects.forEach(function(sel) {
                if (!sel.value) return;
                classified.push({ index: parseInt(sel.dataset.index), type: sel.value });
                if (sel.value === 'question') hasQuestion = true;
                if (sel.value === 'answer') hasAnswer = true;
                if (sel.value === 'banner') hasBanner = true;
            });
            if (classified.length === 0) {
                statusEl.textContent = 'Please classify at least one slide.';
                return;
            }
            if (hasAnswer && !hasQuestion) {
                statusEl.textContent = 'Answer slides require at least one question slide to pair with.';
                return;
            }
            statusEl.textContent = 'Saving...';
            var form = new FormData();
            form.append('event_id', eventId);
            form.append('session_id', sessionId);
            form.append('save_banner', hasBanner ? '1' : '0');
            form.append('slides', JSON.stringify(classified));
            form.append('csrf_token', getCsrfToken());
            fetch('actions/batch_save.php', { method: 'POST', body: form })
                .then(function(r) { return r.json(); })
                .then(function(result) {
                    if (result.error) { statusEl.textContent = 'Error: ' + result.error; return; }
                    statusEl.textContent = 'Saved ' + result.created_questions + ' question(s), ' + result.created_promo + ' promo slide(s), ' + result.created_answers + ' answer slide(s).' + (result.banner_saved ? ' Banner saved.' : '');
                    setTimeout(function() { location.reload(); }, 1500);
                })
                .catch(function(err) { statusEl.textContent = 'Save failed: ' + err.message; });
        });

        clearBtn.addEventListener('click', function() {
            stagedImages = [];
            sessionId = null;
            previewGrid.innerHTML = '';
            classifyList.innerHTML = '';
            previewGrid.style.display = 'none';
            classifyPanel.style.display = 'none';
            statusEl.textContent = '';
            zipInput.value = '';
            multiInput.value = '';
        });
    }

    function getCsrfToken() {
        var input = document.querySelector('input[name="csrf_token"]');
        return input ? input.value : '';
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    document.addEventListener('DOMContentLoaded', function () {
        initTabs();
        initConfirm();
        initImagePreviews();
        initSlideUpload();
    });
})();
