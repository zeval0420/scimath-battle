<?php
// admin/index.php - event creation and event cards. Uses the shared header and footer.
require_once __DIR__ . '/../config/auth.php';
require_login();
require_once __DIR__ . '/../config/database.php'; // provides $conn (mysqli)
require_once __DIR__ . '/event_create.php';

$errors = [];
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
            </div>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </section>

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
</script>
</body>
</html>