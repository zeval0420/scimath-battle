<?php
require_once __DIR__ . '/../../src/bootstrap.php';

Auth::logout();
header('Location: ' . relative_url('/admin/login.php'));
exit;
