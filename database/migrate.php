<?php

/**
 * Minimal, dependency-free migration runner.
 *
 * Usage:
 *   php database/migrate.php            # apply all pending migrations
 *   php database/migrate.php --status   # list applied vs. pending
 *
 * Tracks applied migrations in a `schema_migrations` table so re-running
 * this script is always safe (idempotent) -- only new .sql files in
 * database/migrations/ get applied.
 */

require_once __DIR__ . '/../src/Database.php';

function ensureMigrationsTable(PDO $db): void
{
    $db->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS schema_migrations (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            migration   VARCHAR(255) NOT NULL,
            applied_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_schema_migrations_name (migration)
        ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4
    SQL);
}

function appliedMigrations(PDO $db): array
{
    $stmt = $db->query('SELECT migration FROM schema_migrations ORDER BY migration ASC');

    return array_column($stmt->fetchAll(), 'migration');
}

function pendingMigrationFiles(array $applied): array
{
    $files = glob(__DIR__ . '/migrations/*.sql');
    sort($files); // filenames are zero-padded/numbered, so lexical sort == execution order

    return array_filter($files, fn ($f) => !in_array(basename($f), $applied, true));
}

function runMigration(PDO $db, string $file): void
{
    $sql = file_get_contents($file);
    $name = basename($file);

    try {
        // By convention each migration file is a single CREATE TABLE
        // statement (comments allowed above it), executed as-is. Note: DDL
        // statements implicitly commit in MySQL/MariaDB, so this is
        // deliberately NOT wrapped in an explicit transaction -- BEGIN/COMMIT
        // around DDL would be misleading (it can't actually be rolled back).
        $db->exec($sql);

        $stmt = $db->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');
        $stmt->execute(['migration' => $name]);

        echo "  applied: {$name}\n";
    } catch (Throwable $e) {
        echo "  FAILED: {$name} -- {$e->getMessage()}\n";
        exit(1);
    }
}

$db = Database::connection();
ensureMigrationsTable($db);

$applied = appliedMigrations($db);

if (in_array('--status', $argv, true)) {
    $pending = pendingMigrationFiles($applied);
    echo "Applied migrations (" . count($applied) . "):\n";
    foreach ($applied as $m) {
        echo "  [x] {$m}\n";
    }
    echo "Pending migrations (" . count($pending) . "):\n";
    foreach ($pending as $f) {
        echo "  [ ] " . basename($f) . "\n";
    }
    exit(0);
}

$pending = pendingMigrationFiles($applied);

if ($pending === []) {
    echo "Nothing to migrate -- database is up to date.\n";
    exit(0);
}

echo "Running " . count($pending) . " pending migration(s)...\n";
foreach ($pending as $file) {
    runMigration($db, $file);
}
echo "Done.\n";
