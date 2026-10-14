<?php
// config/auth.php - session login. Single admin account (no users table in the schema).
const AUTH_USER = 'admin';
const AUTH_HASH = '$2y$10$c/SGrosRzTEa/1AK3jwpp.2TL2QSbm3LMBTrQhdIk/XTa7rmhBr2W'; // password_hash() of the password

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function is_logged_in(): bool
{
    return !empty($_SESSION['auth_user']);
}

function attempt_login(string $user, string $pass): bool
{
    // Always run password_verify so timing doesn't reveal whether the username exists.
    $okPass = password_verify($pass, AUTH_HASH);
    if (hash_equals(AUTH_USER, $user) && $okPass) {
        session_regenerate_id(true);
        $_SESSION['auth_user'] = AUTH_USER;
        return true;
    }
    return false;
}

/** Pages: redirect to the login page. JSON endpoints ($json = true): respond 401. */
function require_login(bool $json = false): void
{
    if (is_logged_in()) return;
    if ($json) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Not logged in.']);
    } else {
        header('Location: ' . dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/login.php?next=' . urlencode($_SERVER['REQUEST_URI']));
    }
    exit;
}
