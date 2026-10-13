<?php
ob_start();
require_once __DIR__ . '/../../../src/bootstrap.php';
Auth::requireAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!Csrf::check()) {
    http_response_code(419);
    ob_clean();
    echo json_encode(['error' => 'Security check failed. Please refresh the page and try again.']);
    exit;
}

$eventId = (int) ($_POST['event_id'] ?? 0);
$event = Event::find($eventId);
if ($event === null) {
    ob_clean();
    echo json_encode(['error' => 'Event not found.']);
    exit;
}

$sessionId = trim((string) ($_POST['session_id'] ?? ''));
$saveBanner = isset($_POST['save_banner']) && $_POST['save_banner'] === '1';
$slidesRaw = trim((string) ($_POST['slides'] ?? '[]'));
$redirectUrl = trim((string) ($_POST['redirect_to'] ?? ''));
$slides = json_decode($slidesRaw, true);

if (!is_array($slides)) {
    $slides = [];
}

if ($sessionId === '') {
    ob_clean();
    echo json_encode(['error' => 'Missing session ID.']);
    exit;
}

$sessionKey = 'batch_upload_' . $sessionId;
if (!isset($_SESSION[$sessionKey])) {
    ob_clean();
    echo json_encode(['error' => 'Staged uploads have expired. Please re-upload your files.']);
    exit;
}

$staged = $_SESSION[$sessionKey]['images'] ?? [];
if ($staged === []) {
    ob_clean();
    echo json_encode(['error' => 'No staged images.']);
    exit;
}

$stagedByIndex = [];
foreach ($staged as $img) {
    $stagedByIndex[(int) $img['index']] = $img;
}

require_once __DIR__ . '/../../../src/Support/Uploader.php';
require_once __DIR__ . '/../../../src/Models/PromotionalSlide.php';
require_once __DIR__ . '/../../../src/Models/AnswerSlide.php';

$promoItems = [];
$questionItems = [];
$answerItems = [];
$bannerPath = null;

foreach ($slides as $slide) {
    $idx = (int) ($slide['index'] ?? -1);
    $type = (string) ($slide['type'] ?? '');
    $difficulty = (string) ($slide['difficulty'] ?? 'easy');
    $img = $stagedByIndex[$idx] ?? null;
    if ($img === null) {
        continue;
    }

    switch ($type) {
        case 'banner':
            if ($saveBanner && $bannerPath === null) {
                try {
                    Uploader::delete($event['cover_image_path']);
                    $bannerPath = Uploader::handleImageFromPath($img['tempPath'], 'covers', $eventId);
                } catch (RuntimeException $e) {
                    @unlink($img['tempPath']);
                    echo json_encode(['error' => $e->getMessage()]);
                    exit;
                }
            }
            @unlink($img['tempPath']);
            break;

        case 'promotional':
            try {
                $fpath = Uploader::handleImageFromPath($img['tempPath'], 'promotional', $eventId);
                $promoItems[] = [
                    'title'       => preg_replace('/\.(png|jpe?g|webp)$/i', '', $img['filename']),
                    'description' => '',
                    'file_path'   => $fpath,
                    'is_active'   => true,
                ];
            } catch (RuntimeException $e) {
                @unlink($img['tempPath']);
                echo json_encode(['error' => $e->getMessage()]);
                exit;
            }
            @unlink($img['tempPath']);
            break;

        case 'question':
            try {
                $qpath = Uploader::handleImageFromPath($img['tempPath'], 'questions', $eventId);
                $questionItems[] = [
                    'image_path' => $qpath,
                    'is_active'  => true,
                    'round'      => $difficulty,
                ];
            } catch (RuntimeException $e) {
                @unlink($img['tempPath']);
                echo json_encode(['error' => $e->getMessage()]);
                exit;
            }
            @unlink($img['tempPath']);
            break;

        case 'answer':
            try {
                $apath = Uploader::handleImageFromPath($img['tempPath'], 'answer_slides', $eventId);
                $answerItems[] = [
                    'image_path' => $apath,
                    'is_active'  => true,
                ];
            } catch (RuntimeException $e) {
                @unlink($img['tempPath']);
                echo json_encode(['error' => $e->getMessage()]);
                exit;
            }
            @unlink($img['tempPath']);
            break;
    }
}

$startNum = Question::nextQuestionNumber($eventId);
foreach ($questionItems as $i => &$item) {
    $item['question_number'] = $startNum + $i;
    $item['round'] = $item['round'] ?? Round::EASY;
    $item['display_order'] = count(Question::forEvent($eventId)) + $i;
}
unset($item);

$pairedAnswers = [];
$ansCount = min(count($answerItems), count($questionItems));
for ($i = 0; $i < $ansCount; $i++) {
    $pairedAnswers[] = array_merge($answerItems[$i], ['question_id' => null]);
}
for ($i = $ansCount; $i < count($answerItems); $i++) {
    $pairedAnswers[] = $answerItems[$i];
}

$createdQuestions = [];
if ($questionItems !== []) {
    try {
        $createdQuestions = Question::createBatch($eventId, $questionItems);
    } catch (PDOException $e) {
        if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), '1062')) {
            echo json_encode(['error' => 'A question with that number already exists.']);
            exit;
        }
        throw $e;
    }
}

foreach ($pairedAnswers as $i => &$ans) {
    if ($ans['question_id'] === null && isset($createdQuestions[$i])) {
        $ans['question_id'] = (int) $createdQuestions[$i]['id'];
    }
    $ans['display_order'] = $i;
}
unset($ans);

$createdPromo = 0;
$createdAnswers = 0;

if ($promoItems !== []) {
    try {
        PromotionalSlide::createBatch($eventId, $promoItems);
        $createdPromo = count($promoItems);
    } catch (Throwable $e) {
        echo json_encode(['error' => 'Failed to save promotional slides: ' . $e->getMessage()]);
        exit;
    }
}

if ($pairedAnswers !== []) {
    try {
        AnswerSlide::createBatch($eventId, $pairedAnswers);
        $createdAnswers = count($pairedAnswers);
    } catch (Throwable $e) {
        echo json_encode(['error' => 'Failed to save answer slides: ' . $e->getMessage()]);
        exit;
    }
}

if ($bannerPath !== null) {
    try {
        Event::update($eventId, ['cover_image_path' => $bannerPath]);
    } catch (Throwable $e) {
        // Non-fatal — slides were saved
    }
}

unset($_SESSION[$sessionKey]);

$redirectUrl = trim((string) ($_POST['redirect_to'] ?? ''));
$result = [
    'ok'               => true,
    'created_questions'=> count($createdQuestions),
    'created_promo'    => $createdPromo,
    'created_answers'  => $createdAnswers,
    'banner_saved'     => $bannerPath !== null,
];
if ($redirectUrl !== '') {
    $result['redirect_to'] = $redirectUrl;
}

echo json_encode($result);
