<?php
// logout.php - ends the session and returns to the login page.
require_once __DIR__ . '/config/auth.php';
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
