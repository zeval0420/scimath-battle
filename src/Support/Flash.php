<?php

/**
 * One-time "flash" messages stored in session, shown once after a redirect
 * (the standard POST -> redirect -> GET pattern), then cleared.
 */
class Flash
{
    private const SESSION_KEY = 'flash_messages';

    public static function add(string $type, string $message): void
    {
        $_SESSION[self::SESSION_KEY][] = ['type' => $type, 'message' => $message];
    }

    public static function success(string $message): void
    {
        self::add('success', $message);
    }

    public static function error(string $message): void
    {
        self::add('error', $message);
    }

    /** @return array<int, array{type: string, message: string}> */
    public static function consume(): array
    {
        $messages = $_SESSION[self::SESSION_KEY] ?? [];
        unset($_SESSION[self::SESSION_KEY]);

        return $messages;
    }
}
