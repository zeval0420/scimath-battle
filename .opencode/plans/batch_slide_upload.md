# Batch Slide Upload for Event Creation

## Goal

Allow event creators to upload a ZIP file or multiple images at once, classify them as banner/promo/question/answer slides, auto-match questions to answers by order, and save everything in bulk.

---

## Files to Create/Modify

### 1. `src/Models/Question.php` — Add `createBatch()` method

Add a static method that inserts multiple questions in a transaction:

```php
public static function createBatch(int $eventId, array $items): array
{
    $db = self::db();
    $db->beginTransaction();
    $inserted = [];
    try {
        $stmt = $db->prepare(
            'INSERT INTO scimath_questions (event_id,category_id,round,question_number,' .
            'image_path,answer_image_path,points,time_seconds,is_active,display_order) ' .
            'VALUES (:event_id,:category_id,:round,:question_number,:image_path,:answer_image_path,' .
            ':points,:time_seconds,:is_active,:display_order)'
        );
        foreach ($items as $index => $item) {
            $stmt->execute([
                'event_id'         => $eventId,
                'category_id'      => $item['category_id'] ?: null,
                'round'             => $item['round'] ?? Round::EASY,
                'question_number'   => (int) $item['question_number'],
                'image_path'        => $item['image_path'],
                'answer_image_path' => $item['answer_image_path'] ?? null,
                'points'            => $item['points'] ?: null,
                'time_seconds'      => $item['time_seconds'] ?: null,
                'is_active'         => $item['is_active'] ? 1 : 0,
                'display_order'     => $index,
            ]);
            $inserted[] = self::findOrFail((int) $db->lastInsertId());
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return $inserted;
}
```

### 2. `src/Models/PromotionalSlide.php` — Add `createBatch()` method

```php
public static function createBatch(int $eventId, array $items): void
{
    $db = self::db();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'INSERT INTO scimath_promotional_slides (event_id,title,description,file_path,' .
            'file_type,display_order,is_active) VALUES (:event_id,:title,:description,' .
            ':file_path,:file_type,:display_order,:is_active)'
        );
        foreach ($items as $index => $item) {
            $stmt->execute([
                'event_id'     => $eventId,
                'title'        => $item['title'] ?: "Slide " . ($index + 1),
                'description'  => $item['description'] ?: null,
                'file_path'    => $item['file_path'],
                'file_type'    => 'image',
                'display_order'=> $index,
                'is_active'    => $item['is_active'] ? 1 : 0,
            ]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
```

### 3. `src/Models/AnswerSlide.php` — Add `createBatch()` method

```php
public static function createBatch(int $eventId, array $items): void
{
    $db = self::db();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'INSERT INTO scimath_answer_slides (event_id,question_id,image_path,' .
            'display_order,is_active) VALUES (:event_id,:question_id,:image_path,' .
            ':display_order,:is_active)'
        );
        foreach ($items as $index => $item) {
            $stmt->execute([
                'event_id'     => $eventId,
                'question_id'  => $item['question_id'] ?: null,
                'image_path'   => $item['image_path'],
                'display_order'=> $index,
                'is_active'    => $item['is_active'] ? 1 : 0,
            ]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
```

### 4. `public/admin/actions/batch_upload.php` — New action

Accepts either:
- A single ZIP file (`upload`) containing images
- Multiple image files (`files[]`)

Extracts images, validates each one with the existing `Uploader` logic, returns JSON with:
```json
{
  "images": [
    {
      "index": 0,
      "filename": "q1.png",
      "preview": "data:image/png;base64,...",
      "tmpPath": "/path/to/tmp"
    }
  ],
  "sessionId": "abc123"
}
```

The session ID stores the temp paths in `$_SESSION` so they survive until batch_save.

Key steps:
1. Verify admin auth + CSRF
2. Detect ZIP vs multi-file
3. If ZIP: use `ZipArchive` to extract to temp dir
4. For each image: validate via `Uploader::handleImage()` logic (but don't persist yet — store in temp), generate base64 preview
5. Store results in `$_SESSION['batch_upload'][$sessionId]`
6. Return JSON

### 5. `public/admin/actions/batch_save.php` — New action

Receives:
- `session_id` — to look up staged images
- `save_banner` — bool, save first image as cover
- `slides` — array of `{ index, type, category_id, round, points, time_seconds }`

Where `type` is one of: `banner`, `promotional`, `question`, `answer`

Logic:
1. Verify admin auth + CSRF
2. Look up staged images from `$_SESSION['batch_upload'][$sessionId]`
3. Classify:
   - First image with `type=banner` → save to event as cover_image
   - Images with `type=promotional` → create PromotionalSlide rows
   - Images with `type=question` → create Question rows (sequential numbering)
   - Images with `type=answer` → create AnswerSlide rows, paired sequentially with questions
4. Move staged files to final upload location via Uploader
5. Clear session entry
6. Flash success, redirect to event page with `#slides` hash

### 6. `public/admin/event.php` — Add "Slides" tab

Add a new tab link: `<a class="tab-link" data-tab-link="slides">Slides</a>`

Add a new tab panel:
```html
<div class="tab-panel" data-tab-panel="slides">
    <div class="card">
        <h2>Batch Slide Upload</h2>
        <p class="hint">Upload a ZIP file or multiple images at once. Classify each slide below, then save all at once.</p>
        
        <!-- Upload area -->
        <div id="slide-upload-area" class="slide-drop-zone">
            <p>Drag &amp; drop a ZIP or images here, or <label>browse<span id="slide-file-input" style="display:none;" type="file" multiple accept=".zip,.png,.jpg,.jpeg,.webp"></span></label></p>
            <input type="file" id="slide-zip-input" accept=".zip" style="display:none;">
            <input type="file" id="slide-multi-input" multiple accept=".png,.jpg,.jpeg,.webp" style="display:none;">
        </div>
        
        <!-- Preview grid (hidden until uploaded) -->
        <div id="slide-preview-grid" class="slide-grid" style="display:none;"></div>
        
        <!-- Classification controls (shown after preview) -->
        <div id="slide-classify-panel" style="display:none;">
            <h3>Classify each slide</h3>
            <p class="hint">Answer slides will be paired to question slides in order (1st answer → 1st question, 2nd → 2nd, etc.)</p>
            <div id="slide-classify-list"></div>
            <div class="btn-row" style="margin-top:20px;">
                <button id="slide-save-all" class="btn btn-primary">Save All Slides</button>
                <button id="slide-clear-all" class="btn">Clear</button>
            </div>
            <div id="slide-save-status" class="hint" style="margin-top:8px;"></div>
        </div>
    </div>
</div>
```

CSS additions needed (add to `admin.css`):
```css
.slide-drop-zone {
    border: 2px dashed var(--border);
    border-radius: var(--radius-card);
    padding: 40px 20px;
    text-align: center;
    cursor: pointer;
    transition: border-color 0.2s, background 0.2s;
}
.slide-drop-zone.drag-over {
    border-color: var(--secondary);
    background: var(--secondary-glow);
}
.slide-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 12px;
    margin-top: 16px;
}
.slide-thumb-card {
    background: var(--input-bg);
    border: 1px solid var(--border);
    border-radius: 10px;
    overflow: hidden;
}
.slide-thumb-card img {
    width: 100%;
    height: 100px;
    object-fit: cover;
}
.slide-thumb-card .slide-thumb-info {
    padding: 8px;
}
.slide-thumb-card .slide-thumb-name {
    font-size: 0.72rem;
    color: var(--text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.slide-thumb-card select {
    width: 100%;
    margin-top: 4px;
    font-size: 0.78rem;
    padding: 4px 6px;
}
```

### 7. `public/assets/admin.js` — Add slide upload JS

New section to add before the closing IIFE:
```javascript
    // ---- Batch Slide Upload -----------------------------------------
    function initSlideUpload() {
        var dropZone = document.getElementById('slide-upload-area');
        var zipInput = document.getElementById('slide-zip-input');
        var multiInput = document.getElementById('slide-multi-input');
        var fileBrowse = document.getElementById('slide-file-input');
        var previewGrid = document.getElementById('slide-preview-grid');
        var classifyPanel = document.getElementById('slide-classify-panel');
        var classifyList = document.getElementById('slide-classify-list');
        var saveBtn = document.getElementById('slide-save-all');
        var clearBtn = document.getElementById('slide-clear-all');
        var statusEl = document.getElementById('slide-save-status');

        if (!dropZone) return;

        var stagedImages = [];
        var sessionId = null;

        function triggerZip() { zipInput.click(); }
        function triggerMulti() { multiInput.click(); }
        fileBrowse.addEventListener('click', function(e) { e.stopPropagation(); });
        dropZone.addEventListener('click', function(e) {
            if (e.target === dropZone || e.target.tagName === 'P' || e.target.classList.contains('hint')) {
                if (zipInput.files.length === 0 && multiInput.files.length === 0) triggerZip();
            }
        });
        dropZone.addEventListener('dragover', function(e) {
            e.preventDefault();
            dropZone.classList.add('drag-over');
        });
        dropZone.addEventListener('dragleave', function() { dropZone.classList.remove('drag-over'); });
        dropZone.addEventListener('drop', function(e) {
            e.preventDefault();
            dropZone.classList.remove('drag-over');
            var files = e.dataTransfer.files;
            if (files.length === 1 && files[0].name.toLowerCase().endsWith('.zip')) {
                handleZip(files[0]);
            } else {
                handleMulti(files);
            }
        });
        zipInput.addEventListener('change', function() {
            if (zipInput.files.length) handleZip(zipInput.files[0]);
        });
        multiInput.addEventListener('change', function() {
            if (multiInput.files.length) handleMulti(multiInput.files);
        });

        function handleZip(file) {
            // For ZIP: server-side extraction via batch_upload.php
            var form = new FormData();
            form.append('upload', file);
            form.append('csrf_token', document.querySelector('[name="csrf_token"]')?.value || '');
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
            // For multi-file: send each via FormData to batch_upload.php
            var form = new FormData();
            for (var i = 0; i < files.length; i++) form.append('files[]', files[i]);
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
            images.forEach(function(img, idx) {
                // Grid preview card
                var card = document.createElement('div');
                card.className = 'slide-thumb-card';
                card.innerHTML = '<img src="' + img.preview + '" alt=""><div class="slide-thumb-info"><div class="slide-thumb-name" title="' + img.filename + '">' + img.filename + '</div></div>';
                previewGrid.appendChild(card);

                // Classification row
                var row = document.createElement('div');
                row.className = 'slide-classify-row';
                row.style.cssText = 'display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid var(--border);';
                row.innerHTML =
                    '<img src="' + img.preview + '" style="width:48px;height:36px;object-fit:cover;border-radius:6px;flex-shrink:0;">' +
                    '<span style="flex:1;font-size:0.82rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + img.filename + '">' + img.filename + '</span>' +
                    '<select data-index="' + idx + '" class="slide-type-select" style="width:auto;margin:0;">' +
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
            statusEl.textContent = '';
        }

        saveBtn.addEventListener('click', function() {
            var selects = classifyList.querySelectorAll('.slide-type-select');
            var data = { session_id: sessionId, slides: [], save_banner: false };
            var hasQuestion = false;
            var hasAnswer = false;
            selects.forEach(function(sel) {
                var idx = parseInt(sel.dataset.index);
                var img = stagedImages[idx];
                if (!img || !sel.value) return;
                if (sel.value === 'banner') data.save_banner = true;
                if (sel.value === 'question') hasQuestion = true;
                if (sel.value === 'answer') hasAnswer = true;
                data.slides.push({ index: idx, type: sel.value });
            });
            if (!hasQuestion && !hasAnswer && !data.save_banner) {
                statusEl.textContent = 'Please classify at least one slide.';
                return;
            }
            if (hasAnswer && !hasQuestion) {
                statusEl.textContent = 'Answer slides require at least one question slide to pair with.';
                return;
            }
            statusEl.textContent = 'Saving...';
            var form = new FormData();
            form.append('session_id', sessionId);
            form.append('save_banner', data.save_banner ? '1' : '0');
            form.append('slides', JSON.stringify(data.slides));
            fetch('actions/batch_save.php', { method: 'POST', body: form })
                .then(function(r) { return r.json(); })
                .then(function(result) {
                    if (result.error) { statusEl.textContent = 'Error: ' + result.error; return; }
                    statusEl.textContent = 'Saved ' + (result.created_questions || 0) + ' questions, ' + (result.created_promo || 0) + ' promo slides, ' + (result.created_answers || 0) + ' answer slides.';
                    setTimeout(function() { location.reload(); }, 1200);
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

    document.addEventListener('DOMContentLoaded', function () {
        initTabs();
        initConfirm();
        initImagePreviews();
        initSlideUpload();
    });
```

### 8. `public/admin/index.php` — Add "Upload Slides" CTA after creation

After event creation redirect, add a flash message hint or a direct button. Actually, the redirect already goes to `event.php?id=N`. We just need to ensure the Slides tab is accessible. The existing redirect can optionally append `#slides` to open that tab directly.

Change line 28 in `index.php`:
```php
header('Location: ' . relative_url('/admin/event.php?id=' . $event['id'] . '#slides'));
```

### 9. `public/admin/event.php` — Register the Slides tab

Add the tab link (around line 92, after the existing tabs):
```html
<a class="tab-link" data-tab-link="slides">Slides</a>
```

Ensure the initial tab activation considers `slides` if the hash matches.

---

## Execution Order

1. Add `createBatch()` to all three model files
2. Create `batch_upload.php`
3. Create `batch_save.php`
4. Update `event.php` (add tab + panel HTML + CSS classes)
5. Update `admin.js` (add `initSlideUpload()`)
6. Update `index.php` (redirect to `#slides`)
7. Add slide-specific CSS to `admin.css`

## Notes

- The batch upload stores temp file info in `$_SESSION` keyed by a random session ID. This avoids exposing server paths in the client.
- Images are validated through the existing `Uploader` logic (MIME check, GD decode, re-encode) but persisted only on `batch_save`.
- Question numbers are assigned sequentially starting from `Question::nextQuestionNumber($eventId)`.
- Answer slides are paired to question slides in order: 1st answer → 1st question, 2nd → 2nd, etc.
- If no answer slides are classified, no `scimath_answer_slides` rows are created (questions remain without answer reveals, which is the existing behavior).
