<?php
// config/auth.php - session login. Single admin account (no users table in the schema).
const AUTH_USER = 'admin';
const AUTH_HASH = '$2y$10$RX0ZbPZ7T6rMqxnNkLBUwuc8dxNe.1C/OOCWZY6mC61DZuXh9X47i'; // password_hash() of the password

const AUTH_USER_OPERATOR = 'irc_quest';
const AUTH_HASH_OPERATOR = '$2y$10$tMeTRx2VgvNnBpNR4gU6k.hmfqNQZNKuww864dJLT0iN1A/kIWi2u'; // password_hash() of the password

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function is_logged_in(string $role = 'admin'): bool
{
    return !empty($_SESSION[$role === 'operator' ? 'auth_operator' : 'auth_user']);
}

/** Pages under operator/ and configure/ need the operator account; everything else needs the admin account. */
function role_for_path(string $path): string
{
    return preg_match('#/(operator|configure)(/|$)#', parse_url($path, PHP_URL_PATH) ?: '') ? 'operator' : 'admin';
}

function attempt_login(string $user, string $pass, string $role = 'admin'): bool
{
    $isOp = $role === 'operator';
    // Always run password_verify so timing doesn't reveal whether the username exists.
    $okPass = password_verify($pass, $isOp ? AUTH_HASH_OPERATOR : AUTH_HASH);
    $name   = $isOp ? AUTH_USER_OPERATOR : AUTH_USER;
    if (hash_equals($name, $user) && $okPass) {
        session_regenerate_id(true);
        $_SESSION[$isOp ? 'auth_operator' : 'auth_user'] = $name;
        return true;
    }
    return false;
}

/** Pages: redirect to the login page. JSON endpoints ($json = true): respond 401. */
function require_login(bool $json = false, string $role = 'admin'): void
{
    if (is_logged_in($role)) return;
    if ($json) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Not logged in.']);
    } else {
        header('Location: ' . dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/login.php?next=' . urlencode($_SERVER['REQUEST_URI']));
    }
    exit;
}
