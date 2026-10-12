<?php

/**
 * Usage: php database/seed_rounds.php
 *
 * Creates one clearly-fake test event that exercises all three difficulty
 * rounds (easy / medium / hard), each with its own questions, plus a handful
 * of scores spread across the rounds. Handy for testing the operator's Round
 * Rankings / Final Rankings breakdowns and the round controls generally.
 *
 * Safe to run repeatedly -- each run simply creates another test event.
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/Models/Event.php';
require_once __DIR__ . '/../src/Models/CompetitionSession.php';
require_once __DIR__ . '/../src/Models/ScoreEntry.php';

$event = Event::create([
    'name'       => 'Round Test Event (EASY / MEDIUM / HARD)',
    'subtitle'   => 'Test event covering every difficulty round',
    'event_date' => date('Y-m-d'),
    'status'     => EventStatus::READY,
]);
$eventId = (int) $event['id'];
echo "event #{$eventId} created\n";

$settings = EventSetting::createDefaultsFor($eventId);
EventSetting::update($settings['id'], [
    'default_points'       => 10,
    'default_time_seconds' => 60,
]);

CompetitionSession::createFor($eventId);

$categoryNames = ['Mathematics', 'Physics', 'Chemistry', 'Biology'];
$categories = [];
foreach ($categoryNames as $i => $name) {
    $categories[$name] = Category::create([
        'event_id'      => $eventId,
        'name'          => $name,
        'display_order' => $i,
    ]);
}

$contestantDefs = [
    ['name' => 'Team Alpha',   'team_code' => 'A', 'acronym' => 'ALP'],
    ['name' => 'Team Bravo',   'team_code' => 'B', 'acronym' => 'BRV'],
    ['name' => 'Team Charlie', 'team_code' => 'C', 'acronym' => 'CHR'],
];
$contestants = [];
foreach ($contestantDefs as $i => $def) {
    $contestants[$def['name']] = Contestant::create([
        'event_id'       => $eventId,
        'name'           => $def['name'],
        'team_code'      => $def['team_code'],
        'acronym'        => $def['acronym'],
        'starting_score' => 0,
        'display_order'  => $i,
    ]);
}

// Three questions per round. Easy = 10pt/60s (event defaults), medium =
// 15pt/90s, hard = 20pt/120s.
$questionDefs = [
    ['round' => Round::EASY,   'category' => 'Mathematics', 'points' => null, 'time' => null],
    ['round' => Round::EASY,   'category' => 'Physics',     'points' => null, 'time' => null],
    ['round' => Round::EASY,   'category' => 'Chemistry',   'points' => null, 'time' => null],
    ['round' => Round::MEDIUM, 'category' => 'Mathematics', 'points' => 15,   'time' => 90],
    ['round' => Round::MEDIUM, 'category' => 'Biology',     'points' => 15,   'time' => 90],
    ['round' => Round::MEDIUM, 'category' => 'Physics',     'points' => 15,   'time' => 90],
    ['round' => Round::HARD,   'category' => 'Chemistry',   'points' => 20,   'time' => 120],
    ['round' => Round::HARD,   'category' => 'Mathematics', 'points' => 20,   'time' => 120],
    ['round' => Round::HARD,   'category' => 'Biology',     'points' => 20,   'time' => 120],
];

$questions = [];
foreach ($questionDefs as $i => $def) {
    $questions[] = Question::create([
        'event_id'        => $eventId,
        'category_id'     => $categories[$def['category']]['id'],
        'round'           => $def['round'],
        'question_number' => $i + 1,
        'image_path'      => 'uploads/questions/seed-placeholder-q' . ($i + 1) . '.png',
        'points'          => $def['points'],
        'time_seconds'    => $def['time'],
        'display_order'   => $i,
    ]);
}
echo count($questions) . " questions created (3 easy, 3 medium, 3 hard)\n";

// A few results spread across the rounds so the ranking breakdowns have data.
$results = [
    ['q' => 0, 'team' => 'Team Alpha',   'result' => AnswerResult::CORRECT],
    ['q' => 0, 'team' => 'Team Bravo',   'result' => AnswerResult::CORRECT],
    ['q' => 0, 'team' => 'Team Charlie', 'result' => AnswerResult::INCORRECT],
    ['q' => 1, 'team' => 'Team Alpha',   'result' => AnswerResult::CORRECT],
    ['q' => 1, 'team' => 'Team Bravo',   'result' => AnswerResult::INCORRECT],
    ['q' => 1, 'team' => 'Team Charlie', 'result' => AnswerResult::CORRECT],
    ['q' => 3, 'team' => 'Team Alpha',   'result' => AnswerResult::CORRECT],
    ['q' => 3, 'team' => 'Team Bravo',   'result' => AnswerResult::INCORRECT],
    ['q' => 3, 'team' => 'Team Charlie', 'result' => AnswerResult::INCORRECT],
    ['q' => 6, 'team' => 'Team Alpha',   'result' => AnswerResult::INCORRECT],
    ['q' => 6, 'team' => 'Team Bravo',   'result' => AnswerResult::CORRECT],
    ['q' => 6, 'team' => 'Team Charlie', 'result' => AnswerResult::CORRECT],
];

$settingsRow = EventSetting::forEvent($eventId);
foreach ($results as $r) {
    $q = $questions[$r['q']];
    $team = $contestants[$r['team']];
    $points = $r['result'] === AnswerResult::CORRECT
        ? Question::effectivePoints($q, $settingsRow)
        : 0;

    ScoreEntry::recordResult(
        $eventId,
        (int) $q['id'],
        (int) $team['id'],
        $r['result'],
        $points,
        'seed-operator'
    );
}
echo count($results) . " score entries created\n";

echo "Done. Round test event id = {$eventId}\n";
