<?php
// login.php - login page. No header or footer.
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
  <link rel="stylesheet" href="src/universal/components.css">
  <style>
    .login-wrap { min-height: 100vh; display: grid; place-items: center; padding: var(--pad); }
    .login-card { width: 100%; max-width: 380px; }
    .login-title { margin: 0; font-size: 26px; font-weight: 700; text-align: center; }
    .login-sub { margin: 0; text-align: center; color: var(--ink-soft); }
    .notice { padding: 10px var(--pad); border-radius: var(--radius); border: 1px solid var(--danger); background: var(--danger-soft); color: var(--danger); }
  </style>
</head>
<body>
<div class="login-wrap">
  <section class="card login-card">
    <div class="card-body stack">
      <h1 class="login-title">IRC QUEST</h1>
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
</div>
</body>
</html>
