<?php
require_once __DIR__ . '/../../src/bootstrap.php';
Auth::requireAdmin();

require_once __DIR__ . '/../../src/Models/PromotionalSlide.php';
require_once __DIR__ . '/../../src/Models/AnswerSlide.php';

function acs_findOwned(array $rows, int $id): ?array
{
    foreach ($rows as $row) {
        if ((int) $row['id'] === $id) {
            return $row;
        }
    }

    return null;
}

function acs_availableTransitions(string $status): array
{
    return match ($status) {
        EventStatus::DRAFT     => [EventStatus::READY],
        EventStatus::READY     => [EventStatus::ACTIVE, EventStatus::DRAFT],
        EventStatus::ACTIVE    => [EventStatus::COMPLETED],
        EventStatus::COMPLETED => [EventStatus::ARCHIVED],
        EventStatus::ARCHIVED  => [EventStatus::DRAFT],
        default => [],
    };
}

function acs_isEmptyDraft(array $event): bool
{
    return $event['status'] === EventStatus::DRAFT
        && $event['categories'] === []
        && $event['contestants'] === []
        && $event['questions'] === [];
}

$eventId = (int) ($_GET['id'] ?? 0);
if ($eventId <= 0 || Event::find($eventId) === null) {
    Flash::error('That event could not be found.');
    header('Location: ' . relative_url('/admin/index.php'));
    exit;
}

$event = Event::withRelations($eventId);
$settings = $event['settings'];
$toggles = EventSetting::presentationToggles($settings);
$readinessProblems = EventValidator::readinessProblems($eventId);
$promotionalSlides = PromotionalSlide::forEvent($eventId);
$answerSlides = AnswerSlide::forEvent($eventId);
$audioConfig = EventSetting::audioConfig($settings);

$editingCategory = isset($_GET['edit_category']) ? acs_findOwned($event['categories'], (int) $_GET['edit_category']) : null;
$editingContestant = isset($_GET['edit_contestant']) ? acs_findOwned($event['contestants'], (int) $_GET['edit_contestant']) : null;
$editingQuestion = isset($_GET['edit_question']) ? acs_findOwned($event['questions'], (int) $_GET['edit_question']) : null;

$categoryOptions = $event['categories'];

$pageTitle = $event['name'];
require __DIR__ . '/includes/header.php';
?>

<div class="event-header">
    <div>
        <h1><?= htmlspecialchars($event['name']) ?></h1>
        <p class="subtitle">
            <span class="badge badge-<?= htmlspecialchars($event['status']) ?>"><?= htmlspecialchars($event['status']) ?></span>
            <?php if ($event['event_date']): ?> &nbsp;&middot;&nbsp; <?= htmlspecialchars($event['event_date']) ?><?php endif; ?>
        </p>
    </div>
    <div>
        <a href="index.php" class="btn btn-small">&larr; All events</a>
        <a href="/admin/scores.php?id=<?= $eventId ?>" class="btn btn-small">View scores</a>
    </div>
</div>

<?php if ($readinessProblems !== []): ?>
    <div class="alert alert-error">
        <strong>This event is not yet ready to activate:</strong>
        <ul class="readiness-list">
            <?php foreach ($readinessProblems as $p): ?><li><?= htmlspecialchars($p) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="tabs">
    <a class="tab-link" data-tab-link="overview">Overview &amp; Branding</a>
    <a class="tab-link" data-tab-link="categories">Categories (<?= count($event['categories']) ?>)</a>
    <a class="tab-link" data-tab-link="contestants">Contestants (<?= count($event['contestants']) ?>)</a>
    <a class="tab-link" data-tab-link="questions">Questions (<?= count($event['questions']) ?>)</a>
    <a class="tab-link" data-tab-link="promotional">Promotional Slides</a>
    <a class="tab-link" data-tab-link="audio">Audio &amp; Media</a>
    <a class="tab-link" data-tab-link="settings">Scoring, Timing &amp; Presentation</a>
</div>

<!-- ===================== OVERVIEW & BRANDING ===================== -->
<div class="tab-panel" data-tab-panel="overview">
    <div class="two-col">
        <div class="card">
            <h2>Event details</h2>
            <form method="post" action="actions/event_save.php" enctype="multipart/form-data">
                <?= Csrf::field() ?>
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <div class="field">
                    <label for="name">Event name *</label>
                    <input type="text" id="name" name="name" value="<?= htmlspecialchars($event['name']) ?>" required maxlength="150">
                </div>
                <div class="field">
                    <label for="subtitle">Subtitle</label>
                    <input type="text" id="subtitle" name="subtitle" value="<?= htmlspecialchars($event['subtitle'] ?? '') ?>" maxlength="255">
                </div>
                <div class="field">
                    <label for="event_date">Event date</label>
                    <input type="date" id="event_date" name="event_date" value="<?= htmlspecialchars($event['event_date'] ?? '') ?>">
                </div>

                <h3>Branding</h3>
                <div class="form-grid">
                    <div class="field">
                        <label for="logo">Event logo</label>
                        <?php if (!empty($event['logo_path'])): ?>
                            <img class="thumb-lg" id="logo-preview" src="../<?= htmlspecialchars($event['logo_path']) ?>" alt="Current logo">
                            <label class="checkbox"><input type="checkbox" name="remove_logo" value="1"> Remove current logo</label>
                        <?php else: ?>
                            <img class="thumb-lg" id="logo-preview" style="display:none;" alt="Logo preview">
                        <?php endif; ?>
                        <input type="file" id="logo" name="logo" accept=".png,.jpg,.jpeg,.webp" data-preview-target="logo-preview">
                        <div class="hint">PNG, JPG, or WebP. Max 5 MB.</div>
                    </div>
                    <div class="field">
                        <label for="cover_image">Cover photo</label>
                        <?php if (!empty($event['cover_image_path'])): ?>
                            <img class="thumb-lg" id="cover-preview" src="../<?= htmlspecialchars($event['cover_image_path']) ?>" alt="Current cover">
                            <label class="checkbox"><input type="checkbox" name="remove_cover" value="1"> Remove current cover</label>
                        <?php else: ?>
                            <img class="thumb-lg" id="cover-preview" style="display:none;" alt="Cover preview">
                        <?php endif; ?>
                        <input type="file" id="cover_image" name="cover_image" accept=".png,.jpg,.jpeg,.webp" data-preview-target="cover-preview">
                        <div class="hint">PNG, JPG, or WebP. Max 5 MB.</div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">Save event details</button>
            </form>
        </div>

        <div class="card">
            <h2>Status</h2>
            <p class="muted">Current status: <span class="badge badge-<?= htmlspecialchars($event['status']) ?>"><?= htmlspecialchars($event['status']) ?></span></p>

            <form method="post" action="actions/event_status.php" class="btn-row" style="flex-wrap:wrap;">
                <?= Csrf::field() ?>
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <?php foreach (acs_availableTransitions($event['status']) as $target): ?>
                    <button type="submit" name="status" value="<?= htmlspecialchars($target) ?>" class="btn btn-small">
                        Mark as <?= htmlspecialchars($target) ?>
                    </button>
                <?php endforeach; ?>
            </form>

            <hr style="border:none;border-top:1px solid var(--color-border);margin:16px 0;">

            <h3>Danger zone</h3>
            <?php if (acs_isEmptyDraft($event)): ?>
                <form method="post" action="actions/event_delete.php"
                      onsubmit="return confirm('Permanently delete this event? This cannot be undone.');">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                    <button type="submit" class="btn btn-danger btn-small">Delete event permanently</button>
                </form>
                <p class="hint">This event has no configured data yet, so it can be deleted outright.</p>
            <?php elseif ($event['status'] !== EventStatus::ARCHIVED): ?>
                <form method="post" action="actions/event_status.php"
                      onsubmit="return confirm('Archive this event? It will be hidden from active use but its data and history are kept.');">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                    <input type="hidden" name="status" value="<?= EventStatus::ARCHIVED ?>">
                    <button type="submit" class="btn btn-danger btn-small">Archive event</button>
                </form>
                <p class="hint">This event has configured data (categories/contestants/questions/scores), so it's archived rather than deleted to preserve history.</p>
            <?php else: ?>
                <p class="muted">This event is archived.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ===================== CATEGORIES ===================== -->
<div class="tab-panel" data-tab-panel="categories">
    <div class="two-col">
        <div class="card">
            <h2>Categories</h2>
            <?php if ($event['categories'] === []): ?>
                <p class="muted">No categories yet. Categories are optional but help organize and label questions (e.g. "Mathematics", "Physics").</p>
            <?php else: ?>
                <table>
                    <thead><tr><th></th><th>Name</th><th>Description</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($event['categories'] as $i => $cat): ?>
                        <tr>
                            <td class="row-actions">
                                <?php if ($i > 0): ?>
                                <form method="post" action="actions/category_reorder.php">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <input type="hidden" name="category_id" value="<?= (int) $cat['id'] ?>">
                                    <input type="hidden" name="direction" value="up">
                                    <button type="submit" class="btn btn-small" title="Move up">&uarr;</button>
                                </form>
                                <?php endif; ?>
                                <?php if ($i < count($event['categories']) - 1): ?>
                                <form method="post" action="actions/category_reorder.php">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <input type="hidden" name="category_id" value="<?= (int) $cat['id'] ?>">
                                    <input type="hidden" name="direction" value="down">
                                    <button type="submit" class="btn btn-small" title="Move down">&darr;</button>
                                </form>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($cat['name']) ?></td>
                            <td class="muted"><?= htmlspecialchars($cat['description'] ?? '') ?></td>
                            <td class="row-actions">
                                <a class="btn btn-small" href="?id=<?= $eventId ?>&edit_category=<?= (int) $cat['id'] ?>#categories">Edit</a>
                                <form method="post" action="actions/category_delete.php" data-confirm="Delete category &quot;<?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>&quot;? Questions using it will become uncategorized.">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <input type="hidden" name="category_id" value="<?= (int) $cat['id'] ?>">
                                    <button type="submit" class="btn btn-small btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2><?= $editingCategory ? 'Edit category' : 'Add category' ?></h2>
            <form method="post" action="actions/category_save.php">
                <?= Csrf::field() ?>
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <?php if ($editingCategory): ?><input type="hidden" name="category_id" value="<?= (int) $editingCategory['id'] ?>"><?php endif; ?>
                <div class="field">
                    <label for="cat_name">Name *</label>
                    <input type="text" id="cat_name" name="name" required maxlength="100"
                           value="<?= htmlspecialchars($editingCategory['name'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="cat_description">Description</label>
                    <input type="text" id="cat_description" name="description" maxlength="255"
                           value="<?= htmlspecialchars($editingCategory['description'] ?? '') ?>">
                </div>
                <div class="btn-row">
                    <button type="submit" class="btn btn-primary"><?= $editingCategory ? 'Save changes' : 'Add category' ?></button>
                    <?php if ($editingCategory): ?><a class="btn" href="?id=<?= $eventId ?>#categories">Cancel</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===================== CONTESTANTS ===================== -->
<div class="tab-panel" data-tab-panel="contestants">
    <div class="two-col">
        <div class="card">
            <h2>Contestants / Teams</h2>
            <?php if ($event['contestants'] === []): ?>
                <p class="muted">No contestants yet. Add as many as this event needs — there's no fixed limit.</p>
            <?php else: ?>
                <table>
                    <thead><tr><th></th><th>Logo</th><th>Name</th><th>Code</th><th>Organization</th><th>Start</th><th>Active</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($event['contestants'] as $i => $c): ?>
                        <tr>
                            <td class="row-actions">
                                <?php if ($i > 0): ?>
                                <form method="post" action="actions/contestant_reorder.php">
                                    <?= Csrf::field() ?><input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <input type="hidden" name="contestant_id" value="<?= (int) $c['id'] ?>">
                                    <input type="hidden" name="direction" value="up">
                                    <button type="submit" class="btn btn-small" title="Move up">&uarr;</button>
                                </form>
                                <?php endif; ?>
                                <?php if ($i < count($event['contestants']) - 1): ?>
                                <form method="post" action="actions/contestant_reorder.php">
                                    <?= Csrf::field() ?><input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <input type="hidden" name="contestant_id" value="<?= (int) $c['id'] ?>">
                                    <input type="hidden" name="direction" value="down">
                                    <button type="submit" class="btn btn-small" title="Move down">&darr;</button>
                                </form>
                                <?php endif; ?>
                            </td>
                            <td><?php if (!empty($c['logo_path'])): ?><img class="thumb" src="../<?= htmlspecialchars($c['logo_path']) ?>" alt=""><?php endif; ?></td>
                            <td><?= htmlspecialchars($c['name']) ?></td>
                            <td class="muted"><?= htmlspecialchars($c['team_code'] ?? '') ?></td>
                            <td class="muted"><?= htmlspecialchars($c['organization'] ?? '') ?></td>
                            <td><?= (int) $c['starting_score'] ?></td>
                            <td><?= ((int) $c['is_active']) ? 'Yes' : '<span class="muted">No</span>' ?></td>
                            <td class="row-actions">
                                <a class="btn btn-small" href="?id=<?= $eventId ?>&edit_contestant=<?= (int) $c['id'] ?>#contestants">Edit</a>
                                <form method="post" action="actions/contestant_delete.php" data-confirm="Remove &quot;<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>&quot;? If it has recorded scores it will be deactivated instead of deleted, to preserve history.">
                                    <?= Csrf::field() ?><input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <input type="hidden" name="contestant_id" value="<?= (int) $c['id'] ?>">
                                    <button type="submit" class="btn btn-small btn-danger">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2><?= $editingContestant ? 'Edit contestant' : 'Add contestant' ?></h2>
            <form method="post" action="actions/contestant_save.php" enctype="multipart/form-data">
                <?= Csrf::field() ?>
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <?php if ($editingContestant): ?><input type="hidden" name="contestant_id" value="<?= (int) $editingContestant['id'] ?>"><?php endif; ?>
                <div class="field">
                    <label for="con_name">Name *</label>
                    <input type="text" id="con_name" name="name" required maxlength="150" value="<?= htmlspecialchars($editingContestant['name'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="con_code">Team number / code</label>
                    <input type="text" id="con_code" name="team_code" maxlength="20" value="<?= htmlspecialchars($editingContestant['team_code'] ?? '') ?>">
                    <div class="hint">Must be unique within this event, if set.</div>
                </div>
                <div class="field">
                    <label for="con_org">Organization / school</label>
                    <input type="text" id="con_org" name="organization" maxlength="150" value="<?= htmlspecialchars($editingContestant['organization'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="con_start">Starting score</label>
                    <input type="number" id="con_start" name="starting_score" value="<?= (int) ($editingContestant['starting_score'] ?? 0) ?>">
                </div>
                <div class="field">
                    <label for="con_logo">Logo (optional)</label>
                    <?php if (!empty($editingContestant['logo_path'])): ?>
                        <img class="thumb-lg" id="con-logo-preview" src="../<?= htmlspecialchars($editingContestant['logo_path']) ?>" alt="">
                        <label class="checkbox"><input type="checkbox" name="remove_logo" value="1"> Remove current logo</label>
                    <?php else: ?>
                        <img class="thumb-lg" id="con-logo-preview" style="display:none;" alt="">
                    <?php endif; ?>
                    <input type="file" id="con_logo" name="logo" accept=".png,.jpg,.jpeg,.webp" data-preview-target="con-logo-preview">
                </div>
                <?php if ($editingContestant): ?>
                <div class="field">
                    <label class="checkbox"><input type="checkbox" name="is_active" value="1" <?= ((int) $editingContestant['is_active']) ? 'checked' : '' ?>> Active</label>
                </div>
                <?php endif; ?>
                <div class="btn-row">
                    <button type="submit" class="btn btn-primary"><?= $editingContestant ? 'Save changes' : 'Add contestant' ?></button>
                    <?php if ($editingContestant): ?><a class="btn" href="?id=<?= $eventId ?>#contestants">Cancel</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===================== QUESTIONS ===================== -->
<div class="tab-panel" data-tab-panel="questions">
    <div class="two-col">
        <div class="card">
            <h2>Questions</h2>
            <?php if ($event['questions'] === []): ?>
                <p class="muted">No questions yet. Upload the first question slide using the form on the right.</p>
            <?php else: ?>
                <table>
                    <thead><tr><th></th><th>Preview</th><th>#</th><th>Category</th><th>Points</th><th>Time</th><th>Active</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($event['questions'] as $i => $q): ?>
                        <?php
                            $catName = '—';
                            foreach ($event['categories'] as $cat) {
                                if ((int) $cat['id'] === (int) $q['category_id']) { $catName = $cat['name']; break; }
                            }
                            $pts = Question::effectivePoints($q, $settings);
                            $time = Question::effectiveTimeSeconds($q, $settings);
                        ?>
                        <tr>
                            <td class="row-actions">
                                <?php if ($i > 0): ?>
                                <form method="post" action="actions/question_reorder.php">
                                    <?= Csrf::field() ?><input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
                                    <input type="hidden" name="direction" value="up">
                                    <button type="submit" class="btn btn-small" title="Move up">&uarr;</button>
                                </form>
                                <?php endif; ?>
                                <?php if ($i < count($event['questions']) - 1): ?>
                                <form method="post" action="actions/question_reorder.php">
                                    <?= Csrf::field() ?><input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
                                    <input type="hidden" name="direction" value="down">
                                    <button type="submit" class="btn btn-small" title="Move down">&darr;</button>
                                </form>
                                <?php endif; ?>
                            </td>
                            <td><img class="thumb" src="../<?= htmlspecialchars($q['image_path']) ?>" alt="Question <?= (int) $q['question_number'] ?>"></td>
                            <td>Q<?= (int) $q['question_number'] ?></td>
                            <td class="muted"><?= htmlspecialchars($catName) ?></td>
                            <td><?= $pts ?><?= $q['points'] === null ? ' <span class="muted">(default)</span>' : '' ?></td>
                            <td><?= $time ?>s<?= $q['time_seconds'] === null ? ' <span class="muted">(default)</span>' : '' ?></td>
                            <td><?= ((int) $q['is_active']) ? 'Yes' : '<span class="muted">No</span>' ?></td>
                            <td class="row-actions">
                                <a class="btn btn-small" href="?id=<?= $eventId ?>&edit_question=<?= (int) $q['id'] ?>#questions">Edit</a>
                                <form method="post" action="actions/question_delete.php" data-confirm="Delete question Q<?= (int) $q['question_number'] ?>? If it has recorded scores it will be deactivated instead of deleted, to preserve history.">
                                    <?= Csrf::field() ?><input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
                                    <button type="submit" class="btn btn-small btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2><?= $editingQuestion ? 'Edit question' : 'Add question' ?></h2>
            <form method="post" action="actions/question_save.php" enctype="multipart/form-data">
                <?= Csrf::field() ?>
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <?php if ($editingQuestion): ?><input type="hidden" name="question_id" value="<?= (int) $editingQuestion['id'] ?>"><?php endif; ?>

                <div class="field">
                    <label for="q_number">Question number *</label>
                    <input type="number" id="q_number" name="question_number" min="1" max="9999" required
                           value="<?= (int) ($editingQuestion['question_number'] ?? Question::nextQuestionNumber($eventId)) ?>">
                    <div class="hint">Must be unique within this event. This is the label shown (e.g. "Question 3"); use the &uarr;/&darr; buttons on the left to change presentation order separately.</div>
                </div>

                <div class="field">
                    <label for="q_category">Category</label>
                    <select id="q_category" name="category_id">
                        <option value="">— Uncategorized —</option>
                        <?php foreach ($categoryOptions as $cat): ?>
                            <option value="<?= (int) $cat['id'] ?>" <?= (isset($editingQuestion['category_id']) && (int) $editingQuestion['category_id'] === (int) $cat['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label for="q_points">Points</label>
                        <input type="number" id="q_points" name="points" min="0" max="100000"
                               placeholder="Default: <?= (int) $settings['default_points'] ?>"
                               value="<?= htmlspecialchars((string) ($editingQuestion['points'] ?? '')) ?>">
                        <div class="hint">Leave blank to use the event default (<?= (int) $settings['default_points'] ?>).</div>
                    </div>
                    <div class="field">
                        <label for="q_time">Time limit (seconds)</label>
                        <input type="number" id="q_time" name="time_seconds" min="5" max="7200"
                               placeholder="Default: <?= (int) $settings['default_time_seconds'] ?>"
                               value="<?= htmlspecialchars((string) ($editingQuestion['time_seconds'] ?? '')) ?>">
                        <div class="hint">Leave blank to use the event default (<?= (int) $settings['default_time_seconds'] ?>s).</div>
                    </div>
                </div>

                <div class="field">
                    <label for="q_image">Question slide image <?= $editingQuestion ? '' : '*' ?></label>
                    <?php if (!empty($editingQuestion['image_path'])): ?>
                        <img class="thumb-lg" id="q-image-preview" src="../<?= htmlspecialchars($editingQuestion['image_path']) ?>" alt="">
                        <div class="hint">Upload a new file to replace the current slide.</div>
                    <?php else: ?>
                        <img class="thumb-lg" id="q-image-preview" style="display:none;" alt="">
                    <?php endif; ?>
                    <input type="file" id="q_image" name="image" accept=".png,.jpg,.jpeg,.webp" data-preview-target="q-image-preview" <?= $editingQuestion ? '' : 'required' ?>>
                    <div class="hint">PNG, JPG, or WebP. Max 5 MB. Displayed as-is on the public screen — do not retype the question as text.</div>
                </div>

                <?php if ($editingQuestion): ?>
                <div class="field">
                    <label class="checkbox"><input type="checkbox" name="is_active" value="1" <?= ((int) $editingQuestion['is_active']) ? 'checked' : '' ?>> Active (shown during the competition)</label>
                </div>
                <?php endif; ?>

                <div class="btn-row">
                    <button type="submit" class="btn btn-primary"><?= $editingQuestion ? 'Save changes' : 'Add question' ?></button>
                    <?php if ($editingQuestion): ?><a class="btn" href="?id=<?= $eventId ?>#questions">Cancel</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===================== SETTINGS ===================== -->
<div class="tab-panel" data-tab-panel="settings">
    <div class="card" style="max-width:640px;">
        <h2>Scoring &amp; timing defaults</h2>
        <form method="post" action="actions/settings_save.php">
            <?= Csrf::field() ?>
            <input type="hidden" name="event_id" value="<?= $eventId ?>">
            <input type="hidden" name="settings_id" value="<?= (int) $settings['id'] ?>">

            <div class="form-grid">
                <div class="field">
                    <label for="default_points">Default points per question</label>
                    <input type="number" id="default_points" name="default_points" min="0" max="100000" value="<?= (int) $settings['default_points'] ?>">
                </div>
                <div class="field">
                    <label for="default_time_seconds">Default time per question (seconds)</label>
                    <input type="number" id="default_time_seconds" name="default_time_seconds" min="5" max="7200" value="<?= (int) $settings['default_time_seconds'] ?>">
                </div>
                <div class="field">
                    <label for="ranking_order">Ranking order</label>
                    <select id="ranking_order" name="ranking_order">
                        <option value="score_desc" <?= $settings['ranking_order'] === 'score_desc' ? 'selected' : '' ?>>Highest score first (standard)</option>
                        <option value="score_asc" <?= $settings['ranking_order'] === 'score_asc' ? 'selected' : '' ?>>Lowest score first</option>
                    </select>
                </div>
                <div class="field">
                    <label for="timer_warning_seconds">Timer warning (seconds remaining)</label>
                    <input type="number" id="timer_warning_seconds" name="timer_warning_seconds" min="0" max="600"
                           value="<?= htmlspecialchars((string) ($settings['timer_warning_seconds'] ?? '')) ?>" placeholder="Disabled">
                    <div class="hint">Leave blank to disable. Used by the public display to flash/warn as time runs low.</div>
                </div>
            </div>

            <h3>Presentation</h3>
            <div class="field">
                <label class="checkbox"><input type="checkbox" name="show_category" value="1" <?= $toggles['show_category'] ? 'checked' : '' ?>> Show category on the public display</label>
            </div>
            <div class="field">
                <label class="checkbox"><input type="checkbox" name="show_question_number" value="1" <?= $toggles['show_question_number'] ? 'checked' : '' ?>> Show question number on the public display</label>
            </div>
            <div class="field">
                <label class="checkbox"><input type="checkbox" name="show_timer" value="1" <?= $toggles['show_timer'] ? 'checked' : '' ?>> Show timer on the public display</label>
            </div>
            <div class="field">
                <label class="checkbox"><input type="checkbox" name="auto_show_ranking_after_score" value="1" <?= ((int) $settings['auto_show_ranking_after_score']) ? 'checked' : '' ?>> Automatically show ranking after scores are entered</label>
                <div class="hint">If unchecked, the operator must manually choose when to reveal the ranking.</div>
            </div>

            <button type="submit" class="btn btn-primary">Save settings</button>
        </form>
    </div>
</div>

<!-- ===================== PROMOTIONAL SLIDES ===================== -->
<div class="tab-panel" data-tab-panel="promotional">
    <div class="two-col">
        <div class="card">
            <h2>Promotional Slides</h2>
            <?php if ($promotionalSlides === []): ?>
                <p class="muted">No promotional slides yet. Add slides for intermissions, ads, or announcements.</p>
            <?php else: ?>
                <table>
                    <thead><tr><th></th><th>Preview</th><th>Title</th><th>Description</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($promotionalSlides as $i => $slide): ?>
                        <tr>
                            <td class="row-actions">
                                <?php if ($i > 0): ?>
                                <form method="post" action="actions/promotional_reorder.php">
                                    <?= Csrf::field() ?><input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <input type="hidden" name="slide_id" value="<?= (int) $slide['id'] ?>">
                                    <input type="hidden" name="direction" value="up">
                                    <button type="submit" class="btn btn-small" title="Move up">&uarr;</button>
                                </form>
                                <?php endif; ?>
                                <?php if ($i < count($promotionalSlides) - 1): ?>
                                <form method="post" action="actions/promotional_reorder.php">
                                    <?= Csrf::field() ?><input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <input type="hidden" name="slide_id" value="<?= (int) $slide['id'] ?>">
                                    <input type="hidden" name="direction" value="down">
                                    <button type="submit" class="btn btn-small" title="Move down">&darr;</button>
                                </form>
                                <?php endif; ?>
                            </td>
                            <td><?php if (!empty($slide['file_path'])): ?><img class="thumb" src="../<?= htmlspecialchars($slide['file_path']) ?>" alt=""><?php endif; ?></td>
                            <td><?= htmlspecialchars($slide['title']) ?></td>
                            <td class="muted"><?= htmlspecialchars($slide['description'] ?? '—') ?></td>
                            <td class="row-actions">
                                <form method="post" action="actions/promotional_delete.php" data-confirm="Delete promotional slide &quot;<?= htmlspecialchars($slide['title'], ENT_QUOTES) ?>&quot;?">
                                    <?= Csrf::field() ?><input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <input type="hidden" name="slide_id" value="<?= (int) $slide['id'] ?>">
                                    <button type="submit" class="btn btn-small btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Add Promotional Slide</h2>
            <form method="post" action="actions/promotional_save.php" enctype="multipart/form-data">
                <?= Csrf::field() ?>
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <div class="field">
                    <label for="promo_title">Title *</label>
                    <input type="text" id="promo_title" name="title" required maxlength="255">
                </div>
                <div class="field">
                    <label for="promo_desc">Description</label>
                    <input type="text" id="promo_desc" name="description" maxlength="500">
                    <div class="hint">Optional text shown below the slide.</div>
                </div>
                <div class="field">
                    <label for="promo_file">Slide File *</label>
                    <input type="file" id="promo_file" name="file" accept=".png,.jpg,.jpeg,.webp">
                    <div class="hint">PNG, JPG, or WebP. Max 5 MB.</div>
                </div>
                <div class="btn-row">
                    <button type="submit" class="btn btn-primary">Add Slide</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===================== AUDIO & MEDIA ===================== -->
<div class="tab-panel" data-tab-panel="audio">
    <div class="card" style="max-width:640px;">
        <h2>Audio &amp; Media Settings</h2>
        <form method="post" action="actions/settings_save.php" enctype="multipart/form-data">
            <?= Csrf::field() ?>
            <input type="hidden" name="event_id" value="<?= $eventId ?>">
            <input type="hidden" name="settings_id" value="<?= (int) $settings['id'] ?>">

            <h3>Timer Audio</h3>
            <div class="field">
                <label for="timer_audio">5-second countdown audio</label>
                <input type="file" id="timer_audio" name="timer_audio" accept=".mp3,.wav,.ogg">
                <?php if (!empty($audioConfig['timer_audio_path'])): ?>
                    <div class="hint">Current: <a href="../<?= htmlspecialchars($audioConfig['timer_audio_path']) ?>" target="_blank">View audio</a></div>
                <?php endif; ?>
                <div class="hint">Plays when timer reaches 5 seconds remaining.</div>
            </div>

            <h3>Background Audio</h3>
            <div class="form-grid">
                <div class="field">
                    <label for="banner_audio">Banner/Cover audio</label>
                    <input type="file" id="banner_audio" name="banner_audio" accept=".mp3,.wav,.ogg">
                    <div class="hint">Plays at 100% volume during cover screen.</div>
                </div>
                <div class="field">
                    <label for="game_audio">Game background audio</label>
                    <input type="file" id="game_audio" name="game_audio" accept=".mp3,.wav,.ogg">
                    <div class="hint">Plays at 30% volume during questions and rankings.</div>
                </div>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label for="banner_volume">Banner volume (%)</label>
                    <input type="number" id="banner_volume" name="banner_audio_volume" min="0" max="100" value="<?= (int) ($audioConfig['banner_audio_volume'] ?? 100) ?>">
                </div>
                <div class="field">
                    <label for="game_volume">Game volume (%)</label>
                    <input type="number" id="game_volume" name="game_audio_volume" min="0" max="100" value="<?= (int) ($audioConfig['game_audio_volume'] ?? 30) ?>">
                </div>
            </div>

            <h3>Times Up Image</h3>
            <div class="field">
                <label for="times_up_image">Times up / Raise board image</label>
                <?php if (!empty($audioConfig['times_up_image_path'])): ?>
                    <img class="thumb-lg" id="timesup-preview" src="../<?= htmlspecialchars($audioConfig['times_up_image_path']) ?>" alt="Current times up image">
                    <label class="checkbox"><input type="checkbox" name="remove_timesup" value="1"> Remove current image</label>
                <?php else: ?>
                    <img class="thumb-lg" id="timesup-preview" style="display:none;" alt="Times up preview">
                <?php endif; ?>
                <input type="file" id="times_up_image" name="times_up_image" accept=".png,.jpg,.jpeg,.webp" data-preview-target="timesup-preview">
                <div class="hint">Shown instead of "TIME'S UP" text when timer expires.</div>
            </div>

            <button type="submit" class="btn btn-primary">Save audio settings</button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
