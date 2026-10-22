<?php
// admin/index.php - event creation and event cards. Uses the shared header and footer.
require_once __DIR__ . '/../config/auth.php';
require_login();
require_once __DIR__ . '/../config/database.php'; // provides $conn (mysqli)
require_once __DIR__ . '/event_create.php';

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_event'])) {
    // Archive / restore: archived events are hidden from the list below but nothing is deleted.
    $id = (int)$_POST['archive_event'];
    $to = ($_POST['archive_to'] ?? '') === 'restore' ? 'ready' : 'archived';
    $stmt = $conn->prepare("UPDATE scimath_events SET status = ? WHERE id = ?");
    $stmt->bind_param('si', $to, $id);
    $stmt->execute();
    header('Location: index.php?' . ($to === 'archived' ? 'archived=' : 'restored=') . $id);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = create_event($conn, $_POST, $_FILES['slides'] ?? null);
    if ($result['ok']) {
        header('Location: index.php?created=' . $result['id']);
        exit;
    }
    $errors = $result['errors'];
}

$events = [];
$res = $conn->query(
    "SELECT e.id, e.name, e.subtitle, e.event_date, e.status,
            (SELECT COUNT(*) FROM scimath_promotional_slides p WHERE p.event_id = e.id) AS slide_count
     FROM scimath_events e
     WHERE e.status <> 'archived'
     ORDER BY e.event_date DESC, e.id DESC"
);
if ($res) {
    $events = $res->fetch_all(MYSQLI_ASSOC);
}
$archived = [];
$res = $conn->query("SELECT id, name, event_date FROM scimath_events WHERE status = 'archived' ORDER BY event_date DESC, id DESC");
if ($res) {
    $archived = $res->fetch_all(MYSQLI_ASSOC);
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$createdId = (int)($_GET['created'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" href="../assets/PSHSlogo.png">
  <title>IRC QUEST Admin</title>
  <link rel="stylesheet" href="../src/universal/components.css?v=<?= filemtime(__DIR__ . '/../src/universal/components.css') ?>">
  <link rel="stylesheet" href="../src/universal/HeadFoot.css?v=<?= filemtime(__DIR__ . '/../src/universal/HeadFoot.css') ?>">
  <style>
    .notice { margin-bottom: var(--gap); }
    .notice-ok { background: var(--success-soft); color: var(--success); border-color: var(--success); }
    .notice-error { background: var(--danger-soft); color: var(--danger); border-color: var(--danger); }
    .notice ul { margin: 0; padding-left: 18px; }
    .badge { text-transform: uppercase; display: inline-block; padding: 1px 10px; border-radius: 999px; background: var(--accent-soft); color: var(--accent); font-size: 12px; font-weight: 700; }
  </style>
</head>
<body class="app-body">
<?php
$base = '../';
$header_nav = [];
include __DIR__ . '/../src/universal/header.php';
?>
<main class="page stack" id="main">

  <!-- ===== Event creation ===== -->
  <section class="card" id="sec-create">
    <div class="card-head">
      <h2 class="card-title">Create event</h2>
      <button class="btn btn-sm btn-ghost" type="button" data-toggle="sec-create">Hide</button>
    </div>
    <div class="card-body">
      <?php if ($createdId): ?>
        <div class="notice notice-ok">Event created.</div>
      <?php endif; ?>
      <?php if ($errors): ?>
        <div class="notice notice-error">
          <ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>
      <div class="notice notice-error" id="create-error" hidden></div>
      <form class="stack" method="post" enctype="multipart/form-data" id="create-form">

        <div class="field">
          <label class="field-label" for="name">Name</label>
          <input class="input" type="text" id="name" name="name" maxlength="150"
                 value="<?= h($_POST['name'] ?? '') ?>" required>
        </div>

        <div class="field">
          <label class="field-label" for="subtitle">Subtitle</label>
          <input class="input" type="text" id="subtitle" name="subtitle" maxlength="255"
                 value="<?= h($_POST['subtitle'] ?? '') ?>">
        </div>

        <div class="field">
          <label class="field-label" for="date">Date</label>
          <input class="input" type="date" id="date" name="date"
                 value="<?= h($_POST['date'] ?? '') ?>" required>
        </div>

        <div class="field">
          <label class="field-label" for="slides">Slides</label>
          <input class="file-input" type="file" id="slides" name="slides[]"
                 accept=".zip,image/*" multiple>
          <span class="field-hint">Upload one zip file or select multiple images.</span>
        </div>

        <div class="row">
          <button class="btn btn-primary" type="submit" id="create-btn">Create event</button>
        </div>

      </form>
    </div>
  </section>

  <!-- ===== Event cards ===== -->
  <section class="card" id="sec-events">
    <div class="card-head">
      <h2 class="card-title">Events</h2>
      <button class="btn btn-sm btn-ghost" type="button" data-toggle="sec-events">Hide</button>
    </div>
    <div class="card-body">

      <?php if (!$events): ?>
        <div class="empty">No events yet. Create one above.</div>
      <?php else: ?>
      <div class="card-grid">
        <?php foreach ($events as $ev): $id = (int)$ev['id']; ?>
        <article class="card">
          <div class="card-body stack">
            <div>
              <h3 class="card-title"><?= h($ev['name']) ?></h3>
              <?php if ($ev['subtitle'] !== null && $ev['subtitle'] !== ''): ?>
                <p class="card-subtitle"><?= h($ev['subtitle']) ?></p>
              <?php endif; ?>
            </div>
            <p class="card-meta">
              <?= $ev['event_date'] ? h(date('F j, Y', strtotime($ev['event_date']))) : 'No date' ?>
              &middot; <?= (int)$ev['slide_count'] ?> slide(s)
              &middot; <span class="badge"><?= h($ev['status']) ?></span>
            </p>
            <div class="card-actions">
              <a class="btn btn-primary" href="../display/index.php?event=<?= $id ?>">Display</a>
              <a class="btn btn-primary" href="../operator/index.php?event=<?= $id ?>">Operate</a>
              <a class="btn btn-primary" href="../scorer/index.php?event=<?= $id ?>">Score</a>
              <a class="btn" href="../configure/index.php?event=<?= $id ?>">Configure</a>
              <form method="post" class="confirm-form" style="display:inline">
                <input type="hidden" name="archive_event" value="<?= $id ?>">
                <button class="btn" type="submit" data-confirm="Click again to archive" title="Hides the event from this list; nothing is deleted.">Archive</button>
              </form>
            </div>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- ===== Archived events ===== -->
  <?php if ($archived): ?>
  <section class="card is-collapsed" id="sec-archived">
    <div class="card-head">
      <h2 class="card-title">Archived events (<?= count($archived) ?>)</h2>
      <button class="btn btn-sm btn-ghost" type="button" data-toggle="sec-archived">Show</button>
    </div>
    <div class="card-body stack">
      <?php foreach ($archived as $ev): ?>
      <form method="post" class="row row-spread">
        <span><b><?= h($ev['name']) ?></b> <span class="card-meta">&middot; <?= $ev['event_date'] ? h(date('F j, Y', strtotime($ev['event_date']))) : 'No date' ?></span></span>
        <input type="hidden" name="archive_event" value="<?= (int)$ev['id'] ?>">
        <input type="hidden" name="archive_to" value="restore">
        <button class="btn btn-sm" type="submit">Restore</button>
      </form>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

</main>
<?php include __DIR__ . '/../src/universal/footer.php'; ?>

<script src="create_event.js"></script>
<script>
  // Collapse / expand sections
  document.querySelectorAll('[data-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var card = document.getElementById(btn.dataset.toggle);
      var collapsed = card.classList.toggle('is-collapsed');
      btn.textContent = collapsed ? 'Show' : 'Hide';
    });
  });

  // Inline two-step confirmation instead of a browser popup: first click arms the button for 4 seconds.
  document.querySelectorAll('.confirm-form').forEach(function (form) {
    var btn = form.querySelector('[data-confirm]'), orig = btn.textContent, timer;
    form.addEventListener('submit', function (e) {
      if (btn.dataset.armed) return;
      e.preventDefault();
      btn.dataset.armed = '1';
      btn.textContent = btn.dataset.confirm;
      timer = setTimeout(function () { delete btn.dataset.armed; btn.textContent = orig; }, 4000);
    });
  });
</script>
</body>
</html>