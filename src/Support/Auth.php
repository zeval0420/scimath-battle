<?php

/**
 * Minimal session-based auth for the admin/operator interfaces.
 *
 * By design this is a single shared admin credential (see config/app.php),
 * not a full user-account system -- the spec calls for preventing
 * unauthorized access to admin/operator functions, not multi-user account
 * management. If real per-operator accounts are needed later, this class is
 * the single place that would change.
 */
class Auth
{
    private const SESSION_KEY = 'admin_authenticated';
    private const SESSION_USER_KEY = 'admin_username';

    public static function attempt(string $username, string $password): bool
    {
        $config = require __DIR__ . '/../../config/app.php';
        $admin = $config['admin'];

        $usernameMatches = hash_equals($admin['username'], $username);
        $passwordMatches = password_verify($password, $admin['password_hash']);

        if ($usernameMatches && $passwordMatches) {
            session_regenerate_id(true);
            $_SESSION[self::SESSION_KEY] = true;
            $_SESSION[self::SESSION_USER_KEY] = $username;

            return true;
        }

        return false;
    }

    public static function check(): bool
    {
        return $_SESSION[self::SESSION_KEY] ?? false;
    }

    public static function username(): ?string
    {
        return $_SESSION[self::SESSION_USER_KEY] ?? null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    /**
     * Call at the top of every protected admin page. Redirects to the login
     * screen (preserving where the user was headed) if not authenticated.
     */
    public static function requireAdmin(): void
    {
        if (!self::check()) {
            $return = $_SERVER['REQUEST_URI'] ?? relative_url('/admin/index.php');
            header('Location: ' . relative_url('/admin/login.php') . '?return=' . urlencode($return));
            exit;
        }
    }
}
