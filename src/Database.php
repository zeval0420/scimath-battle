<?php

/**
 * Thin PDO singleton wrapper.
 *
 * Kept deliberately small: this is a data-access convenience, not an ORM.
 * All models share one connection, with exceptions enabled and native
 * prepared statements (no emulation) so parameter binding is safe by
 * default against SQL injection.
 */
class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance === null) {
            $config = require __DIR__ . '/../config/database.php';

            $dsn = sprintf(
                '%s:host=%s;port=%s;dbname=%s;charset=%s',
                $config['driver'],
                $config['host'],
                $config['port'],
                $config['database'],
                $config['charset']
            );

            self::$instance = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }

        return self::$instance;
    }

    /**
     * Useful for tests/seeders that need a clean connection (e.g. after
     * switching databases). Not used in normal request handling.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
