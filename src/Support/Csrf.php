<?php

/**
 * CSRF protection for admin forms. Every form-rendering page calls
 * Csrf::field() to embed a hidden token; every action script calls
 * Csrf::verify() before touching the database.
 */
class Csrf
{
    private const SESSION_KEY = 'csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }

    public static function verify(): void
    {
        if (!self::check()) {
            http_response_code(419);
            die('Security check failed (invalid or expired form token). Please go back and try again.');
        }
    }

    /**
     * Non-dying variant for JSON API endpoints, which need to return a JSON
     * error body (and the right HTTP status) rather than a plain-text die().
     */
    public static function check(): bool
    {
        $submitted = $_POST['csrf_token'] ?? '';
        $expected = $_SESSION[self::SESSION_KEY] ?? '';

        return $expected !== '' && hash_equals($expected, $submitted);
    }
}
