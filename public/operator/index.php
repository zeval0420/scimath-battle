<?php
require_once __DIR__ . '/../../src/bootstrap.php';
Auth::requireAdmin();

$events = array_values(array_filter(
    Event::allOrderedByDate(),
    fn ($e) => in_array($e['status'], [EventStatus::READY, EventStatus::ACTIVE, EventStatus::COMPLETED], true)
));

$pageTitle = 'Operator';
require __DIR__ . '/../admin/includes/header.php';
?>
<h1>Run a competition</h1>
<p class="subtitle">Choose an event to open its live control panel. Only events marked Ready, Active, or Completed are shown here — configure an event in the admin panel first.</p>

<div class="card">
    <?php if ($events === []): ?>
        <p class="muted">No events are ready to run yet. <a href="../admin/index.php">Go to the admin panel</a> to configure one.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Name</th><th>Date</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($events as $ev): ?>
                <tr>
                    <td><?= htmlspecialchars($ev['name']) ?></td>
                    <td><?= $ev['event_date'] ? htmlspecialchars($ev['event_date']) : '<span class="muted">—</span>' ?></td>
                    <td><span class="badge badge-<?= htmlspecialchars($ev['status']) ?>"><?= htmlspecialchars($ev['status']) ?></span></td>
                    <td><a class="btn btn-primary btn-small" href="event.php?id=<?= (int) $ev['id'] ?>">Open control panel</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../admin/includes/footer.php'; ?>
