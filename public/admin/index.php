<?php
require_once __DIR__ . '/../../src/bootstrap.php';
Auth::requireAdmin();

$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $old = $_POST;

    $v = new Validator();
    $name = $v->requiredString($_POST, 'name', 'Event name', 150);
    $subtitle = $v->optionalString($_POST, 'subtitle', 'Subtitle', 255);
    $eventDate = $v->optionalDate($_POST, 'event_date', 'Event date');

    if (!$v->hasErrors()) {
        $event = Event::create([
            'name'       => $name,
            'subtitle'   => $subtitle,
            'event_date' => $eventDate,
            'status'     => EventStatus::DRAFT,
        ]);
        EventSetting::createDefaultsFor((int) $event['id']);
        CompetitionSession::createFor((int) $event['id']);

        Flash::success("Event \"{$name}\" created. Configure it below.");
        header('Location: ' . relative_url('/admin/event.php?id=' . $event['id']));
        exit;
    }

    $errors = $v->errors();
}

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
            <form method="post">
                <?= Csrf::field() ?>
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
                    <?php if (isset($errors['event_date'])): ?><div class="error"><?= htmlspecialchars($errors['event_date']) ?></div><?php endif; ?>
                </div>
                <p class="hint">Branding, categories, contestants, and questions are configured after the event is created.</p>
                <button type="submit" class="btn btn-primary">Create event</button>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
