<?php
// login.php - login page. Uses its own branded layout instead of the shared header and footer.
require_once __DIR__ . '/config/auth.php';

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Only allow redirecting back to a local path, never to another site.
function safe_next(string $n): string
{
    return ($n !== '' && $n[0] === '/' && !str_starts_with($n, '//') && !str_contains($n, chr(92))) ? $n : 'admin/index.php';
}

$next  = safe_next((string)($_GET['next'] ?? $_POST['next'] ?? ''));
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (attempt_login(trim($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''))) {
        header('Location: ' . $next);
        exit;
    }
    $error = 'Incorrect username or password.';
} elseif (is_logged_in()) {
    header('Location: ' . $next);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login - IRC QUEST</title>
  <link rel="stylesheet" href="src/universal/components.css?v=<?= filemtime(__DIR__ . '/src/universal/components.css') ?>">
  <link rel="stylesheet" href="src/universal/HeadFoot.css?v=<?= filemtime(__DIR__ . '/src/universal/HeadFoot.css') ?>">
  <style>
    body { background: linear-gradient(to right, #0e0054, #0036af, #0e0054); }
    .login-wrap { min-height: 100vh; display: grid; place-items: center; padding: 24px var(--pad); }
    .login-card { width: 100%; max-width: 440px; border: 0; box-shadow: 0 20px 50px rgba(0, 0, 0, 0.35); }
    .login-card .card-body { padding: 32px 28px; gap: 18px; }
    .login-logo { width: 96px; height: 96px; margin: 0 auto; object-fit: contain; }
    .login-title { margin: 0; font-size: 30px; font-weight: 800; letter-spacing: 0.06em; text-align: center; color: var(--navy); }
    .login-full { margin: -10px 0 0; text-align: center; color: var(--ink-soft); font-size: 14px; }
    .login-sub { margin: 0; text-align: center; font-weight: 600; }
    .notice { border-color: var(--danger); background: var(--danger-soft); color: var(--danger); font-weight: 600; }
    .login-foot { margin: 20px 0 0; text-align: center; color: #fff; font-size: 13px; opacity: 0.85; }
  </style>
</head>
<body>
<div class="login-wrap">
  <main id="main">
    <section class="card login-card" aria-labelledby="login-title">
      <div class="card-body stack">
        <img class="login-logo" src="assets/PSHSlogo.png" alt="Philippine Science High School - Ilocos Region Campus logo">
        <h1 class="login-title" id="login-title">IRC QUEST</h1>
        <p class="login-full">PSHS-IRC Quiz Utility for Events, Scoring, and Tournaments</p>
        <p class="login-sub">Sign in to continue</p>

        <?php if ($error): ?><div class="notice" role="alert"><?= h($error) ?></div><?php endif; ?>

        <form class="stack" method="post" autocomplete="on">
          <input type="hidden" name="next" value="<?= h($next) ?>">
          <div class="field">
            <label class="field-label" for="username">Account name</label>
            <input class="input" type="text" id="username" name="username" autocomplete="username"
                   value="<?= h($_POST['username'] ?? '') ?>" required autofocus>
          </div>
          <div class="field">
            <label class="field-label" for="password">Password</label>
            <input class="input" type="password" id="password" name="password" autocomplete="current-password" required>
          </div>
          <button class="btn btn-primary btn-lg btn-block" type="submit">Log in</button>
        </form>
      </div>
    </section>
    <p class="login-foot">&copy; 2026 Philippine Science High School - Ilocos Region Campus</p>
  </main>
</div>
</body>
</html>
