<?php

/**
 * JSON API for the operator interface (and, later, the public display).
 *
 * Every action here is a one-line call into CompetitionRuntime -- this file
 * contains no scoring or state-transition logic of its own. Its only job is
 * HTTP plumbing: auth, CSRF, parameter extraction, and translating
 * CompetitionRuntimeException into a clean JSON error response.
 *
 * Routing: ?action=<name>, GET for reads, POST for anything that mutates
 * state. All actions require an authenticated session (same Auth as the
 * admin interface -- there is no separate operator role, matching the
 * "prevent unauthorized access" requirement without building a full
 * multi-role account system). A future public-display stage would add a
 * separate, read-only, unauthenticated endpoint -- this one stays
 * operator-only.
 */

require_once __DIR__ . '/../../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function api_fail(int $httpStatus, string $message): void
{
    http_response_code($httpStatus);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

function api_ok(array $data): void
{
    echo json_encode(['ok' => true] + $data);
    exit;
}

if (!Auth::check()) {
    api_fail(401, 'Not authenticated.');
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
$isWrite = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($isWrite && !Csrf::check()) {
    api_fail(419, 'Invalid or expired security token. Reload the page and try again.');
}

$input = $isWrite ? $_POST : $_GET;
$eventId = (int) ($input['event_id'] ?? 0);
if ($eventId <= 0) {
    api_fail(400, 'event_id is required.');
}

try {
    switch ($action) {
        // ---- Reads --------------------------------------------------
        case 'state':
            api_ok(CompetitionRuntime::getDashboard($eventId));
            break;

        case 'scores':
            $questionId = isset($input['question_id']) && $input['question_id'] !== ''
                ? (int) $input['question_id'] : null;
            api_ok(['scores' => CompetitionRuntime::getScores($eventId, $questionId)]);
            break;

        case 'rankings':
            api_ok(['rankings' => CompetitionRuntime::getRankings($eventId)]);
            break;

        // ---- Event lifecycle -----------------------------------------
        case 'start_event':
            require_write();
            CompetitionRuntime::startEvent($eventId);
            api_ok(CompetitionRuntime::getDashboard($eventId));
            break;

        case 'end_event':
            require_write();
            $state = CompetitionRuntime::endEvent($eventId);
            api_ok(['state' => $state]);
            break;

        // ---- Navigation ------------------------------------------------
        case 'start_first_question':
            require_write();
            api_ok(['state' => CompetitionRuntime::startFirstQuestion($eventId)]);
            break;

        case 'next_question':
            require_write();
            api_ok(['state' => CompetitionRuntime::nextQuestion($eventId)]);
            break;

        case 'previous_question':
            require_write();
            api_ok(['state' => CompetitionRuntime::previousQuestion($eventId)]);
            break;

        case 'go_to_question':
            require_write();
            $questionId = (int) ($input['question_id'] ?? 0);
            api_ok(['state' => CompetitionRuntime::goToQuestion($eventId, $questionId)]);
            break;

        // ---- Timer ----------------------------------------------------
        case 'start_timer':
            require_write();
            api_ok(['state' => CompetitionRuntime::startTimer($eventId)]);
            break;

        case 'pause_timer':
            require_write();
            api_ok(['state' => CompetitionRuntime::pauseTimer($eventId)]);
            break;

        case 'resume_timer':
            require_write();
            api_ok(['state' => CompetitionRuntime::resumeTimer($eventId)]);
            break;

        case 'reset_timer':
            require_write();
            api_ok(['state' => CompetitionRuntime::resetTimer($eventId)]);
            break;

        // ---- Display state ----------------------------------------------
        case 'show_ranking':
            require_write();
            api_ok(['state' => CompetitionRuntime::showRanking($eventId)]);
            break;

        case 'return_to_question':
            require_write();
            api_ok(['state' => CompetitionRuntime::returnToQuestion($eventId)]);
            break;

        case 'show_final_results':
            require_write();
            api_ok(['state' => CompetitionRuntime::showFinalResults($eventId)]);
            break;

        case 'return_to_cover':
            require_write();
            api_ok(['state' => CompetitionRuntime::returnToCover($eventId)]);
            break;

        // ---- Promotional & Answer Slides ------------------------------
        case 'show_promotional':
            require_write();
            $slideId = (int) ($input['slide_id'] ?? 0);
            api_ok(['state' => CompetitionRuntime::showPromotionalSlide($eventId, $slideId)]);
            break;

        case 'show_answer':
            require_write();
            $slideId = (int) ($input['slide_id'] ?? 0);
            api_ok(['state' => CompetitionRuntime::showAnswerSlide($eventId, $slideId)]);
            break;

        case 'hide_question':
            require_write();
            api_ok(['state' => CompetitionRuntime::hideQuestion($eventId)]);
            break;

        case 'show_question':
            require_write();
            api_ok(['state' => CompetitionRuntime::showQuestion($eventId)]);
            break;

        case 'preview_next':
            require_write();
            $next = CompetitionRuntime::previewNextQuestion($eventId);
            api_ok(['next_question' => $next]);
            break;

        case 'start_round':
            require_write();
            $round = isset($input['round_number']) && $input['round_number'] !== ''
                ? (int) $input['round_number'] : null;
            api_ok(['state' => CompetitionRuntime::startRound($eventId, $round)]);
            break;

        case 'end_round':
            require_write();
            api_ok(['state' => CompetitionRuntime::endRound($eventId)]);
            break;

        // ---- Scoring ----------------------------------------------------
        case 'score':
            require_write();
            $questionId = (int) ($input['question_id'] ?? 0);
            $contestantId = (int) ($input['contestant_id'] ?? 0);
            $result = (string) ($input['result'] ?? '');
            $adjustmentPoints = isset($input['adjustment_points']) && $input['adjustment_points'] !== ''
                ? (int) $input['adjustment_points'] : null;
            $reason = isset($input['reason']) && $input['reason'] !== '' ? (string) $input['reason'] : null;

            $entry = CompetitionRuntime::scoreContestant(
                $eventId, $questionId, $contestantId, $result, $adjustmentPoints, Auth::username(), $reason
            );
            api_ok(['entry' => $entry] + CompetitionRuntime::getDashboard($eventId));
            break;

        // ---- Export -----------------------------------------------------
        case 'export_scores':
            require_write();
            $format = (string) ($input['format'] ?? 'excel');
            $event = Event::find($eventId);
            if (!$event) {
                api_fail(404, 'Event not found.');
            }
            $dashboard = CompetitionRuntime::getDashboard($eventId);
            $rankings = $dashboard['rankings'] ?? [];
            $filename = 'scimath_scores_' . preg_replace('/[^a-zA-Z0-9]/', '_', $event['name']) . '.' . ($format === 'pdf' ? 'pdf' : 'csv');

            if ($format === 'excel' || $format === 'csv') {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                $fp = fopen('php://output', 'w');
                fputcsv($fp, ['Rank', 'Contestant Name', 'Team Code', 'Acronym', 'Starting Score', 'Total Score']);
                foreach ($rankings as $r) {
                    fputcsv($fp, [$r['rank'], $r['name'], $r['team_code'] ?? '', $r['acronym'] ?? '', $r['starting_score'] ?? 0, $r['total_score']]);
                }
                fclose($fp);
                exit;
            } elseif ($format === 'pdf') {
                // Placeholder for PDF export - integrate TCPDF/mPDF for production
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                echo "%PDF-1.4\nScimath Score Report\nEvent: " . $event['name'] . "\n";
                exit;
            } else {
                api_fail(400, 'Invalid format. Use excel, csv, or pdf.');
            }
            break;

        // ---- Promotional Slides CRUD ----------------------------------
        case 'promotional_slides':
            api_ok(['slides' => PromotionalSlide::forEvent($eventId)]);
            break;

        case 'promotional_save':
            require_write();
            require_once __DIR__ . '/../../src/Support/Uploader.php';
            $title = (string) ($input['title'] ?? '');
            $description = $input['description'] ?? null;
            $file = $_FILES['file'] ?? null;

            if (empty($title)) {
                api_fail(400, 'Title is required.');
            }

            $filePath = null;
            if ($file && $file['error'] === UPLOAD_ERR_OK) {
                $filePath = Uploader::handleImage($file, 'promotional', $eventId);
            } elseif (!isset($input['remove_file']) && !empty($input['existing_file'])) {
                $filePath = $input['existing_file'];
            }

            if ($filePath === null && empty($input['slide_id'])) {
                api_fail(400, 'A file is required for new slides.');
            }

            $slideId = isset($input['slide_id']) && $input['slide_id'] !== '' ? (int) $input['slide_id'] : null;
            $data = [
                'event_id'    => $eventId,
                'title'       => $title,
                'description' => $description,
                'file_path'   => $filePath ?? '',
                'file_type'   => $filePath ? 'image' : 'document',
            ];

            if ($slideId) {
                PromotionalSlide::update($slideId, $data);
            } else {
                PromotionalSlide::create($data);
            }

            api_ok(['slides' => PromotionalSlide::forEvent($eventId)]);
            break;

        case 'promotional_delete':
            require_write();
            $slideId = (int) ($input['slide_id'] ?? 0);
            PromotionalSlide::deleteWithFile($slideId);
            api_ok(['slides' => PromotionalSlide::forEvent($eventId)]);
            break;

        case 'promotional_reorder':
            require_write();
            $ids = json_decode((string) ($input['ids'] ?? '[]'), true);
            if (is_array($ids) && $ids !== []) {
                PromotionalSlide::reorder($ids);
            }
            api_ok(['slides' => PromotionalSlide::forEvent($eventId)]);
            break;

        // ---- Answer Slides CRUD ---------------------------------------
        case 'answer_slides':
            api_ok(['slides' => AnswerSlide::forEvent($eventId)]);
            break;

        case 'answer_save':
            require_write();
            require_once __DIR__ . '/../../src/Support/Uploader.php';
            $questionId = isset($input['question_id']) && $input['question_id'] !== '' ? (int) $input['question_id'] : null;
            $file = $_FILES['file'] ?? null;

            $filePath = null;
            if ($file && $file['error'] === UPLOAD_ERR_OK) {
                $filePath = Uploader::handleImage($file, 'answer', $eventId);
            } elseif (!isset($input['remove_file']) && !empty($input['existing_file'])) {
                $filePath = $input['existing_file'];
            }

            if ($filePath === null && empty($input['slide_id'])) {
                api_fail(400, 'A file is required for new answer slides.');
            }

            $slideId = isset($input['slide_id']) && $input['slide_id'] !== '' ? (int) $input['slide_id'] : null;
            $data = [
                'event_id'    => $eventId,
                'question_id' => $questionId,
                'image_path'  => $filePath ?? '',
            ];

            if ($slideId) {
                AnswerSlide::update($slideId, $data);
            } else {
                AnswerSlide::create($data);
            }

            api_ok(['slides' => AnswerSlide::forEvent($eventId)]);
            break;

        case 'answer_delete':
            require_write();
            $slideId = (int) ($input['slide_id'] ?? 0);
            AnswerSlide::deleteWithFile($slideId);
            api_ok(['slides' => AnswerSlide::forEvent($eventId)]);
            break;

        default:
            api_fail(404, 'Unknown action.');
    }
} catch (CompetitionRuntimeException $e) {
    api_fail(422, $e->getMessage());
} catch (Throwable $e) {
    // Unexpected errors: don't leak internals to the client, but don't
    // silently swallow them either.
    error_log('[runtime API] ' . $e->getMessage());
    api_fail(500, 'An unexpected error occurred.');
}

function require_write(): void
{
    global $isWrite;
    if (!$isWrite) {
        api_fail(405, 'This action requires POST.');
    }
}
