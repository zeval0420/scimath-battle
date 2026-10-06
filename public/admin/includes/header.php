<?php
/** @var string $pageTitle */
$pageTitle = $pageTitle ?? 'Admin';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> — Competition Admin</title>
<link rel="stylesheet" href="../assets/admin.css">
</head>
<body>
<header class="topbar">
    <div class="topbar-inner">
        <a href="../admin/index.php" class="brand">
            <img src="../../../img/logo.png" alt="PSHS Logo" width="28" height="28">
            Competition Admin
        </a>
        <?php if (Auth::check()): ?>
        <nav class="topnav">
            <a href="../admin/index.php">Events</a>
            <span class="topnav-user">Signed in as <?= htmlspecialchars((string) Auth::username()) ?></span>
            <a href="../admin/logout.php" class="topnav-logout">Log out</a>
        </nav>
        <?php endif; ?>
    </div>
</header>
<main class="page">
<?php foreach (Flash::consume() as $flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div>
<?php endforeach; ?>
