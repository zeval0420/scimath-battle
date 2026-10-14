<?php
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
    echo json_encode(['error' => 'Security check failed. Please refresh and try again.']);
    exit;
}

require_once __DIR__ . '/../../../src/Support/Uploader.php';
require_once __DIR__ . '/../../../src/Models/PromotionalSlide.php';
require_once __DIR__ . '/../../../src/Models/AnswerSlide.php';

$v = new Validator();
$name = $v->requiredString($_POST, 'name', 'Event name', 150);
$subtitle = $v->optionalString($_POST, 'subtitle', 'Subtitle', 255);
$eventDate = $v->optionalDate($_POST, 'event_date', 'Event date');

if ($v->hasErrors()) {
    echo json_encode(['error' => implode(' ', $v->errors())]);
    exit;
}

try {
    // Create the event
    $event = Event::create([
        'name'       => $name,
        'subtitle'   => $subtitle,
        'event_date' => $eventDate,
        'status'     => EventStatus::DRAFT,
    ]);
    $eventId = (int) $event['id'];
    EventSetting::createDefaultsFor($eventId);
    CompetitionSession::createFor($eventId);

    // Process slides if any were uploaded
    $slidesResult = processSlides($eventId);

    echo json_encode([
        'ok'         => true,
        'event_id'   => $eventId,
        'event_name' => $name,
        'slides'     => $slidesResult,
        'redirect_to' => relative_url('/public/admin/event.php?id=' . $eventId),
    ]);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Failed to create event: ' . $e->getMessage()]);
    exit;
}

function processSlides(int $eventId): array
{
    error_log("processSlides: FILES keys: " . print_r(array_keys($_FILES), true));
    $createdQuestions = 0;
    $createdPromo = 0;
    $createdAnswers = 0;
    $bannerSaved = false;
    $errors = [];

    // Handle ZIP upload
    if (isset($_FILES['upload']) && $_FILES['upload']['error'] === UPLOAD_ERR_OK) {
        error_log("processSlides: Processing ZIP upload");
        $result = processZipUpload($eventId, $_FILES['upload']);
        $createdQuestions += $result['questions'];
        $createdPromo += $result['promo'];
        $createdAnswers += $result['answers'];
        $bannerSaved = $result['banner'] || $bannerSaved;
        $errors = array_merge($errors, $result['errors']);
    }

    // Handle multi-file upload
    if (isset($_FILES['files']) && is_array($_FILES['files']['name'])) {
        error_log("processSlides: Processing multi-file upload");
        $result = processMultiUpload($eventId, $_FILES['files']);
        $createdQuestions += $result['questions'];
        $createdPromo += $result['promo'];
        $createdAnswers += $result['answers'];
        $bannerSaved = $result['banner'] || $bannerSaved;
        $errors = array_merge($errors, $result['errors']);
    }

    return [
        'questions' => $createdQuestions,
        'promo'     => $createdPromo,
        'answers'   => $createdAnswers,
        'banner'    => $bannerSaved,
        'errors'    => $errors,
    ];
}

function processZipUpload(int $eventId, array $file): array
{
    error_log("processZipUpload: Received ZIP file: " . $file['name'] . ", size: " . $file['size'] . ", error: " . $file['error']);
    $zipPath = $file['tmp_name'];
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        error_log("processZipUpload: Failed to open ZIP file");
        return ['questions' => 0, 'promo' => 0, 'answers' => 0, 'banner' => false, 'errors' => ['Could not open ZIP file']];
    }
    error_log("processZipUpload: ZIP opened successfully, numFiles: " . $zip->numFiles);

    $slidesData = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->statIndex($i);
        $name = $entry['name'];

        if (str_ends_with($name, '/') || !preg_match('/\.(png|jpe?g|webp)$/i', $name)) {
            continue;
        }

        $content = $zip->getFromIndex($i);
        if ($content === false) continue;

        $tmpName = sys_get_temp_dir() . '/scimath_batch_' . bin2hex(random_bytes(8)) . '_' . basename($name);
        file_put_contents($tmpName, $content);

        try {
            validateImageFile($tmpName);
            $slidesData[] = [
                'filename' => basename($name),
                'tempPath' => $tmpName,
            ];
        } catch (RuntimeException $e) {
            error_log("processZipUpload: Validation failed for $name: " . $e->getMessage());
            @unlink($tmpName);
        }
    }
    error_log("processZipUpload: Total valid images extracted: " . count($slidesData));
    $zip->close();
    @unlink($zipPath);

    return saveSlidesFromData($eventId, $slidesData);
}

function processMultiUpload(int $eventId, array $files): array
{
    $slidesData = [];
    $count = count($files['name']);
    $uploadedCount = 0;
    $skippedCount = 0;
    $maxFiles = (int) ini_get('max_file_uploads');
    
    error_log("processMultiUpload: Received $count files, max_file_uploads=$maxFiles");
    for ($i = 0; $i < $count; $i++) {
        error_log("processMultiUpload: File $i - name=" . basename($files['name'][$i]) . ", error=" . $files['error'][$i] . ", tmp_name=" . $files['tmp_name'][$i]);
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) {
                // This can happen when max_file_uploads limit is reached
                $skippedCount++;
                continue;
            }
            continue;
        }
        $originalName = basename($files['name'][$i]);
        if (!preg_match('/\.(png|jpe?g|webp)$/i', $originalName)) continue;

        $tmpName = $files['tmp_name'][$i];
        try {
            validateImageFile($tmpName);
            $slidesData[] = [
                'filename' => $originalName,
                'tempPath' => $tmpName,
            ];
            $uploadedCount++;
        } catch (RuntimeException $e) {
            error_log("processMultiUpload: Validation failed for $originalName: " . $e->getMessage());
        }
    }
    
    // Check if files were skipped due to upload limits
    if ($skippedCount > 0 || $count > $maxFiles) {
        error_log("processMultiUpload: $count files submitted, $uploadedCount processed, $skippedCount skipped (max_file_uploads limit: $maxFiles)");
    }

    return saveSlidesFromData($eventId, $slidesData);
}

function saveSlidesFromData(int $eventId, array $slidesData): array
{
    if (empty($slidesData)) {
        $maxFiles = (int) ini_get('max_file_uploads');
        $postMaxSize = ini_get('post_max_size');
        $uploadMaxSize = ini_get('upload_max_filesize');
        $msg = "No valid images found. ";
        if ($maxFiles < 100) {
            $msg .= "PHP max_file_uploads limit is $maxFiles (you uploaded 100 files). ";
            $msg .= "Please use a ZIP file instead, or increase max_file_uploads in php.ini. ";
        }
        $msg .= "post_max_size: $postMaxSize, upload_max_filesize: $uploadMaxSize.";
        return ['questions' => 0, 'promo' => 0, 'answers' => 0, 'banner' => false, 'errors' => [$msg]];
    }

    // Get classifications from POST
    $classificationsRaw = trim((string) ($_POST['slides'] ?? '[]'));
    $classifications = json_decode($classificationsRaw, true);
    if (!is_array($classifications)) $classifications = [];

    $saveBanner = isset($_POST['save_banner']) && $_POST['save_banner'] === '1';

    // Index slides by their order
    $slidesByIndex = [];
    foreach ($slidesData as $index => $slide) {
        $slidesByIndex[$index] = $slide;
    }

    $promoItems = [];
    $questionItems = [];
    $answerItems = [];
    $bannerPath = null;

    foreach ($classifications as $cls) {
        $idx = (int) ($cls['index'] ?? -1);
        $type = (string) ($cls['type'] ?? '');
        $difficulty = (string) ($cls['difficulty'] ?? 'easy');
        $slide = $slidesByIndex[$idx] ?? null;
        if (!$slide) continue;

        switch ($type) {
            case 'banner':
                if ($saveBanner && $bannerPath === null) {
                    try {
                        $event = Event::find($eventId);
                        Uploader::delete($event['cover_image_path']);
                        $bannerPath = Uploader::handleImageFromPath($slide['tempPath'], 'covers', $eventId);
                    } catch (RuntimeException $e) {
                        // Ignore
                    }
                }
                @unlink($slide['tempPath']);
                break;

            case 'promotional':
                try {
                    $fpath = Uploader::handleImageFromPath($slide['tempPath'], 'promotional', $eventId);
                    $promoItems[] = [
                        'title'       => preg_replace('/\.(png|jpe?g|webp)$/i', '', $slide['filename']),
                        'description' => '',
                        'file_path'   => $fpath,
                        'is_active'   => true,
                    ];
                } catch (RuntimeException $e) {
                    // Skip
                }
                @unlink($slide['tempPath']);
                break;

            case 'question':
                try {
                    $qpath = Uploader::handleImageFromPath($slide['tempPath'], 'questions', $eventId);
                    $questionItems[] = [
                        'image_path' => $qpath,
                        'is_active'  => true,
                        'round'      => $difficulty,
                    ];
                } catch (RuntimeException $e) {
                    // Skip
                }
                @unlink($slide['tempPath']);
                break;

            case 'answer':
                try {
                    $apath = Uploader::handleImageFromPath($slide['tempPath'], 'answer_slides', $eventId);
                    $answerItems[] = [
                        'image_path' => $apath,
                        'is_active'  => true,
                    ];
                } catch (RuntimeException $e) {
                    // Skip
                }
                @unlink($slide['tempPath']);
                break;
        }
    }

    // Assign question numbers and save questions
    $startNum = Question::nextQuestionNumber($eventId);
    foreach ($questionItems as $i => &$item) {
        $item['question_number'] = $startNum + $i;
        $item['display_order'] = count(Question::forEvent($eventId)) + $i;
    }
    unset($item);

    $createdQuestions = [];
    if (!empty($questionItems)) {
        try {
            $createdQuestions = Question::createBatch($eventId, $questionItems);
        } catch (PDOException $e) {
            // Handle duplicate
        }
    }

    // Pair answer slides to questions in order
    $pairedAnswers = [];
    $ansCount = min(count($answerItems), count($questionItems));
    for ($i = 0; $i < $ansCount; $i++) {
        $qid = isset($createdQuestions[$i]) ? (int) $createdQuestions[$i]['id'] : null;
        $pairedAnswers[] = array_merge($answerItems[$i], [
            'question_id' => $qid,
            'display_order' => $i,
        ]);
    }
    for ($i = $ansCount; $i < count($answerItems); $i++) {
        $pairedAnswers[] = array_merge($answerItems[$i], ['question_id' => null, 'display_order' => $i]);
    }

    $createdPromo = 0;
    $createdAnswers = 0;

    if (!empty($promoItems)) {
        try {
            PromotionalSlide::createBatch($eventId, $promoItems);
            $createdPromo = count($promoItems);
        } catch (Throwable $e) {
            // Ignore
        }
    }

    if (!empty($pairedAnswers)) {
        try {
            AnswerSlide::createBatch($eventId, $pairedAnswers);
            $createdAnswers = count($pairedAnswers);
        } catch (Throwable $e) {
            // Ignore
        }
    }

    if ($bannerPath !== null) {
        try {
            Event::update($eventId, ['cover_image_path' => $bannerPath]);
        } catch (Throwable $e) {
            // Ignore
        }
    }

    return [
        'questions' => count($createdQuestions),
        'promo'     => $createdPromo,
        'answers'   => $createdAnswers,
        'banner'    => $bannerPath !== null,
    ];
}

function validateImageFile(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Cannot read file.');
    }
    $size = filesize($path);
    $config = require __DIR__ . '/../../../config/app.php';
    $uploadMaxBytes = $config['uploads']['max_bytes'];
    if ($size <= 0 || $size > $uploadMaxBytes) {
        throw new RuntimeException('File size invalid.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $path);
    finfo_close($finfo);
    $mime = $mime ?: 'application/octet-stream';

    $allowedMimes = $config['uploads']['allowed_mime_types'];
    if (!isset($allowedMimes[$mime])) {
        throw new RuntimeException('Unsupported file type.');
    }

    $imageInfo = @getimagesize($path);
    if ($imageInfo === false) {
        throw new RuntimeException('File is not a valid image.');
    }

    $image = match ($mime) {
        'image/png'  => @imagecreatefrompng($path),
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default      => false,
    };
    if ($image === false) {
        throw new RuntimeException('Image could not be decoded.');
    }
    imagedestroy($image);

    return ['mime' => $mime, 'size' => $size];
}