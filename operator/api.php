<?php
// operator/api.php - shared screen-state endpoint for the operator and display pages.
// POST: action, event, payload (JSON). Every response is {snapshot} or {error}.
// The live screen state is one JSON row per event in scimath_screen_state (created on first use).
require_once __DIR__ . '/../config/database.php'; // provides $conn (mysqli)
require_once __DIR__ . '/../config/clincher.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

const ROUNDS = ['easy', 'medium', 'hard', 'clincher'];
const SCORED_ROUNDS = ['easy', 'medium', 'hard']; // clincher rounds carry no points; the operator picks winners
const ROUND_LABEL = ['easy' => 'Easy', 'medium' => 'Average', 'hard' => 'Difficult', 'clincher' => 'Clincher'];
const BASES = ['question', 'answer', 'boards_up'];

class UserError extends RuntimeException {}

function run(string $sql, array $p = []): mysqli_stmt
{
    global $conn;
    $s = $conn->prepare($sql);
    $s->execute($p ?: null);
    return $s;
}
function rows(string $sql, array $p = []): array { return run($sql, $p)->get_result()->fetch_all(MYSQLI_ASSOC); }
function one(string $sql, array $p = []): ?array { return rows($sql, $p)[0] ?? null; }
function val(string $sql, array $p = []) { $r = one($sql, $p); return $r ? array_values($r)[0] : null; }
function now_ms(): int { return (int)round(microtime(true) * 1000); }
function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// ---------------------------------------------------------------- questions

function round_defaults(int $eid): array
{
    $st = one('SELECT default_points, default_time_seconds, extra_settings FROM scimath_event_settings WHERE event_id = ?', [$eid]);
    $extra = json_decode($st['extra_settings'] ?? '', true) ?: [];
    $out = [];
    foreach (ROUNDS as $r) {
        $out[$r] = [
            'points' => (int)($extra['rounds'][$r]['points'] ?? ($st['default_points'] ?? 10)),
            'time'   => (int)($extra['rounds'][$r]['time'] ?? ($st['default_time_seconds'] ?? 60)),
        ];
    }
    return $out;
}

/** Questions with effective points/time resolved from round defaults. */
function questions(int $eid): array
{
    $d = round_defaults($eid);
    $qs = rows('SELECT id, question_number AS number, round, image_path, answer_image_path, points, time_seconds
                FROM scimath_questions WHERE event_id = ? ORDER BY display_order, id', [$eid]);
    $seen = [];
    foreach ($qs as &$q) {
        $q['rn']   = $seen[$q['round']] = ($seen[$q['round']] ?? 0) + 1; // number within its round
        $q['name'] = ROUND_LABEL[$q['round']] . ' ' . $q['rn'];
        $q['eff_points'] = (int)($q['points'] ?? $d[$q['round']]['points']);
        $q['eff_time']   = (int)($q['time_seconds'] ?? $d[$q['round']]['time']);
        $q['round_label'] = ROUND_LABEL[$q['round']];
    }
    return $qs;
}

function find_question(array $qs, ?int $id): ?array
{
    foreach ($qs as $q) if ((int)$q['id'] === $id) return $q;
    return null;
}

// ---------------------------------------------------------------- state row

function default_state(int $eid): array
{
    $first = val('SELECT id FROM scimath_questions WHERE event_id = ? ORDER BY display_order, id LIMIT 1', [$eid]);
    return [
        'hidden' => true,            // true = show the cover (default); false = show the live content
        'banner' => false,           // true = force the cover even while the event is switched on
        'base' => 'question',        // question | answer | boards_up
        'overlay' => null,           // null | {type:'promo',id} | {type:'results',kind,question_id,round}
        'question_id' => $first ? (int)$first : null,
        'boards_up' => ['type' => 'text', 'text' => 'Boards Up', 'promo_id' => null],
        'timer' => ['duration' => 0, 'remaining' => 0, 'running' => false, 'end_ms' => 0, 'ended' => false, 'run_id' => 0],
        'scroll' => 0, 'scroll_by' => '',
    ];
}

function lock_state(int $eid): array
{
    run('CREATE TABLE IF NOT EXISTS scimath_screen_state (
            event_id INT UNSIGNED NOT NULL PRIMARY KEY,
            state LONGTEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_screen_state_event FOREIGN KEY (event_id) REFERENCES scimath_events (id) ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    run('INSERT IGNORE INTO scimath_event_settings (event_id) VALUES (?)', [$eid]);
    run('INSERT IGNORE INTO scimath_screen_state (event_id, state) VALUES (?, ?)', [$eid, json_encode(default_state($eid))]);
    $row = one('SELECT state FROM scimath_screen_state WHERE event_id = ? FOR UPDATE', [$eid]);
    return array_replace_recursive(default_state($eid), json_decode($row['state'], true) ?: []);
}

function save_state(int $eid, array $s): void
{
    run('UPDATE scimath_screen_state SET state = ? WHERE event_id = ?', [json_encode($s), $eid]);
}

/** Timer ran out: stop it and move the screen to Boards Up. Returns true if the state changed. */
function tick(array &$s, int $now): bool
{
    $t = &$s['timer'];
    if (!$t['running'] || $now < $t['end_ms']) return false;
    $t['running'] = false; $t['remaining'] = 0; $t['ended'] = true;
    if ($s['base'] === 'question') $s['base'] = 'boards_up';
    return true;
}

function timer_remaining_ms(array $t, int $now): int
{
    return $t['running'] ? max(0, $t['end_ms'] - $now) : (int)round($t['remaining'] * 1000);
}

function reset_timer(array &$s, ?array $q): void
{
    $dur = $q ? $q['eff_time'] : 0;
    $s['timer'] = ['duration' => $dur, 'remaining' => $dur, 'running' => false, 'end_ms' => 0, 'ended' => false,
                   'run_id' => $s['timer']['run_id'] ?? 0];
}

// ---------------------------------------------------------------- results

/** Adds 'rank' to rows already sorted best-first. Rows with equal keys share the average of the places they span (two-way tie for 1st = 1.5). */
function assign_ranks(array $list, callable $key): array
{
    $n = count($list);
    for ($i = 0; $i < $n; ) {
        $j = $i;
        while ($j + 1 < $n && $key($list[$j + 1]) === $key($list[$i])) $j++;
        $avg = ($i + $j + 2) / 2;
        for ($k = $i; $k <= $j; $k++) $list[$k]['rank'] = $avg;
        $i = $j + 1;
    }
    return $list;
}

function result_data(int $eid, array $overlay, array $qs): array
{
    $cons = rows('SELECT id, name, acronym, starting_score FROM scimath_contestants
                  WHERE event_id = ? AND is_active = 1 ORDER BY display_order, id', [$eid]);
    $entries = rows('SELECT e.contestant_id, e.question_id, e.result, e.points_awarded, q.round, q.question_number
                     FROM scimath_score_entries e JOIN scimath_questions q ON q.id = e.question_id
                     WHERE e.event_id = ? ORDER BY e.id', [$eid]);
    $kind = $overlay['kind'] ?? 'question';
    $label = fn($c) => $c['acronym'] !== null && $c['acronym'] !== '' ? $c['acronym'] : $c['name'];

    if ($kind === 'question') {
        $q = find_question($qs, (int)($overlay['question_id'] ?? 0));
        if ($q && $q['round'] === 'clincher') {
            // No points: show the teams that played, winners in green.
            $cl = load_clincher($GLOBALS['conn'], $eid)[(int)$q['id']] ?? ['participants' => [], 'winners' => []];
            $list = [];
            foreach ($cons as $c) {
                if (!in_array((int)$c['id'], $cl['participants'], true)) continue;
                $win = in_array((int)$c['id'], $cl['winners'], true);
                $list[] = ['label' => $label($c), 'name' => $c['name'], 'result' => $win ? 'correct' : 'incorrect', 'points' => null];
            }
            return ['kind' => 'question', 'title' => $q['name'] . ' Results', 'rows' => $list];
        }
        // Fixed order (the contestants' display order): scoring or changing a score never moves a card.
        $by = [];
        foreach ($entries as $e) if ((int)$e['question_id'] === (int)($q['id'] ?? 0)) $by[(int)$e['contestant_id']] = $e;
        $list = [];
        foreach ($cons as $c) {
            $e = $by[(int)$c['id']] ?? null;
            $list[] = ['label' => $label($c), 'name' => $c['name'], 'result' => $e['result'] ?? 'unscored', 'points' => $e ? (int)$e['points_awarded'] : null];
        }
        return ['kind' => 'question', 'title' => $q ? $q['name'] . ' Results' : 'Question Results', 'rows' => $list];
    }

    if ($kind === 'round') {
        $round = in_array($overlay['round'] ?? '', SCORED_ROUNDS, true) ? $overlay['round'] : 'easy';
        $rq = array_values(array_filter($qs, fn($q) => $q['round'] === $round));
        $list = [];
        foreach ($cons as $c) {
            $cells = []; $total = 0;
            foreach ($rq as $q) {
                $pts = null; $res = 'unscored';
                foreach ($entries as $e) {
                    if ((int)$e['contestant_id'] === (int)$c['id'] && (int)$e['question_id'] === (int)$q['id']) {
                        $pts = (int)$e['points_awarded']; $res = $e['result']; $total += $pts;
                    }
                }
                $cells[] = ['n' => (int)$q['rn'], 'points' => $pts, 'result' => $res];
            }
            $list[] = ['label' => $label($c), 'name' => $c['name'], 'cells' => $cells, 'total' => $total];
        }
        usort($list, fn($a, $b) => $b['total'] <=> $a['total']); // stable: ties keep display order
        $list = assign_ranks($list, fn($r) => [$r['total']]);
        return ['kind' => 'round', 'round' => $round, 'title' => ROUND_LABEL[$round] . ' Round Results', 'rows' => $list];
    }

    $cl = load_clincher($GLOBALS['conn'], $eid);
    $wins = clincher_wins(array_filter($cl, fn($c) => empty($c['rank']))); // rounds without a rank only break ties
    $locked = $kind === 'final' ? clincher_locked($cl) : [];
    if ($kind === 'overall') $wins = [];
    $list = [];
    foreach ($cons as $c) {
        $parts = array_fill_keys(SCORED_ROUNDS, 0);
        foreach ($entries as $e) if ((int)$e['contestant_id'] === (int)$c['id'] && isset($parts[$e['round']])) $parts[$e['round']] += (int)$e['points_awarded'];
        $list[] = ['id' => (int)$c['id'], 'label' => $label($c), 'name' => $c['name'], 'parts' => $parts,
                   'start' => (int)$c['starting_score'], 'total' => (int)$c['starting_score'] + array_sum($parts),
                   'wins' => $wins[(int)$c['id']] ?? 0];
    }

    // A clincher winner takes the rank that round was played for. Everyone else shares the remaining
    // places in score order; teams still tied (same total, same clincher wins) get the average of their places.
    $pool = array_values(array_filter($list, fn($r) => !isset($locked[$r['id']])));
    $fixed = array_values(array_filter($list, fn($r) => isset($locked[$r['id']])));
    foreach ($fixed as &$r) $r['rank'] = $locked[$r['id']];
    unset($r);
    usort($pool, fn($a, $b) => [$b['total'], $b['wins']] <=> [$a['total'], $a['wins']]);
    $places = array_values(array_diff(range(1, count($list)), array_values($locked)));
    for ($i = 0; $i < count($pool); ) {
        $j = $i;
        while ($j + 1 < count($pool) && [$pool[$j + 1]['total'], $pool[$j + 1]['wins']] === [$pool[$i]['total'], $pool[$i]['wins']]) $j++;
        $span = array_map(fn($k) => $places[$k] ?? count($list), range($i, $j));
        $avg = array_sum($span) / count($span);
        for ($k = $i; $k <= $j; $k++) $pool[$k]['rank'] = $avg;
        $i = $j + 1;
    }
    $list = array_merge($fixed, $pool);
    usort($list, fn($a, $b) => $a['rank'] <=> $b['rank']);

    // Optional "top N": keep teams tied with the last one shown.
    $limit = (int)($overlay['limit'] ?? 0);
    $title = $kind === 'final' ? 'Final Results' : 'Overall Results';
    if ($limit > 0 && count($list) > $limit) {
        $edge = $list[$limit - 1]['rank'];
        $list = array_values(array_filter($list, fn($r) => $r['rank'] <= $edge));
        $title .= ' - Top ' . $limit;
    }
    return ['kind' => $kind, 'title' => $title, 'rows' => $list];
}

// ---------------------------------------------------------------- snapshot

function snapshot(int $eid, array $s, int $now, bool $operator): array
{
    $ev = one('SELECT id, name, subtitle, logo_path, cover_image_path FROM scimath_events WHERE id = ?', [$eid]);
    $st = one('SELECT timer_audio_path, banner_audio_path, game_audio_path, banner_audio_volume, game_audio_volume
               FROM scimath_event_settings WHERE event_id = ?', [$eid]);
    $qs = questions($eid);
    $q = find_question($qs, $s['question_id']);
    $t = $s['timer'];

    $promoPath = function (?int $id) use ($eid) {
        return $id ? val('SELECT file_path FROM scimath_promotional_slides WHERE id = ? AND event_id = ?', [$id, $eid]) : null;
    };

    // What the display shows.
    $screen = ['kind' => 'cover', 'src' => $ev['cover_image_path'], 'text' => $ev['name']];
    if (!$s['hidden'] && !$s['banner']) {
        $ov = $s['overlay'];
        if ($ov && $ov['type'] === 'promo' && ($p = $promoPath((int)$ov['id']))) {
            $screen = ['kind' => 'image', 'src' => $p];
        } elseif ($ov && $ov['type'] === 'results') {
            $screen = ['kind' => 'results'] + result_data($eid, $ov, $qs);
        } elseif ($s['base'] === 'question') {
            $screen = $q ? ['kind' => 'image', 'src' => $q['image_path']] : ['kind' => 'text', 'text' => 'No questions'];
        } elseif ($s['base'] === 'answer') {
            $screen = $q && $q['answer_image_path'] ? ['kind' => 'image', 'src' => $q['answer_image_path']]
                                                     : ['kind' => 'text', 'text' => 'No answer slide for this question'];
        } else { // boards_up
            $bu = $s['boards_up'];
            $slide = $bu['type'] === 'slide' ? $promoPath((int)$bu['promo_id']) : null;
            $screen = $slide ? ['kind' => 'image', 'src' => $slide] : ['kind' => 'text', 'text' => $bu['text'] !== '' ? $bu['text'] : 'Boards Up'];
        }
    }

    $snap = [
        'now' => $now,
        'event' => $ev,
        'state' => ['hidden' => $s['hidden'], 'banner' => $s['banner'], 'base' => $s['base'], 'overlay' => $s['overlay'], 'boards_up' => $s['boards_up'],
                    'scroll' => $s['scroll'], 'scroll_by' => $s['scroll_by']],
        'screen' => $screen,
        'question' => $q,
        'question_index' => $q ? array_search($q['id'], array_column($qs, 'id')) : null,
        'question_count' => count($qs),
        'timer' => [
            'running' => $t['running'], 'ended' => $t['ended'], 'duration' => $t['duration'], 'run_id' => $t['run_id'],
            'remaining_ms' => timer_remaining_ms($t, $now),
            // The countdown badge only makes sense while the question slide is up.
            'visible' => !$s['hidden'] && !$s['banner'] && !$s['overlay'] && $s['base'] === 'question' && $t['duration'] > 0 && ($t['running'] || $t['remaining'] > 0),
        ],
        'audio' => [
            'timer' => $st['timer_audio_path'] ?: 'assets/placeholder_timer_audio.mp3',
            'banner' => $st['banner_audio_path'], 'banner_volume' => (int)$st['banner_audio_volume'],
            'game' => $st['game_audio_path'], 'game_volume' => (int)$st['game_audio_volume'],
        ],
    ];
    if ($operator) {
        $snap['questions'] = $qs;
        $snap['promos'] = rows('SELECT id, title, file_path AS path FROM scimath_promotional_slides
                                WHERE event_id = ? AND is_active = 1 ORDER BY display_order, id', [$eid]);
        $snap['rounds'] = array_map(fn($r) => ['value' => $r, 'label' => ROUND_LABEL[$r]], SCORED_ROUNDS);
        $snap['contestants'] = rows('SELECT id, name, acronym FROM scimath_contestants
                                     WHERE event_id = ? AND is_active = 1 ORDER BY display_order, id', [$eid]);
        $snap['clincher'] = (object)load_clincher($GLOBALS['conn'], $eid);
    }
    return $snap;
}

// ---------------------------------------------------------------- dispatch

try {
    $eid = (int)($_POST['event'] ?? $_GET['event'] ?? 0);
    $action = $_POST['action'] ?? 'state';
    $p = json_decode($_POST['payload'] ?? '{}', true) ?: [];
    $operator = ($p['role'] ?? '') === 'operator';
    if (!val('SELECT id FROM scimath_events WHERE id = ?', [$eid])) throw new UserError('Event not found.');

    ensure_clincher_table($conn);
    $conn->begin_transaction();
    $s = lock_state($eid);
    $now = now_ms();
    $changed = tick($s, $now);
    $qs = questions($eid);
    $cur = find_question($qs, $s['question_id']);

    // Row buttons name the question they act on: move to it first (new question = fresh timer).
    if (in_array($action, ['show', 'timer_start', 'show_results'], true) && isset($p['question_id'])) {
        $target = find_question($qs, (int)$p['question_id']);
        if (!$target) throw new UserError('No such question.');
        if ((int)$target['id'] !== (int)$s['question_id']) {
            $s['question_id'] = (int)$target['id'];
            $s['overlay'] = null;
            reset_timer($s, $target);
            $cur = $target;
            $changed = true;
        }
    }

    switch ($action) {
        case 'state':
            break;

        case 'set_hidden': // true = back to the cover; false = start/resume the event
            $s['hidden'] = !empty($p['hidden']); $s['banner'] = false;
            if (!$s['hidden'] && val('SELECT status FROM scimath_events WHERE id = ?', [$eid]) !== 'active') {
                run("UPDATE scimath_events SET status = 'active' WHERE id = ? AND status IN ('draft','ready')", [$eid]);
            }
            $changed = true;
            break;

        case 'set_banner': // cover on top of the live content; the event toggle is left as it is
            $s['banner'] = !empty($p['banner']);
            $changed = true;
            break;

        case 'goto_question':
            $idx = $cur ? array_search($cur['id'], array_column($qs, 'id')) : -1;
            if (isset($p['dir'])) $idx += $p['dir'] === 'prev' ? -1 : 1;
            elseif (isset($p['question_id'])) $idx = array_search((int)$p['question_id'], array_column($qs, 'id'));
            if ($idx === false || !isset($qs[$idx])) throw new UserError('No such question.');
            $s['question_id'] = (int)$qs[$idx]['id'];
            $s['base'] = 'question'; $s['overlay'] = null;
            reset_timer($s, $qs[$idx]);
            $changed = true;
            break;

        case 'show': // question | answer | boards_up
            if (!in_array($p['base'] ?? '', BASES, true)) throw new UserError('Invalid screen.');
            $s['base'] = $p['base']; $s['overlay'] = null; $s['hidden'] = false; $s['banner'] = false;
            $changed = true;
            break;

        case 'show_promo':
            if (!val('SELECT id FROM scimath_promotional_slides WHERE id = ? AND event_id = ?', [(int)($p['id'] ?? 0), $eid])) {
                throw new UserError('Slide not found.');
            }
            $s['overlay'] = ['type' => 'promo', 'id' => (int)$p['id']]; $s['hidden'] = false; $s['banner'] = false;
            $changed = true;
            break;

        case 'show_results':
            $kind = $p['kind'] ?? '';
            if (!in_array($kind, ['question', 'round', 'overall', 'final'], true)) throw new UserError('Invalid results type.');
            $round = in_array($p['round'] ?? '', SCORED_ROUNDS, true) ? $p['round'] : (in_array($cur['round'] ?? '', SCORED_ROUNDS, true) ? $cur['round'] : 'easy');
            $limit = max(0, min(50, (int)($p['limit'] ?? 0)));
            $s['overlay'] = ['type' => 'results', 'kind' => $kind, 'question_id' => $s['question_id'], 'round' => $round, 'limit' => $limit];
            $s['hidden'] = false; $s['banner'] = false; $s['scroll'] = 0; $s['scroll_by'] = 'operator-reset';
            $changed = true;
            break;

        case 'clear_overlay':
            $s['overlay'] = null;
            $changed = true;
            break;

        case 'set_scroll':
            $s['scroll'] = max(0, min(1, (float)($p['ratio'] ?? 0)));
            $s['scroll_by'] = substr((string)($p['by'] ?? ''), 0, 40);
            $changed = true;
            break;

        case 'save_boards_up':
            $type = ($p['type'] ?? '') === 'slide' ? 'slide' : 'text';
            $promo = (int)($p['promo_id'] ?? 0);
            if ($type === 'slide' && !val('SELECT id FROM scimath_promotional_slides WHERE id = ? AND event_id = ?', [$promo, $eid])) {
                throw new UserError('Pick a slide for Boards Up.');
            }
            $text = trim((string)($p['text'] ?? ''));
            if (mb_strlen($text) > 120) throw new UserError('Boards Up text is too long (max 120).');
            $s['boards_up'] = ['type' => $type, 'text' => $text === '' ? 'Boards Up' : $text, 'promo_id' => $promo ?: null];
            $changed = true;
            break;

        case 'timer_start':
            if (!$cur) throw new UserError('There is no question to time.');
            $dur = $cur['eff_time'];
            $s['timer'] = ['duration' => $dur, 'remaining' => $dur, 'running' => true, 'end_ms' => $now + $dur * 1000,
                           'ended' => false, 'run_id' => ($s['timer']['run_id'] ?? 0) + 1];
            $s['base'] = 'question'; $s['overlay'] = null; $s['hidden'] = false; $s['banner'] = false;
            $changed = true;
            break;

        case 'timer_pause':
            if ($s['timer']['running']) {
                $s['timer']['remaining'] = max(0, $s['timer']['end_ms'] - $now) / 1000;
                $s['timer']['running'] = false;
            }
            $changed = true;
            break;

        case 'timer_resume':
            if (!$s['timer']['running'] && $s['timer']['remaining'] > 0) {
                $s['timer']['end_ms'] = $now + (int)round($s['timer']['remaining'] * 1000);
                $s['timer']['running'] = true; $s['timer']['ended'] = false;
            }
            $changed = true;
            break;

        case 'timer_adjust':
            $delta = max(-600, min(600, (int)($p['seconds'] ?? 0)));
            if ($s['timer']['running']) {
                $s['timer']['end_ms'] += $delta * 1000;
            } else {
                $s['timer']['remaining'] = max(0, $s['timer']['remaining'] + $delta);
                $s['timer']['duration'] = max($s['timer']['duration'], (int)ceil($s['timer']['remaining']));
            }
            $changed = true;
            break;

        case 'timer_reset':
            reset_timer($s, $cur);
            $changed = true;
            break;

        case 'save_question_fields':
            $pts = $p['points'] ?? ''; $time = $p['time'] ?? '';
            foreach ([['Points', $pts, 0, 100000], ['Time limit', $time, 1, 65535]] as [$label, $v, $lo, $hi]) {
                if ($v !== '' && $v !== null && (!is_numeric($v) || (int)$v != $v || $v < $lo || $v > $hi)) {
                    throw new UserError("$label must be a whole number from $lo to $hi.");
                }
            }
            run('UPDATE scimath_questions SET points = ?, time_seconds = ? WHERE id = ? AND event_id = ?',
                [$pts === '' ? null : (int)$pts, $time === '' ? null : (int)$time, (int)($p['id'] ?? 0), $eid]);
            $qs = questions($eid);
            if (!$s['timer']['running'] && ($cur['id'] ?? 0) == ($p['id'] ?? 0) && !$s['timer']['ended'] && $s['timer']['remaining'] == $s['timer']['duration']) {
                reset_timer($s, find_question($qs, $s['question_id'])); // keep an unstarted timer in sync with the new limit
                $changed = true;
            }
            break;

        case 'save_clincher': // participants + winners for one clincher question
            $q = find_question($qs, (int)($p['question_id'] ?? 0));
            if (!$q || $q['round'] !== 'clincher') throw new UserError('Pick a clincher question.');
            $valid = array_map('intval', array_column(
                rows('SELECT id FROM scimath_contestants WHERE event_id = ? AND is_active = 1', [$eid]), 'id'));
            $parts = array_values(array_unique(array_map('intval', (array)($p['participants'] ?? []))));
            $wins = array_values(array_unique(array_map('intval', (array)($p['winners'] ?? []))));
            if (array_diff($parts, $valid)) throw new UserError('Unknown team selected.');
            if (array_diff($wins, $parts)) throw new UserError('A winner must be one of the participants.');
            if (array_key_exists('rank', $p)) {
                $rk = (int)$p['rank'];
                if ($rk > 0) run('INSERT INTO scimath_clincher_rounds (question_id, rank_for) VALUES (?, ?) ON DUPLICATE KEY UPDATE rank_for = VALUES(rank_for)', [$q['id'], $rk]);
                else run('DELETE FROM scimath_clincher_rounds WHERE question_id = ?', [$q['id']]);
            }
            run('DELETE FROM scimath_clincher_entries WHERE question_id = ?', [$q['id']]);
            foreach ($parts as $cid) {
                run('INSERT INTO scimath_clincher_entries (question_id, contestant_id, is_winner) VALUES (?, ?, ?)',
                    [$q['id'], $cid, in_array($cid, $wins, true) ? 1 : 0]);
            }
            break;

        default:
            throw new UserError('Unknown action.');
    }

    if ($changed) save_state($eid, $s);
    $conn->commit();
    out(['snapshot' => snapshot($eid, $s, $now, $operator)]);
} catch (UserError $e) {
    try { $conn->rollback(); } catch (Throwable $_) {}
    out(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $_) {}
    out(['error' => 'Server error: ' . $e->getMessage()], 500);
}
