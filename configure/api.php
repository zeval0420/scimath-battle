<?php
// configure/api.php - JSON endpoint for the configuration page.
// POST: action, event, payload (JSON string), optional file. Always responds with {state} or {error}.
require_once __DIR__ . '/../config/auth.php';
require_login(true, 'operator');
require_once __DIR__ . '/../config/database.php'; // provides $conn (mysqli)
require_once __DIR__ . '/../config/schema.php';
require_once __DIR__ . '/../config/api_helpers.php'; // UserError, run(), rows(), one(), val(), out()
require_once __DIR__ . '/../admin/event_create.php'; // normalize_uploads(), save_slides()

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

const ROOT = __DIR__ . '/..';
const DEFAULT_TIMER_AUDIO = 'assets/placeholder_timer_audio.mp3';
const ROUNDS = ['easy', 'medium', 'hard', 'clincher'];
const IMG_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const AUDIO_EXTS = ['mp3', 'wav', 'ogg', 'oga', 'opus', 'm4a', 'aac', 'flac', 'weba', 'webm', 'mp4'];
const ASSETS = [
    'logo'         => ['scimath_events', 'id', 'logo_path', 'image'],
    'cover'        => ['scimath_events', 'id', 'cover_image_path', 'image'],
    'timer_audio'  => ['scimath_event_settings', 'event_id', 'timer_audio_path', 'audio'],
    'banner_audio' => ['scimath_event_settings', 'event_id', 'banner_audio_path', 'audio'],
    'game_audio'   => ['scimath_event_settings', 'event_id', 'game_audio_path', 'audio'],
    'correct_audio' => ['scimath_event_settings', 'event_id', 'correct_audio_path', 'audio'],
];

/** Positive/zero int or null (blank) from user input, range-checked. */
function opt_int($v, int $min, int $max, string $label): ?int
{
    if ($v === null || $v === '') return null;
    if (!is_numeric($v) || (int)$v != $v || $v < $min || $v > $max) {
        throw new UserError("$label must be a whole number from $min to $max.");
    }
    return (int)$v;
}

// ---------------------------------------------------------------- state

function load_state(int $eid): array
{
    run('INSERT IGNORE INTO scimath_event_settings (event_id) VALUES (?)', [$eid]);
    $event = one('SELECT id, name, subtitle, event_date, logo_path, cover_image_path FROM scimath_events WHERE id = ?', [$eid]);
    if (!$event) throw new UserError('Event not found.');
    $st = one('SELECT * FROM scimath_event_settings WHERE event_id = ?', [$eid]);

    $extra = json_decode($st['extra_settings'] ?? '', true) ?: [];
    $rounds = [];
    foreach (ROUNDS as $r) {
        $rounds[$r] = [
            'points' => (int)($extra['rounds'][$r]['points'] ?? $st['default_points']),
            'time'   => (int)($extra['rounds'][$r]['time'] ?? $st['default_time_seconds']),
        ];
    }

    $slides = [];
    foreach (rows('SELECT id, title, file_path AS path, display_order, is_active FROM scimath_promotional_slides WHERE event_id = ?', [$eid]) as $r) {
        $slides[] = $r + ['kind' => 'promo'];
    }
    foreach (rows('SELECT q.id, q.image_path AS path, q.display_order,
                         (SELECT COUNT(*) FROM scimath_score_entries s WHERE s.question_id = q.id) AS score_count
                         FROM scimath_questions q WHERE q.event_id = ?', [$eid]) as $r) {
        $slides[] = $r + ['kind' => 'question'];
    }
    foreach (rows('SELECT id, image_path AS path, display_order FROM scimath_answer_slides WHERE event_id = ?', [$eid]) as $r) {
        $slides[] = $r + ['kind' => 'answer'];
    }
    usort($slides, fn($a, $b) => [$a['display_order'], $a['kind'], $a['id']] <=> [$b['display_order'], $b['kind'], $b['id']]);

    return [
        'event' => $event,
        'settings' => [
            'timer_audio_path'  => $st['timer_audio_path'],
            'default_timer_audio' => DEFAULT_TIMER_AUDIO,
            'banner_audio_path' => $st['banner_audio_path'],
            'game_audio_path'   => $st['game_audio_path'],
            'correct_audio_path' => $st['correct_audio_path'] ?? null,
            'banner_audio_volume' => (int)$st['banner_audio_volume'],
            'game_audio_volume'   => (int)$st['game_audio_volume'],
            'rounds' => $rounds,
            'results_grid' => [
                'cols' => (int)($extra['results_grid']['cols'] ?? 8),
                'rows' => (int)($extra['results_grid']['rows'] ?? 5),
            ],
        ],
        'slides' => $slides,
        'questions' => rows('SELECT id, question_number AS number, round, image_path, answer_image_path, points, time_seconds
                             FROM scimath_questions WHERE event_id = ? ORDER BY display_order, id', [$eid]),
        'contestants' => rows('SELECT c.id, c.name, c.acronym,
                               (SELECT COUNT(*) FROM scimath_score_entries s WHERE s.contestant_id = c.id) AS score_count
                               FROM scimath_contestants c WHERE c.event_id = ? ORDER BY c.display_order, c.id', [$eid]),
    ];
}

// ---------------------------------------------------------------- files

function delete_event_file(int $eid, ?string $path): void
{
    if (!$path || strpos($path, "uploads/events/$eid/") !== 0 || strpos($path, '..') !== false) return;
    $abs = ROOT . '/' . $path;
    if (is_file($abs)) @unlink($abs);
}

function set_asset(int $eid, string $key, ?string $path): void
{
    [$table, $idCol, $col] = ASSETS[$key];
    $old = val("SELECT $col FROM $table WHERE $idCol = ?", [$eid]);
    run("UPDATE $table SET $col = ? WHERE $idCol = ?", [$path, $eid]);
    if ($old !== $path) delete_event_file($eid, $old);
}

function upload_asset(int $eid, string $key): void
{
    if (!isset(ASSETS[$key])) throw new UserError('Unknown asset.');
    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        throw new UserError('Upload failed (code ' . ($f['error'] ?? '?') . '). Check upload_max_filesize / post_max_size.');
    }
    $type = ASSETS[$key][3];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $type === 'image' ? IMG_EXTS : AUDIO_EXTS, true)) {
        throw new UserError('Unsupported file type. Allowed: ' . implode(', ', $type === 'image' ? IMG_EXTS : AUDIO_EXTS) . '.');
    }
    if ($type === 'image' && @getimagesize($f['tmp_name']) === false) throw new UserError('That file is not a valid image.');

    $rel = "uploads/events/$eid/assets";
    if (!is_dir(ROOT . "/$rel") && !mkdir(ROOT . "/$rel", 0775, true)) throw new UserError('Could not create the upload folder.');
    $name = "{$key}_" . time() . ".$ext";
    if (!move_uploaded_file($f['tmp_name'], ROOT . "/$rel/$name")) throw new UserError('Could not save the file.');
    set_asset($eid, $key, "$rel/$name");
}

// ---------------------------------------------------------------- slides / questions

function renumber_questions(int $eid): void
{
    $ids = array_column(rows('SELECT id FROM scimath_questions WHERE event_id = ? ORDER BY display_order, id', [$eid]), 'id');
    foreach ($ids as $i => $id) run('UPDATE scimath_questions SET question_number = ? WHERE id = ?', [30000 + $i, $id]); // dodge unique key
    foreach ($ids as $i => $id) run('UPDATE scimath_questions SET question_number = ? WHERE id = ?', [$i + 1, $id]);
}

/** nth answer slide goes with nth question slide, in order of appearance. */
function sync_answers(int $eid): void
{
    run('UPDATE scimath_answer_slides SET question_id = NULL WHERE event_id = ?', [$eid]);
    run('UPDATE scimath_questions SET answer_image_path = NULL WHERE event_id = ?', [$eid]);
    $qs = rows('SELECT id FROM scimath_questions WHERE event_id = ? ORDER BY display_order, id', [$eid]);
    $as = rows('SELECT id, image_path FROM scimath_answer_slides WHERE event_id = ? ORDER BY display_order, id', [$eid]);
    foreach ($qs as $i => $q) {
        if (!isset($as[$i])) break;
        run('UPDATE scimath_answer_slides SET question_id = ? WHERE id = ?', [$q['id'], $as[$i]['id']]);
        run('UPDATE scimath_questions SET answer_image_path = ? WHERE id = ?', [$as[$i]['image_path'], $q['id']]);
    }
}

function classify(int $eid, array $items): void
{
    $src = [
        'promo'    => ['scimath_promotional_slides', 'file_path'],
        'question' => ['scimath_questions', 'image_path'],
        'answer'   => ['scimath_answer_slides', 'image_path'],
    ];
    foreach ($items as $it) {
        $from = $it['kind'] ?? ''; $to = $it['to'] ?? ''; $id = (int)($it['id'] ?? 0);
        if (!isset($src[$from], $src[$to])) throw new UserError('Invalid slide type.');
        if ($from === $to) continue;
        [$table, $col] = $src[$from];
        $row = one("SELECT * FROM $table WHERE id = ? AND event_id = ?", [$id, $eid]);
        if (!$row) throw new UserError('Slide not found.');
        if ($from === 'question' && val('SELECT COUNT(*) FROM scimath_score_entries WHERE question_id = ?', [$id]) > 0) {
            throw new UserError('Question ' . $row['question_number'] . ' already has scores and cannot be reclassified.');
        }
        $path = $row[$col]; $order = (int)$row['display_order'];

        if ($to === 'promo') {
            run("INSERT INTO scimath_promotional_slides (event_id, title, file_path, file_type, display_order) VALUES (?, ?, ?, 'image', ?)",
                [$eid, pathinfo($path, PATHINFO_FILENAME), $path, $order]);
        } elseif ($to === 'question') {
            $n = (int)val('SELECT COALESCE(MAX(question_number), 0) + 1 FROM scimath_questions WHERE event_id = ?', [$eid]);
            run("INSERT INTO scimath_questions (event_id, round, question_number, image_path, display_order) VALUES (?, 'easy', ?, ?, ?)",
                [$eid, $n, $path, $order]);
        } else {
            run('INSERT INTO scimath_answer_slides (event_id, image_path, display_order) VALUES (?, ?, ?)', [$eid, $path, $order]);
        }
        run("DELETE FROM $table WHERE id = ?", [$id]);
    }
    renumber_questions($eid);
    sync_answers($eid);
}

/** Next free display_order across promo, question and answer slides. */
function next_slide_order(int $eid): int
{
    $max = 0;
    foreach (['scimath_promotional_slides', 'scimath_questions', 'scimath_answer_slides'] as $t) {
        $max = max($max, (int)val("SELECT COALESCE(MAX(display_order), 0) FROM $t WHERE event_id = ?", [$eid]));
    }
    return $max + 1;
}

/** Renumbers every slide's display_order 1..N, keeping the order shown on the page. */
function normalize_order(int $eid): void
{
    $all = [];
    foreach (['promo' => 'scimath_promotional_slides', 'question' => 'scimath_questions', 'answer' => 'scimath_answer_slides'] as $kind => $t) {
        foreach (rows("SELECT id, display_order FROM $t WHERE event_id = ?", [$eid]) as $r) $all[] = [(int)$r['display_order'], $kind, (int)$r['id'], $t];
    }
    usort($all, fn($a, $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
    foreach ($all as $i => [$o, $kind, $id, $t]) {
        if ($o !== $i + 1) run("UPDATE $t SET display_order = ? WHERE id = ?", [$i + 1, $id]);
    }
}

/** Appends uploaded images / a zip to an existing event. $as: promo | question | answer | alternate (question, answer, ...). */
function add_slides(int $eid, string $as, string $round): void
{
    if (!in_array($as, ['promo', 'question', 'answer', 'alternate'], true)) throw new UserError('Choose what to add the slides as.');
    if (!in_array($round, ROUNDS, true)) throw new UserError('Invalid round.');
    $errors = [];
    $uploads = normalize_uploads($_FILES['files'] ?? null, $errors);
    if ($errors) throw new UserError(implode(' ', $errors));
    if (!$uploads) throw new UserError('Choose a .zip or some images to add.');

    $rel = "uploads/events/$eid/slides";
    if (!is_dir(ROOT . "/$rel") && !mkdir(ROOT . "/$rel", 0775, true)) throw new UserError('Could not create the upload folder.');
    $saved = [];
    try {
        try {
            $saved = save_slides($uploads, ROOT . "/$rel", 'add_' . time() . '_' . bin2hex(random_bytes(2)) . '_');
        } catch (RuntimeException $e) {
            throw new UserError($e->getMessage());
        }
        if (!$saved) throw new UserError('No valid images were found in the upload.');
        $have = (int)val('SELECT (SELECT COUNT(*) FROM scimath_promotional_slides WHERE event_id = ?)
                               + (SELECT COUNT(*) FROM scimath_questions WHERE event_id = ?)
                               + (SELECT COUNT(*) FROM scimath_answer_slides WHERE event_id = ?)', [$eid, $eid, $eid]);
        if ($have + count($saved) > MAX_SLIDES) throw new UserError('Too many slides (max ' . MAX_SLIDES . ' per event).');

        $order = next_slide_order($eid);
        $n = (int)val('SELECT COALESCE(MAX(question_number), 0) FROM scimath_questions WHERE event_id = ?', [$eid]);
        foreach ($saved as $i => $name) {
            $path = "$rel/$name";
            $kind = $as === 'alternate' ? ($i % 2 === 0 ? 'question' : 'answer') : $as;
            if ($kind === 'promo') {
                run("INSERT INTO scimath_promotional_slides (event_id, title, file_path, file_type, display_order) VALUES (?, ?, ?, 'image', ?)",
                    [$eid, pathinfo($name, PATHINFO_FILENAME), $path, $order++]);
            } elseif ($kind === 'question') {
                run('INSERT INTO scimath_questions (event_id, round, question_number, image_path, display_order) VALUES (?, ?, ?, ?, ?)',
                    [$eid, $round, ++$n, $path, $order++]);
            } else {
                run('INSERT INTO scimath_answer_slides (event_id, image_path, display_order) VALUES (?, ?, ?)', [$eid, $path, $order++]);
            }
        }
        renumber_questions($eid);
        sync_answers($eid);
    } catch (Throwable $e) {
        foreach ($saved as $name) delete_event_file($eid, "$rel/$name");
        throw $e;
    }
}

/** Moves a question one place up/down among the questions of its own round; its answer slide travels with it. */
function move_question(int $eid, int $id, string $dir): void
{
    normalize_order($eid);
    $qs = rows('SELECT id, round, display_order FROM scimath_questions WHERE event_id = ? ORDER BY display_order, id', [$eid]);
    $i = null;
    foreach ($qs as $k => $q) if ((int)$q['id'] === $id) $i = $k;
    if ($i === null) throw new UserError('Question not found.');
    $step = $dir === 'up' ? -1 : 1;
    for ($j = $i + $step; isset($qs[$j]) && $qs[$j]['round'] !== $qs[$i]['round']; $j += $step);
    if (!isset($qs[$j])) return; // already first/last in its round

    $ans = [];
    foreach (rows('SELECT id, question_id, display_order FROM scimath_answer_slides WHERE event_id = ? AND question_id IN (?, ?)',
                  [$eid, $qs[$i]['id'], $qs[$j]['id']]) as $a) $ans[(int)$a['question_id']] = $a;
    run('UPDATE scimath_questions SET display_order = ? WHERE id = ?', [$qs[$j]['display_order'], $qs[$i]['id']]);
    run('UPDATE scimath_questions SET display_order = ? WHERE id = ?', [$qs[$i]['display_order'], $qs[$j]['id']]);
    $ai = $ans[(int)$qs[$i]['id']] ?? null; $aj = $ans[(int)$qs[$j]['id']] ?? null;
    if ($ai && $aj) {
        run('UPDATE scimath_answer_slides SET display_order = ? WHERE id = ?', [$aj['display_order'], $ai['id']]);
        run('UPDATE scimath_answer_slides SET display_order = ? WHERE id = ?', [$ai['display_order'], $aj['id']]);
    }
    renumber_questions($eid);
    sync_answers($eid);
}

/** Deletes slides (and their files). $only = one [kind, id], or null for every slide of the event. */
function delete_slides(int $eid, ?array $only, bool $force): void
{
    $src = [
        'promo'    => ['scimath_promotional_slides', 'file_path'],
        'question' => ['scimath_questions', 'image_path'],
        'answer'   => ['scimath_answer_slides', 'image_path'],
    ];
    $targets = $only ? [$only[0] => [$only[1]]] : null;
    foreach ($src as $kind => [$table, $col]) {
        if ($targets && !isset($targets[$kind])) continue;
        $where = $targets ? ' AND id = ' . (int)$targets[$kind][0] : '';
        $found = rows("SELECT id, $col AS path FROM $table WHERE event_id = ?$where", [$eid]);
        if ($only && !$found) throw new UserError('Slide not found.');
        foreach ($found as $r) {
            if ($kind === 'question' && !$force
                && val('SELECT COUNT(*) FROM scimath_score_entries WHERE question_id = ?', [$r['id']]) > 0) {
                throw new UserError('A question slide already has scores. Confirm to delete it together with its scores.');
            }
            $paths[] = $r['path'];
            run("DELETE FROM $table WHERE id = ?", [$r['id']]); // scores and clincher picks cascade
        }
    }
    foreach ($paths ?? [] as $path) delete_event_file($eid, $path);
    renumber_questions($eid);
    sync_answers($eid);
}

// ---------------------------------------------------------------- dispatch

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new UserError('POST required.');
    $eid = (int)($_POST['event'] ?? 0);
    $action = $_POST['action'] ?? '';
    $p = json_decode($_POST['payload'] ?? '{}', true) ?: [];
    if (!val('SELECT id FROM scimath_events WHERE id = ?', [$eid])) throw new UserError('Event not found.');
    run('INSERT IGNORE INTO scimath_event_settings (event_id) VALUES (?)', [$eid]);

    ensure_correct_audio_column($conn);
    // The acronym column used to be VARCHAR(20); widen it once (DDL commits, so do it before the transaction).
    $len = (int)val("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scimath_contestants' AND COLUMN_NAME = 'acronym'");
    if ($len > 0 && $len < 255) run('ALTER TABLE scimath_contestants MODIFY acronym VARCHAR(255) NULL DEFAULT NULL');
    $conn->begin_transaction();
    switch ($action) {
        case 'load':
            break;

        case 'save_details':
            $name = trim((string)($p['name'] ?? '')); $sub = trim((string)($p['subtitle'] ?? '')); $date = trim((string)($p['event_date'] ?? ''));
            if ($name === '' || mb_strlen($name) > 150) throw new UserError('Event name is required (max 150 characters).');
            if (mb_strlen($sub) > 255) throw new UserError('Subtitle is too long (max 255 characters).');
            if ($date !== '') {
                $d = DateTime::createFromFormat('Y-m-d', $date);
                if (!$d || $d->format('Y-m-d') !== $date) throw new UserError('Enter a valid date.');
            }
            run('UPDATE scimath_events SET name = ?, subtitle = ?, event_date = ? WHERE id = ?',
                [$name, $sub === '' ? null : $sub, $date === '' ? null : $date, $eid]);
            break;

        case 'upload_asset':
            upload_asset($eid, (string)($p['key'] ?? ''));
            break;

        case 'clear_asset': // for timer_audio this reverts to the default placeholder
            if (!isset(ASSETS[$p['key'] ?? ''])) throw new UserError('Unknown asset.');
            set_asset($eid, $p['key'], null);
            break;

        case 'save_volumes':
            $b = opt_int($p['banner'] ?? null, 0, 100, 'Banner volume');
            $g = opt_int($p['game'] ?? null, 0, 100, 'Background volume');
            run('UPDATE scimath_event_settings SET banner_audio_volume = COALESCE(?, banner_audio_volume),
                 game_audio_volume = COALESCE(?, game_audio_volume) WHERE event_id = ?', [$b, $g, $eid]);
            break;

        case 'save_round_defaults':
            $st = one('SELECT extra_settings FROM scimath_event_settings WHERE event_id = ?', [$eid]);
            $extra = json_decode($st['extra_settings'] ?? '', true) ?: [];
            $old = one('SELECT default_points, default_time_seconds FROM scimath_event_settings WHERE event_id = ?', [$eid]);
            foreach (ROUNDS as $r) {
                $pts = opt_int($p['rounds'][$r]['points'] ?? null, 0, 100000, 'Points');
                $time = opt_int($p['rounds'][$r]['time'] ?? null, 1, 65535, 'Time limit');
                if ($pts === null || $time === null) throw new UserError('Points and time are required for every round.');
                $oldPts = (int)($extra['rounds'][$r]['points'] ?? $old['default_points']);
                $oldTime = (int)($extra['rounds'][$r]['time'] ?? $old['default_time_seconds']);
                // A changed round default applies to every question in that round (drops their per-question overrides).
                if ($pts !== $oldPts) run('UPDATE scimath_questions SET points = NULL WHERE event_id = ? AND round = ?', [$eid, $r]);
                if ($time !== $oldTime) run('UPDATE scimath_questions SET time_seconds = NULL WHERE event_id = ? AND round = ?', [$eid, $r]);
                $extra['rounds'][$r] = ['points' => $pts, 'time' => $time];
            }
            run('UPDATE scimath_event_settings SET extra_settings = ? WHERE event_id = ?', [json_encode($extra), $eid]);
            break;

        case 'save_results_grid':
            $cols = opt_int($p['cols'] ?? null, 1, 20, 'Columns');
            $rws = opt_int($p['rows'] ?? null, 1, 20, 'Rows');
            if ($cols === null || $rws === null) throw new UserError('Columns and rows are required.');
            $st = one('SELECT extra_settings FROM scimath_event_settings WHERE event_id = ?', [$eid]);
            $extra = json_decode($st['extra_settings'] ?? '', true) ?: [];
            $extra['results_grid'] = ['cols' => $cols, 'rows' => $rws];
            run('UPDATE scimath_event_settings SET extra_settings = ? WHERE event_id = ?', [json_encode($extra), $eid]);
            break;

        case 'classify':
            classify($eid, $p['items'] ?? []);
            break;

        case 'save_question':
            if (!in_array($p['round'] ?? '', ROUNDS, true)) throw new UserError('Invalid round.');
            $pts = opt_int($p['points'] ?? null, 0, 100000, 'Points');
            $time = opt_int($p['time'] ?? null, 1, 65535, 'Time limit');
            // Moving a question to another round makes it follow that round's defaults.
            run('UPDATE scimath_questions SET points = IF(round = ?, ?, NULL), time_seconds = IF(round = ?, ?, NULL), round = ?
                 WHERE id = ? AND event_id = ?',
                [$p['round'], $pts, $p['round'], $time, $p['round'], (int)($p['id'] ?? 0), $eid]);
            break;

        case 'move_question':
            move_question($eid, (int)($p['id'] ?? 0), ($p['dir'] ?? '') === 'up' ? 'up' : 'down');
            break;

        case 'add_slides':
            add_slides($eid, (string)($p['as'] ?? ''), (string)($p['round'] ?? 'easy'));
            break;

        case 'bulk_round':
            if (!in_array($p['round'] ?? '', ROUNDS, true)) throw new UserError('Invalid round.');
            $from = opt_int($p['from'] ?? null, 1, 65535, 'From'); $to = opt_int($p['to'] ?? null, 1, 65535, 'To');
            if ($from === null || $to === null || $from > $to) throw new UserError('Enter a valid question range.');
            run('UPDATE scimath_questions SET points = IF(round = ?, points, NULL), time_seconds = IF(round = ?, time_seconds, NULL), round = ?
                 WHERE event_id = ? AND question_number BETWEEN ? AND ?', [$p['round'], $p['round'], $p['round'], $eid, $from, $to]);
            break;

        case 'delete_slide':
            delete_slides($eid, [(string)($p['kind'] ?? ''), (int)($p['id'] ?? 0)], !empty($p['force']));
            break;

        case 'delete_all_slides':
            delete_slides($eid, null, !empty($p['force']));
            break;

        case 'save_promo':
            $title = trim((string)($p['title'] ?? ''));
            if (mb_strlen($title) > 255) throw new UserError('Title is too long (max 255).');
            run('UPDATE scimath_promotional_slides SET title = ?, is_active = ? WHERE id = ? AND event_id = ?',
                [$title, empty($p['active']) ? 0 : 1, (int)($p['id'] ?? 0), $eid]);
            break;

        case 'add_contestant':
        case 'save_contestant':
            $name = trim((string)($p['name'] ?? '')); $acr = trim((string)($p['acronym'] ?? ''));
            if ($name === '' || mb_strlen($name) > 150) throw new UserError('Contestant name is required (max 150 characters).');
            if (mb_strlen($acr) > 255) throw new UserError('Acronym is too long (max 255 characters).');
            $acr = $acr === '' ? null : $acr;
            if ($action === 'add_contestant') {
                $o = (int)val('SELECT COALESCE(MAX(display_order), 0) + 1 FROM scimath_contestants WHERE event_id = ?', [$eid]);
                run('INSERT INTO scimath_contestants (event_id, name, acronym, display_order) VALUES (?, ?, ?, ?)', [$eid, $name, $acr, $o]);
            } else {
                run('UPDATE scimath_contestants SET name = ?, acronym = ? WHERE id = ? AND event_id = ?', [$name, $acr, (int)($p['id'] ?? 0), $eid]);
            }
            break;

        case 'delete_contestant':
            $id = (int)($p['id'] ?? 0);
            if (!val('SELECT id FROM scimath_contestants WHERE id = ? AND event_id = ?', [$id, $eid])) throw new UserError('Contestant not found.');
            if (empty($p['force']) && val('SELECT COUNT(*) FROM scimath_score_entries WHERE contestant_id = ?', [$id]) > 0) {
                throw new UserError('This contestant already has scores. Confirm to delete them together with the contestant.');
            }
            run('DELETE FROM scimath_contestants WHERE id = ? AND event_id = ?', [$id, $eid]); // scores and clincher picks cascade
            break;

        case 'move_contestant': // swap with the neighbour above/below, then renumber 1..N
            $ids = array_map('intval', array_column(rows('SELECT id FROM scimath_contestants WHERE event_id = ? ORDER BY display_order, id', [$eid]), 'id'));
            $i = array_search((int)($p['id'] ?? 0), $ids, true);
            if ($i === false) throw new UserError('Contestant not found.');
            $j = $i + (($p['dir'] ?? '') === 'up' ? -1 : 1);
            if (isset($ids[$j])) [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            foreach ($ids as $k => $cid) run('UPDATE scimath_contestants SET display_order = ? WHERE id = ?', [$k + 1, $cid]);
            break;

        case 'import_contestants': // rows parsed from a CSV file in the browser: [{acronym, name}, ...]
            $in = $p['rows'] ?? [];
            if (!is_array($in) || !$in) throw new UserError('The CSV file has no schools in it.');
            if (count($in) > 500) throw new UserError('Too many rows (max 500 at a time).');
            $have = [];
            foreach (rows('SELECT name FROM scimath_contestants WHERE event_id = ?', [$eid]) as $r) $have[mb_strtolower($r['name'])] = true;
            $o = (int)val('SELECT COALESCE(MAX(display_order), 0) FROM scimath_contestants WHERE event_id = ?', [$eid]);
            foreach ($in as $i => $r) {
                $name = trim((string)($r['name'] ?? '')); $acr = trim((string)($r['acronym'] ?? ''));
                $line = $i + 1;
                if ($name === '' || mb_strlen($name) > 150) throw new UserError("Row $line: a name is required (max 150 characters).");
                if (mb_strlen($acr) > 255) throw new UserError("Row $line: acronym is too long (max 255 characters).");
                if (isset($have[mb_strtolower($name)])) continue; // already added
                $have[mb_strtolower($name)] = true;
                run('INSERT INTO scimath_contestants (event_id, name, acronym, display_order) VALUES (?, ?, ?, ?)',
                    [$eid, $name, $acr === '' ? null : $acr, ++$o]);
            }
            break;

        default:
            throw new UserError('Unknown action.');
    }
    $conn->commit();
    out(['state' => load_state($eid)]);
} catch (UserError $e) {
    try { $conn->rollback(); } catch (Throwable $_) {}
    out(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $_) {}
    out(['error' => 'Server error: ' . $e->getMessage()], 500);
}
