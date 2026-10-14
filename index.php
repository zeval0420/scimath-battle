<?php
// index.php - entry point: go to the admin page when logged in, otherwise to the login page.
require_once __DIR__ . '/config/auth.php';
header('Location: ' . (is_logged_in() ? 'admin/index.php' : 'login.php'));
exit;