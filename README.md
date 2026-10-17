# IRC QUEST

IRC QUEST (PSHS-IRC Quiz Utility for Events, Scoring, and Tournaments) runs a
quiz-bee style competition from start to finish. It was built for the Science
and Math quiz events at PSHS Ilocos Region Campus, first utilized on October 17, 2026 for the Regional Finals of Sci Math Battle.

The questions are slides (images). During the event, one person, the operator,
decides what the audience sees on the projector. Scorers record which teams got
each question right, and the tabulation and the ranking screens update from
those entries. Before the event, someone uploads the question slides, sets the
points and timer per difficulty, and enters the teams.

It is plain PHP and JavaScript with a MariaDB database. There are no
frameworks, no Composer packages, and no build step. Copy the folder to a PHP
server, point it at a database, and it runs.

## What is in the box

| Page | Folder | Who uses it | What it does |
|---|---|---|---|
| Events | `admin/` | Organizer | Create events, upload promotional slides, archive old events |
| Configure | `configure/` | Organizer | Event details, logo and cover, audio, round points and times, question slides, teams |
| Operate | `operator/` | Operator | Drives the projector: questions, timer, answers, results, clincher rounds |
| Display | `display/` | Projector | The audience screen. It has no controls and follows the operator |
| Score | `scorer/` | Scorers | Marks answers per team and shows the live tabulation. Exports CSV and PDF |

`config/` holds the shared setup (database, login, small schema helpers).
`src/universal/` holds the shared header, footer and stylesheets. Uploaded
files go to `uploads/events/<event id>/`.

---

## Part 1: Putting it on your own system

### Requirements

- PHP 8.1 or newer with `mysqli`, `mbstring`, `zip` (`ZipArchive`) and `fileinfo`
  enabled
- MariaDB 10.4+ or MySQL 8
- Any web server that can run PHP (Apache from XAMPP is what it was developed on)
- A PHP upload limit big enough for your slide decks. Raise `upload_max_filesize`
  and `post_max_size` in `php.ini` (50M or more is sensible for a zip of 100+
  slides), otherwise uploads fail with an "Check upload_max_filesize" message

### 1. Copy the files

Put the project folder inside your web root, for example
`C:\xampp\htdocs\irc_quest`, so it is served at `http://localhost/irc_quest/`.
Make sure the `uploads/` folder is writable by the web server user.

### 2. Create the database tables

The structure is in [assets/database_structure_for_context.sql](assets/database_structure_for_context.sql).
Create a database (or use one you already have) and import the file:

```
mysql -u root -p your_database < assets/database_structure_for_context.sql
```

or use the Import tab in phpMyAdmin. This creates nine tables. All of them use
the prefix `scimath_`, so they will not collide with anything you already have:

| Table | Holds |
|---|---|
| `scimath_events` | One row per event: name, subtitle, date, logo, cover, status |
| `scimath_event_settings` | Per-event defaults: points, time, audio, volumes, per-round values (JSON in `extra_settings`) |
| `scimath_categories` | Optional question categories |
| `scimath_questions` | One row per question slide: round, number, question image, answer image, points, time |
| `scimath_contestants` | Teams / schools for an event |
| `scimath_score_entries` | One row per team per question: the result and points awarded |
| `scimath_score_adjustments` | History of changes to a score, with who and why |
| `scimath_competition_sessions` | Current question, timer state, display state |
| `scimath_promotional_slides` | Non-question slides (sponsors, rules, Boards Up slides) |
| `scimath_answer_slides` | Answer slides not tied to a question row |

Three more tables are **not** in the SQL file because the code creates them the
first time they are needed, so there is nothing to import for them:
`scimath_screen_state` (what the projector is showing),
`scimath_clincher_entries` and `scimath_clincher_rounds` (tiebreakers). The
`correct_audio_path` column on `scimath_event_settings` is also added
automatically. This is why the database user needs `CREATE` and `ALTER`
privileges, at least for the first run.

If you want a different table prefix, search the project for `scimath_` and
replace it everywhere, including the SQL file, then re-import.

### 3. Connect to the database

Every page gets its database connection by including
[config/database.php](config/database.php). That file must leave a **mysqli**
connection in a variable called `$conn`. In this repository the file only
pulls in the connection from a larger site it was originally part of:

```php
<?php include('../../scilab/helperFiles/db_connection.php'); ?>
```

That path will not exist on your server, so replace the file's contents with
your own connection:

```php
<?php
// config/database.php
$conn = new mysqli('127.0.0.1', 'quest_user', 'a-strong-password', 'your_database');
if ($conn->connect_error) {
    http_response_code(500);
    exit('Database connection failed.');
}
$conn->set_charset('utf8mb4');
```

If your own system already has a connection file that defines `$conn`, you can
keep a one-line `include` like the original and just fix the path. Nothing else
in the project needs to know where the credentials live.

Create a dedicated MySQL user for the app with rights on the tables above
(`SELECT, INSERT, UPDATE, DELETE`, plus `CREATE` and `ALTER` for the
self-creating tables).

### 4. Log in

Open `http://localhost/irc_quest/`. You are sent to the login page. The
account that ships in the repository is a single hardcoded admin in
[config/auth.php](config/auth.php). Change it before you put this on any
network other than your own laptop (see the next section).

---

## Part 2: Accounts and authentication

### How login works now

All of the login logic is in [config/auth.php](config/auth.php), and it is
small. There is no users table. One username and one password hash are
constants at the top of the file:

```php
const AUTH_USER = 'admin';
const AUTH_HASH = '$2y$10$...';   // password_hash() of the password
```

A successful login stores `$_SESSION['auth_user']`. Every protected page calls
`require_login()` at the top; JSON endpoints call `require_login(true)`, which
answers `401` instead of redirecting. `logout.php` clears the session.

Everything else in the app only depends on three functions:

| Function | Used by | Contract |
|---|---|---|
| `is_logged_in(): bool` | `index.php`, `login.php` | true when a user is signed in |
| `attempt_login($user, $pass): bool` | `login.php` | verify credentials, start the session, return success |
| `require_login(bool $json = false)` | every page and API | stop the request unless signed in |

So to change how authentication works you only have to keep those three
functions honest. Nothing else needs editing.

### Option A: just change the admin password

Generate a new hash and paste it into `AUTH_HASH`:

```
php -r "echo password_hash('your new password', PASSWORD_DEFAULT);"
```

Change `AUTH_USER` too if you want a different account name.

### Option B: use an accounts table

Use this when there should be more than one organizer, or when your system
already has accounts you want to reuse.

**If you already have an accounts table**, skip the create statement and map
the column names in the query below to yours. All that matters is that you can
find a row by username and get a password hash that `password_verify()` accepts.

**If you do not**, create one:

```sql
CREATE TABLE accounts (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(60)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  display_name  VARCHAR(100) DEFAULT NULL,
  role          ENUM('admin','operator','scorer') NOT NULL DEFAULT 'admin',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    TIMESTAMP    NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Add the first account. Generate the hash with the PHP one-liner from Option A
and paste it in:

```sql
INSERT INTO accounts (username, password_hash, display_name)
VALUES ('admin', '$2y$10$...paste the hash here...', 'Administrator');
```

Then replace the constants and `attempt_login()` in `config/auth.php`. The
file needs the database now, so load the connection at the top:

```php
require_once __DIR__ . '/database.php';   // provides $conn

function attempt_login(string $user, string $pass): bool
{
    global $conn;
    $stmt = $conn->prepare(
        'SELECT id, username, password_hash, role FROM accounts WHERE username = ? AND is_active = 1'
    );
    $stmt->bind_param('s', $user);
    $stmt->execute();
    $acct = $stmt->get_result()->fetch_assoc();

    // Verify against a dummy hash when the user is unknown, so response time
    // does not reveal which usernames exist.
    $hash = $acct['password_hash'] ?? password_hash('x', PASSWORD_DEFAULT);
    if (password_verify($pass, $hash) && $acct) {
        session_regenerate_id(true);
        $_SESSION['auth_user'] = $acct['username'];
        $_SESSION['auth_id']   = (int)$acct['id'];
        $_SESSION['auth_role'] = $acct['role'];
        return true;
    }
    return false;
}
```

Keep `is_logged_in()`, `require_login()` and the session setup as they are.
They only look at `$_SESSION['auth_user']`.

A few notes on this:

- `login.php` labels its field "Account name", so no change is needed there.
- Two pages already ask for a name on their own: the Score page has a "Scorer
  name" box, and score changes record it in `scored_by` and `changed_by`. If
  you would rather have those come from the logged-in account, read
  `$_SESSION['auth_user']` where the scorer API writes them.
- Roles are stored but not enforced. If you want, for example, scorers who can
  open only the Score page, add a check after `require_login()` on the pages
  you want to restrict:
  ```php
  if (($_SESSION['auth_role'] ?? '') === 'scorer') { http_response_code(403); exit('Not allowed.'); }
  ```
- Add accounts the same way you added the first one, with `INSERT`. There is no
  sign-up page, which is intentional.

### Option C: log in through another system

If the app sits inside a site that already has a login (a school portal, for
example), replace `is_logged_in()` with a check of that site's session, and have
`require_login()` redirect to that site's login page. `login.php` and
`logout.php` can then be removed or pointed at the portal.

### Two things to know about access

- **The projector and its API are open.** `display/index.php` and
  `operator/api.php` do not call `require_login()`. The display needs to work
  on a machine that nobody signs in on, and the display reads its state
  through that API. This also means the operator controls (`operator/api.php`
  accepts changes) can be called by anyone who can reach the server and knows
  an event id. On a shared or public network, put the server behind your
  network's access rules, or add `require_login(true)` to the actions in
  `operator/api.php` that change state and keep `state` open.
- **Use HTTPS on a real server.** Login sessions are cookie based. The session
  cookie is already set `HttpOnly` and `SameSite=Lax`; add `'secure' => true`
  in `config/auth.php` once the site is served over HTTPS.

---

## Part 3: Using the system

### A typical event, in order

1. **Create the event** on the Events page (`admin/index.php`)
2. **Configure it** (questions, teams, points, audio)
3. **Test with Operate and Display** a day before
4. **Run it**: operator on one screen, scorers on their own devices, Display on
   the projector
5. **Export the tabulation** and archive the event

### 1. Create the event

On the Events page, open **Create event** and fill in the name, subtitle and
date. You can upload slides right away: either one `.zip` or several images
(`jpg`, `png`, `gif`, `webp`). They are added as promotional slides in file-name
order, so name them `001.png`, `002.png` and so on. Press **Create event**.

Each event card links to its Display, Operate, Score and Configure pages. Use
**Archive** to hide a finished event. It is only hidden, never deleted, and
you can restore it from the Archived events list at the bottom.

### 2. Configure the event

Open **Configure** for the event. It is a single page with collapsible
sections, and changes save as you make them.

- **Event details**: name, subtitle and date.
- **Logo and cover slide**: the logo shows on result screens, and the cover is
  what the audience sees before the event starts and whenever you show the banner.
- **Audio**: timer sound, banner music, in-game background music and the
  correct-answer sound, each with its own volume. Files can be mp3, wav, ogg,
  m4a, aac, flac and similar. A placeholder timer sound is used until you upload one.
- **Round defaults**: points and time for each round. The rounds are Easy,
  Average (`medium`), Difficult (`hard`) and Clincher. Every question
  uses its round's values unless you override it on that question.
- **Slide classification**: upload your question slides (a zip or several
  images) and say what they are. You can tell it to treat the files as all
  questions, all answers, or alternating question/answer pairs, which is the
  usual shape of an exported slide deck. Answer slides are matched to
  question slides by order (first answer to first question), so upload them
  in the same order.
- **Questions**: once uploaded, each question can be given a round, a number,
  points and time, moved up or down within its round. Leave points or time
  blank to use the round default. Use the bulk option to put a range of
  questions into one round.
- **Promotional slides**: sponsor slides, rules, anything shown outside the
  question flow. These are also what the operator can use as the Boards Up slide.
- **Contestants**: add teams one by one, reorder them, or import a CSV with
  `acronym,name` per row. A header row is accepted, teams already listed are
  skipped, and there is a template to start from in
  [assets/schools_template.csv](assets/schools_template.csv).

Question order is the order on screen, and the numbers must be unique within an
event.

### 3. Operate the event

Open **Operate** on the operator's computer, and **Open display** on the
projector's computer (or another browser window dragged to the projector).
Click once on the display page to start it; browsers will not play audio until
someone interacts with the page. Everything the operator does shows up on the
display within a moment, and a small live preview inside the Operate page shows
what the audience is seeing.

The normal loop for each question:

1. **Show question** puts the question slide on screen.
2. **Start timer** starts the countdown. Use **-5 sec / +5 sec**, **Pause** and
   **Reset** if needed.
3. When the timer ends, **Boards Up** appears by itself (see below).
4. **Show answer** reveals the answer slide.
5. **Next** moves to the next question.

Other controls on the page:

- **Show event on screen** switches between the live event and the cover
  banner. The banner is the quick way to put a cover up between rounds
  without losing your place.
- **Boards Up** is the "pens down" screen shown when time runs out. Choose
  text (default "Boards Up") or one of your promotional slides, then Save.
- **Results**: show question results, round results (pick the round), overall
  results, or final results. For overall and final you can limit it to the top
  3, 5, 10 or 15 teams. Use the scroll buttons to move through a long list on
  the projector, then **Hide results** to return.
- **Additional slides**: click a promotional slide to put it on screen, and
  **Back to question / answer** to return.
- **Clincher rounds**: only needed for ties. For each clincher question, choose
  the teams playing and mark the winner. Clincher questions carry no points; the
  winner is ranked above the teams they beat on tied totals.

The questions table on the Operate page lists every question, so you can jump
to any of them. Its fields can also be edited there (points, time) if you need
to change one during the event.

### 4. Score the event

Scorers open **Score** for the event, on any device on the same network. Several
scorers can have the page open at once; every click is saved right away and
each page refreshes itself, so everyone sees the same tabulation. The indicator
by the page title shows whether it is still connected.

1. Type your name in **Scorer name** (it is saved with each entry).
2. Pick the question, or follow the one the operator is on. **Prev / Next** step through them.
3. For each team card, mark the result. The points are filled in from the
   question's value, and you can correct a score afterwards.
4. The **Tabulation** below shows every team against every question, with totals and ranks.

**Reset this question** clears the marks for the current question.
**Reset all scores in event** clears everything and asks first. Every change
to an existing score is recorded in `scimath_score_adjustments` with the
previous and new result, who changed it and when.

**Export to sheets** downloads a CSV that opens in Google Sheets or Excel.
**Export PDF** opens a print-ready page; save it as PDF from the print dialog.

### 5. Afterwards

Export the tabulation, then archive the event from the Events page. Nothing is
deleted, so the scores, slides and uploads stay available if you restore it.

### Tips

- Run the whole flow once on the actual projector before the day. Check the
  audio, since browsers need that first click on the display.
- Keep `uploads/` in your backups. It holds every slide and sound as well as
  the database rows that point at them.
- Slide images shown on a projector look best at the same aspect ratio, usually 16:9.
- If a page says "Event not found", the link is missing its `?event=<id>` part.
  Open the page from the event card instead of typing the address.

## Project layout

```
admin/       events list, event creation and slide upload
configure/   event settings, questions, teams (page + api.php + js/css)
operator/    operator panel and the screen-state API the display reads
display/     projector screen
scorer/      scoring page, API, tabulation helpers and export
config/      auth.php, database.php, clincher.php, schema.php
src/         shared header, footer and styles
assets/      logo, placeholder timer audio, CSV template, SQL structure
uploads/     per-event files (created at runtime)
login.php  logout.php  index.php
```
