<?php
require_once __DIR__ . '/../../src/bootstrap.php';
Auth::requireAdmin();

$errors = [];
$old = [];

$events = Event::allOrderedByDate();

$pageTitle = 'Events';
require __DIR__ . '/includes/header.php';
?>

<div class="two-col">
    <div>
        <h1>Events</h1>
        <p class="subtitle">Every competition run through this system is an event. Create a new one, or open an existing one to configure it.</p>

        <div class="card">
            <?php if ($events === []): ?>
                <p class="muted">No events yet. Create your first event using the form on the right.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr><th>Name</th><th>Date</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($events as $ev): ?>
                        <tr>
                            <td>
                                <a href="event.php?id=<?= (int) $ev['id'] ?>"><?= htmlspecialchars($ev['name']) ?></a>
                                <?php if ($ev['subtitle']): ?><br><span class="muted"><?= htmlspecialchars($ev['subtitle']) ?></span><?php endif; ?>
                            </td>
                            <td><?= $ev['event_date'] ? htmlspecialchars($ev['event_date']) : '<span class="muted">—</span>' ?></td>
                            <td><span class="badge badge-<?= htmlspecialchars($ev['status']) ?>"><?= htmlspecialchars($ev['status']) ?></span></td>
                            <td class="row-actions">
                                <a class="btn btn-small" href="event.php?id=<?= (int) $ev['id'] ?>">Configure</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <h2>Create a new event</h2>
        <div class="card">
            <form id="event-create-form" method="post" enctype="multipart/form-data">
                <?= Csrf::field() ?>
                
                <!-- Event Details -->
                <div class="field <?= isset($errors['name']) ? 'has-error' : '' ?>">
                    <label for="name">Event name *</label>
                    <input type="text" id="name" name="name" value="<?= htmlspecialchars($old['name'] ?? '') ?>" required maxlength="150">
                    <?php if (isset($errors['name'])): ?><div class="error"><?= htmlspecialchars($errors['name']) ?></div><?php endif; ?>
                </div>
                <div class="field <?= isset($errors['subtitle']) ? 'has-error' : '' ?>">
                    <label for="subtitle">Subtitle</label>
                    <input type="text" id="subtitle" name="subtitle" value="<?= htmlspecialchars($old['subtitle'] ?? '') ?>" maxlength="255">
                </div>
                <div class="field <?= isset($errors['event_date']) ? 'has-error' : '' ?>">
                    <label for="event_date">Event date</label>
                    <input type="date" id="event_date" name="event_date" value="<?= htmlspecialchars($old['event_date'] ?? '') ?>">
                </div>

                <!-- Slide Upload Section -->
                <h3 style="margin:20px 0 12px;">Slides (optional)</h3>
                <p class="hint">Upload a ZIP or multiple images. After upload, classify each slide below.</p>

                <div id="slide-upload-area" class="slide-drop-zone">
                    <p style="margin:0;font-size:1rem;">Drag & drop a ZIP or images here</p>
                    <p style="margin:8px 0 0;font-size:0.85rem;color:var(--text-secondary);">or use one of these buttons:</p>
                    <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;justify-content:center;">
                        <label class="btn btn-primary" style="margin:0;cursor:pointer;">
                            Select Images
                            <input type="file" id="slide-multi-input" multiple accept=".png,.jpg,.jpeg,.webp" style="display:none;">
                        </label>
                        <label class="btn btn-primary" style="margin:0;cursor:pointer;">
                            Select ZIP
                            <input type="file" id="slide-zip-input" accept=".zip" style="display:none;">
                        </label>
                    </div>
                    
                    <!-- Explicit file status display -->
                    <div id="slide-file-status" style="margin-top:16px;padding:12px;background:var(--input-bg);border-radius:8px;display:none;">
                        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                            <div style="flex:1;min-width:200px;">
                                <strong id="slide-status-filename" style="display:block;font-size:0.9rem;"></strong>
                                <span id="slide-status-size" style="font-size:0.75rem;color:var(--text-secondary);"></span>
                            </div>
                            <span id="slide-status-badge" class="badge badge-draft" style="white-space:nowrap;">Selected</span>
                            <button type="button" id="slide-clear-file" class="btn btn-small" style="margin-left:8px;">Remove</button>
                        </div>
                    </div>
                    
                    <p id="slide-file-info" class="hint" style="margin-top:10px;"></p>
                    <div id="slide-upload-progress" style="display:none;margin-top:10px;">
                        <div style="display:flex;justify-content:space-between;margin-bottom:5px;">
                            <span id="slide-progress-text" class="hint">Uploading...</span>
                            <span id="slide-progress-percent" class="hint">0%</span>
                        </div>
                        <div style="background:var(--border);border-radius:999px;height:8px;overflow:hidden;">
                            <div id="slide-progress-bar" style="background:var(--secondary);height:100%;width:0%;transition:width 0.15s ease;"></div>
                        </div>
                    </div>
                    <p id="slide-upload-error" class="alert alert-error" style="display:none;margin-top:10px;text-align:left;"></p>
                </div>

                <!-- Classification Panel (shown after upload) -->
                <div id="slide-classify-panel" style="display:none;margin-top:20px;">
                    <h3>Classify each slide</h3>
                    <p class="hint">When you select "Question Slide", a difficulty dropdown will appear. Answer slides auto-pair to question slides in order.</p>
                    <div id="slide-classify-list"></div>
                    <input type="hidden" name="slides" id="slides-classification" value="[]">
                    <input type="hidden" name="save_banner" id="save-banner" value="0">
                    <div class="btn-row" style="margin-top:20px;">
                        <button type="button" id="slide-save-all" class="btn btn-primary">Save All Slides</button>
                        <button type="button" id="slide-clear-all" class="btn">Clear</button>
                    </div>
                    <div id="slide-save-status" class="hint" style="margin-top:8px;"></div>
                </div>

                <!-- Hidden inputs for final submit -->
                <input type="hidden" name="slides" id="slides-classification-final" value="[]">
                <input type="hidden" name="save_banner" id="save-banner-final" value="0">

                <div class="btn-row" style="margin-top:20px;">
                    <button type="submit" class="btn btn-primary" id="btn-create-event">Create Event & Save Slides</button>
                </div>
                <div id="create-error" class="alert alert-error" style="display:none;margin-top:12px;"></div>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
<script src="../assets/admin.js?v=2"></script>
<script src="../assets/index.js?v=2"></script>