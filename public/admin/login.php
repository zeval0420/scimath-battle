<?php
require_once __DIR__ . '/../../src/bootstrap.php';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();

    $username = (string) ($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if (Auth::attempt($username, $password)) {
        $return = $_GET['return'] ?? relative_url('/admin/index.php');
        // Only ever redirect to a local path -- never follow an
        // absolute/external URL from the query string. Allow absolute
        // (host-rooted) paths, which is what Auth::requireAdmin passes.
        if (!is_string($return) || $return === ''
            || str_contains($return, '://')
            || str_starts_with($return, '//')
            || str_starts_with($return, '\\')) {
            $return = relative_url('/admin/index.php');
        }
        header('Location: ' . $return);
        exit;
    }

    $error = 'Incorrect username or password.';
}

if (Auth::check()) {
    header('Location: ' . relative_url('/admin/index.php'));
    exit;
}

$pageTitle = 'Sign in';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign in — Competition Admin</title>
<link rel="stylesheet" href="../assets/admin.css">
</head>
<body>
<div class="login-layout">

    <div class="login-visual">
        <div class="login-visual-bg"></div>
        <div class="login-visual-overlay">
            <h2>Competition Management</h2>
            <p>Configure events, manage categories and contestants, control the live competition, and broadcast results to the venue — all from one place.</p>
        </div>
    </div>

    <div class="login-card">
        <div class="card-header-band">
            <p class="system-label">Competition Admin</p>
            <h1>SciMath Competition</h1>
        </div>

        <div class="card-body">

            <div class="institution">
                <div class="logo-circle">
                    <img src="../../../img/logo.png" alt="PSHS Logo" width="44" height="44">
                </div>
                <div class="inst-text">
                    <p class="inst-line1">Department of Science and Technology</p>
                    <p class="inst-line2">Ilocos Region Campus</p>
                    <p class="inst-line3">Philippine Science High School</p>
                </div>
            </div>

            <hr class="divider">

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post">
                <?= Csrf::field() ?>
                <div class="field">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" placeholder="Enter your username" required autofocus>
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;height:46px;font-size:0.92rem;">Sign in</button>
            </form>
        </div>
    </div>

</div>
</body>
</html>
