# Academic Competition / Quiz Battle System — Database & Core Data Model

This is the database schema and PHP data-access layer for a reusable
competition engine (Stage 2 of the project). No UI is implemented yet — see
`IMPLEMENTATION_NOTES.md` for what's next.

Stack: **PHP 8.3 (plain, no framework) + PDO + MariaDB**, matching the
existing HTML/CSS/JS/PHP + MariaDB stack already in use for this project.

## Requirements

- PHP 8.1+ with the `pdo_mysql` extension
- MariaDB 10.6+ (or MySQL 8+)

## Local development setup

1. Create a database and a dedicated app user:

   ```sql
   CREATE DATABASE academic_competition CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'acs_user'@'localhost' IDENTIFIED BY 'dev_password_change_me';
   GRANT ALL PRIVILEGES ON academic_competition.* TO 'acs_user'@'localhost';
   FLUSH PRIVILEGES;
   ```

2. Configure connection settings via environment variables (or edit the
   fallback defaults in `config/database.php` directly for local dev):

   ```
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=academic_competition
   DB_USERNAME=acs_user
   DB_PASSWORD=dev_password_change_me
   ```

3. Run migrations:

   ```
   php database/migrate.php
   ```

   Check status any time with:

   ```
   php database/migrate.php --status
   ```

   Re-running `migrate.php` is always safe — only new files in
   `database/migrations/` get applied (tracked in a `schema_migrations`
   table).

4. Load sample development data (clearly fake — one demo event, 4
   categories, 3 contestants, 5 questions, and a scored question with one
   correction to demonstrate the audit trail):

   ```
   php database/seed.php
   ```

## Project structure

```
config/
  database.php     — DB connection settings (env-driven)
  constants.php     — EventStatus / AnswerResult / SessionPhase / RankingOrder enums
database/
  migrations/       — one .sql file per table, numbered in dependency order
  migrate.php       — migration runner (idempotent, tracks applied migrations)
  seeders/
    DevSeeder.php   — sample data generator
  seed.php          — seeder entry point
src/
  Database.php      — PDO singleton
  Models/
    Model.php           — shared CRUD base class
    Event.php
    EventSetting.php
    Category.php
    Contestant.php
    Question.php
    ScoreEntry.php       — recordResult() + rankingForEvent(), the scoring "engine"
    ScoreAdjustment.php  — append-only audit log
    CompetitionSession.php — timer/runtime state machine
public/
  uploads/            — placeholder dirs for logos/covers/questions/contestants
                         (upload handling itself is not built yet — Stage 3)
```

## Admin interface (Stage 3)

The admin interface lives under `public/admin/`. Point your web server's
document root at `public/` (or use PHP's built-in server for local dev):

```
php -S 127.0.0.1:8000 -t public
```

Then visit `http://127.0.0.1:8000/admin/login.php`.

**Default local dev credentials:** username `admin`, password `admin123`
(see `config/app.php` — set `ADMIN_USERNAME` / `ADMIN_PASSWORD_HASH` env
vars in any real deployment; generate a hash with
`php -r "echo password_hash('yourpassword', PASSWORD_BCRYPT);"`).

### What's there
- `public/admin/login.php` / `logout.php` — session-based auth gate
- `public/admin/index.php` — list events, create a new one
- `public/admin/event.php?id=N` — consolidated tabbed editor: Overview &
  Branding, Categories, Contestants, Questions, Scoring/Timing/Presentation
- `public/admin/actions/*.php` — POST-only handlers (create/update/delete/
  reorder) for each entity, each CSRF-protected and redirecting back to
  `event.php` with a flash message

### Uploads
Handled by `src/Support/Uploader.php`: validates real MIME type (not the
client-supplied one), size, and that the file actually decodes as an image,
then **re-encodes it via GD** (stripping any non-pixel payload) and writes
it under a randomly generated filename — the original filename and bytes
are never trusted or stored as-is. See that file's docblock for the full
defense-in-depth breakdown.

## Verifying the install


A quick smoke test after migrating + seeding:

```php
<?php
require_once 'src/Models/Event.php';
require_once 'src/Models/ScoreEntry.php';

$event = Event::withRelations(1); // the seeded sample event
print_r(ScoreEntry::rankingForEvent(1));
```

This should print a ranked list of the 3 seeded teams.
