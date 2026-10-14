<?php
// Shared header. Set these before including it:
//   $base       path from the current page to the project root ('' for root pages, '../' for subfolders)
//   $header_nav optional list of [label, href, isCurrent] links shown on the right, before "Log out"
$base       = $base ?? '../';
$header_nav = $header_nav ?? [];
?>
<a class="skip-link" href="#main">Skip to main content</a>
<header class="site-header background-gradient">
  <div class="site-header-inner">
    <a class="brand" href="<?= $base ?>admin/index.php" aria-label="IRC QUEST - all events">
      <img class="brand-logo" src="<?= $base ?>assets/PSHSlogo.png" alt="">
      <span class="brand-text">
        <span class="brand-name">IRC QUEST</span>
        <span class="brand-full">PSHS-IRC Quiz Utility for Events, Scoring, and Tournaments</span>
      </span>
    </a>
    <nav class="header-actions" aria-label="Main">
      <?php foreach ($header_nav as [$label, $href, $current]): ?>
        <a class="header-link" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"<?= $current ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
      <?php endforeach; ?>
      <a class="header-link" href="<?= $base ?>logout.php">Log out</a>
    </nav>
  </div>
</header>
