<?php
require_once __DIR__ . '/../../src/bootstrap.php';
Auth::requireAdmin();

$events = array_values(array_filter(
    Event::allOrderedByDate(),
    fn ($e) => in_array($e['status'], [EventStatus::READY, EventStatus::ACTIVE, EventStatus::COMPLETED], true)
));

$pageTitle = 'Scorer Interface';
require __DIR__ . '/../admin/includes/header.php';
?>

<div class="scorer-hero">
    <h1>Scorer / Tabulator Interface</h1>
    <p class="subtitle">Select an event to view and manage live scores. This interface provides a real-time scoreboard view optimized for the official scorer.</p>
</div>

<div class="card scorer-event-list">
    <?php if ($events === []): ?>
        <p class="muted">No events are available for scoring yet. <a href="../admin/index.php">Go to the admin panel</a> to configure an event.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Event Name</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Contestants</th>
                    <th>Questions</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($events as $ev): ?>
                    <?php
                        $contestants = Contestant::forEvent((int) $ev['id']);
                        $questions = Question::forEvent((int) $ev['id'], true);
                    ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($ev['name']) ?></strong>
                            <?php if ($ev['subtitle']): ?>
                                <br><span class="muted"><?= htmlspecialchars($ev['subtitle']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= $ev['event_date'] ? htmlspecialchars($ev['event_date']) : '<span class="muted">—</span>' ?></td>
                        <td><span class="badge badge-<?= htmlspecialchars($ev['status']) ?>"><?= htmlspecialchars($ev['status']) ?></span></td>
                        <td class="muted"><?= count($contestants) ?></td>
                        <td class="muted"><?= count($questions) ?></td>
                        <td class="row-actions">
                            <a class="btn btn-primary btn-small" href="scores.php?id=<?= (int) $ev['id'] ?>">Open Scorer</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<footer class="scorer-footer">
    <p>Scorer Interface by <strong>Developer Name</strong> &middot; Powered by Sci-Math Competition System</p>
</footer>

<?php require __DIR__ . '/../admin/includes/footer.php'; ?>
