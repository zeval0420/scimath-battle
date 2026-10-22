<?php
// configure/index.php - event configuration. Uses the shared header and footer.
// The page shell is static; configure.js loads and renders everything through api.php.
require_once __DIR__ . '/../config/auth.php';
require_login(false, 'operator');
require_once __DIR__ . '/../config/database.php'; // provides $conn (mysqli)

$eventId = (int)($_GET['event'] ?? 0);
$stmt = $conn->prepare('SELECT name FROM scimath_events WHERE id = ?');
$stmt->bind_param('i', $eventId);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
if (!$event) {
    http_response_code(404);
    exit('Event not found. <a href="../admin/index.php">Back to events</a>');
}
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$sections = [
    'details'     => 'Event details',
    'branding'   => 'Logo and cover slide',
    'audio'       => 'Audio',
    'rounds'      => 'Round defaults',
    'results'     => 'Question results screen',
    'slides'      => 'Slide classification',
    'questions'   => 'Questions',
    'promo'       => 'Promotional slides',
    'contestants' => 'Contestants',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" href="../assets/PSHSlogo.png">
  <title>Configure - <?= h($event['name']) ?></title>
  <link rel="stylesheet" href="../src/universal/components.css?v=<?= filemtime(__DIR__ . '/../src/universal/components.css') ?>">
  <link rel="stylesheet" href="../src/universal/HeadFoot.css?v=<?= filemtime(__DIR__ . '/../src/universal/HeadFoot.css') ?>">
  <link rel="stylesheet" href="configure.css?v=<?= filemtime(__DIR__ . '/configure.css') ?>">
</head>
<body class="app-body" data-event="<?= $eventId ?>">
<?php
$base = '../';
$header_nav = [
  ['All events', '../admin/index.php', false],
  ['Display',   '../display/index.php?event=' . $eventId, false],
  ['Operate',   '../operator/index.php?event=' . $eventId, false],
  ['Score',     '../scorer/index.php?event=' . $eventId, false],
  ['Configure', '../configure/index.php?event=' . $eventId, true],
];
include __DIR__ . '/../src/universal/header.php';
?>
<main class="page stack" id="main">

  <div class="row row-spread">
    <div>
      <a href="../admin/index.php">&larr; All events</a>
      <h1 class="page-title">Configure: <span id="event-title"><?= h($event['name']) ?></span></h1>
    </div>
    <span id="status" class="status" role="status"></span>
  </div>

  <?php foreach ($sections as $key => $title): ?>
  <section class="card" id="sec-<?= $key ?>">
    <div class="card-head">
      <h2 class="card-title"><?= h($title) ?></h2>
      <button class="btn btn-sm btn-ghost" type="button" data-toggle="sec-<?= $key ?>">Hide</button>
    </div>
    <div class="card-body stack" id="body-<?= $key ?>"><div class="empty">Loading...</div></div>
  </section>
  <?php endforeach; ?>

</main>
<?php include __DIR__ . '/../src/universal/footer.php'; ?>
<script src="configure.js?v=<?= filemtime(__DIR__ . '/configure.js') ?>"></script>
</body>
</html>
