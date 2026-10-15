<?php
// display/index.php - presentation screen. No header or footer.
// Everything shown here is driven by the operator through operator/api.php (see display.js).
// Add &preview=1 to embed it in the operator page (no audio, no start gate).

$eventId = (int)($_GET['event'] ?? 0);
$preview = !empty($_GET['preview']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" href="../assets/PSHSlogo.png">
  <title>Display</title>
  <link rel="stylesheet" href="../src/universal/components.css?v=<?= filemtime(__DIR__ . '/../src/universal/components.css') ?>">
  <link rel="stylesheet" href="display.css?v=<?= filemtime(__DIR__ . '/display.css') ?>">
</head>
<body class="display-body" data-event="<?= $eventId ?>" data-preview="<?= $preview ? 1 : 0 ?>">

  <!-- One div is reused for every slide type: cover, question, answer, promotional, Boards Up slide. -->
  <div id="reusable-slide" class="slide-fullscreen"></div>

  <!-- Text-only screens (Boards Up text, cover without an image, messages) -->
  <div id="text-screen" class="text-screen" hidden></div>

  <!-- Countdown badge while the question is on screen -->
  <div id="timer-badge" class="timer-badge" hidden>00:00</div>

  <!-- Results overlay (question / round / final) -->
  <div id="overlay" class="overlay" hidden>
    <div class="overlay-top">
      <img class="overlay-logo" id="overlay-logo" src="" alt="" hidden>
      <h1 class="overlay-title" id="overlay-title"></h1>
    </div>
    <div class="overlay-bottom">
      <div class="score-list" id="score-list"></div>
    </div>
  </div>

  <?php if (!$preview): ?>
  <!-- Browsers block audio until the page is clicked once -->
  <div id="start-gate" class="start-gate"><button class="btn btn-primary btn-lg" type="button">Click to start display</button></div>
  <audio id="audio-banner" loop></audio>
  <audio id="audio-game" loop></audio>
  <audio id="audio-timer"></audio>
  <audio id="audio-correct"></audio>
  <?php endif; ?>

<script src="display.js?v=<?= filemtime(__DIR__ . '/display.js') ?>"></script>
</body>
</html>
