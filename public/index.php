<?php
require_once __DIR__ . '/../src/bootstrap.php';

$events = Event::allOrderedByDate();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SciMath Competition System</title>
<link rel="stylesheet" href="assets/index.css">
</head>
<body>

<header class="hero">
    <div class="hero-inner">
        <div class="hero-institution">
            <div class="hero-logo">
                <img src="../../img/logo.png" alt="PSHS Logo" width="44" height="44">
            </div>
            <div class="hero-inst-text">
                <div class="line1">Department of Science and Technology</div>
                <div class="line2">Philippine Science High School &middot; Ilocos Region Campus</div>
            </div>
        </div>
        <span class="hero-eyebrow">Competition Management</span>
        <h1>SciMath Competition System</h1>
        <p>Configure competitions, run the live control panel, and broadcast results to the venue screen — all from one place.</p>
    </div>
</header>

<main class="page">
    <div class="feature-grid">
        <div class="feature-card">
            <h2>Admin Panel</h2>
            <p>Create and configure events — add categories, contestants, questions, and scoring settings.</p>
            <a class="btn btn-primary" href="admin/login.php">Open Admin Panel</a>
        </div>
        <div class="feature-card">
            <h2>Operator Console</h2>
            <p>Run a live competition — control the timer, reveal questions, and score contestants in real time.</p>
            <a class="btn btn-primary" href="operator/index.php">Open Operator Console</a>
        </div>
        <div class="feature-card">
            <h2>Display Screen</h2>
            <p>Broadcast questions and live standings to the venue projector — open a display below, no login required.</p>
            <span class="muted-hint">Pick an event below</span>
        </div>
        <div class="feature-card">
            <h2>Scorer Interface</h2>
            <p>Real-time scoreboard view for the official scorer — track points, rounds, and export results.</p>
            <a class="btn" href="scorer/index.php">Open Scorer</a>
        </div>
    </div>

    <h2 class="section-title">Current Events</h2>
    <p class="section-subtitle">Open the display for an event on the venue projector, operate it from your laptop, or configure it in the admin panel.</p>

    <?php if ($events === []): ?>
        <div class="empty-state">
            <p>No events yet. Create your first event to get started.</p>
            <a class="btn btn-primary" href="admin/index.php">Create an event</a>
        </div>
    <?php else: ?>
        <div class="event-grid">
        <?php foreach ($events as $ev): ?>
            <div class="event-card">
                <div class="event-card-name"><?= htmlspecialchars($ev['name']) ?></div>
                <?php if ($ev['subtitle']): ?><p class="event-card-subtitle"><?= htmlspecialchars($ev['subtitle']) ?></p><?php endif; ?>
                <div class="event-card-meta">
                    <span><?= $ev['event_date'] ? htmlspecialchars($ev['event_date']) : 'TBA' ?></span>
                    <span class="badge badge-<?= htmlspecialchars($ev['status']) ?>"><?= htmlspecialchars($ev['status']) ?></span>
                </div>
                <div class="event-card-actions">
                    <a class="btn" href="display/event.php?id=<?= (int) $ev['id'] ?>">Display</a>
                    <a class="btn" href="operator/event.php?id=<?= (int) $ev['id'] ?>">Operate</a>
                    <a class="btn" href="scorer/scores.php?id=<?= (int) $ev['id'] ?>">Scorer</a>
                    <a class="btn" href="admin/event.php?id=<?= (int) $ev['id'] ?>">Configure</a>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <p class="footer-note">Admin and operator access require a login. The display screen is public and needs no account.</p>
</main>

</body>
</html>
