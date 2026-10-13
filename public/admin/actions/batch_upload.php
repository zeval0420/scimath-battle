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
    echo json_encode(['error' => 'Security check failed.']);
    exit;
}

require_once __DIR__ . '/../../../src/Support/Uploader.php';

$config = require __DIR__ . '/../../../config/app.php';
$uploadMaxBytes = $config['uploads']['max_bytes'];
$allowedMimes = $config['uploads']['allowed_mime_types'];

/**
 * Validate an image file at $path and return ['mime'=>..., 'size'=>...] or
 * throw RuntimeException on failure.
 */
function validateImageFile(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Cannot read file.');
    }
    $size = filesize($path);
    if ($size <= 0 || $size > $uploadMaxBytes) {
        $maxMb = round($uploadMaxBytes / (1024 * 1024), 1);
        throw new RuntimeException("File must be a valid image no larger than {$maxMb} MB.");
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $path);
    finfo_close($finfo);
    $mime = $mime ?: 'application/octet-stream';

    if (!isset($allowedMimes[$mime])) {
        throw new RuntimeException('Unsupported file type. Use PNG, JPG, or WebP.');
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
        throw new RuntimeException('Image could not be decoded — file may be corrupted.');
    }
    imagedestroy($image);

    return ['mime' => $mime, 'size' => $size];
}

/**
 * Generate a base64 data-URI preview of an image file.
 */
function previewBase64(string $path): string
{
    $data = base64_encode(file_get_contents($path));
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $path);
    finfo_close($finfo);
    $mime = $mime ?: 'application/octet-stream';

    return 'data:' . $mime . ';base64,' . $data;
}

$sessionId = bin2hex(random_bytes(8));
$staged = [];
$errors = [];

// ---- Single ZIP upload -----------------------------------------------
if (isset($_FILES['upload']) && $_FILES['upload']['error'] === UPLOAD_ERR_OK) {
    $zipPath = $_FILES['upload']['tmp_name'];
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        echo json_encode(['error' => 'Could not open ZIP file.']);
        exit;
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->statIndex($i);
        $name = $entry['name'];

        // Skip directories and non-image files
        if (str_ends_with($name, '/') || !preg_match('/\.(png|jpe?g|webp)$/i', $name)) {
            continue;
        }

        $content = $zip->getFromIndex($i);
        if ($content === false) {
            continue;
        }

        $tmpName = sys_get_temp_dir() . '/scimath_batch_' . $sessionId . '_' . bin2hex(random_bytes(4)) . '_' . basename($name);
        file_put_contents($tmpName, $content);

        try {
            $meta = validateImageFile($tmpName);
            $staged[] = [
                'index'  => count($staged),
                'filename' => basename($name),
                'preview'  => previewBase64($tmpName),
                'mime'     => $meta['mime'],
                'tempPath' => $tmpName,
                'source'   => 'zip',
            ];
        } catch (RuntimeException $e) {
            @unlink($tmpName);
            $errors[] = basename($name) . ': ' . $e->getMessage();
        }
    }
    $zip->close();
    @unlink($zipPath);

// ---- Multi-file upload -----------------------------------------------
} elseif (isset($_FILES['files']) && is_array($_FILES['files']['name'])) {
    $count = count($_FILES['files']['name']);
    for ($i = 0; $i < $count; $i++) {
        if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }
        $tmpName = $_FILES['files']['tmp_name'][$i];
        $originalName = basename($_FILES['files']['name'][$i]);

        // Skip non-image files
        if (!preg_match('/\.(png|jpe?g|webp)$/i', $originalName)) {
            continue;
        }

        try {
            $meta = validateImageFile($tmpName);
            $staged[] = [
                'index'    => count($staged),
                'filename' => $originalName,
                'preview'  => previewBase64($tmpName),
                'mime'     => $meta['mime'],
                'tempPath' => $tmpName,
                'source'   => 'multi',
            ];
        } catch (RuntimeException $e) {
            $errors[] = $originalName . ': ' . $e->getMessage();
        }
    }

// ---- Nothing uploaded ------------------------------------------------
} else {
    echo json_encode(['error' => 'No files provided.']);
    exit;
}

if ($staged === []) {
    echo json_encode(['error' => 'No valid images were found in the upload.']);
    exit;
}

$_SESSION['batch_upload_' . $sessionId] = [
    'images' => $staged,
    'errors' => $errors,
    'created_at' => time(),
];

// Clean up staged entries older than 30 minutes
foreach ($_SESSION as $key => $value) {
    if (str_starts_with($key, 'batch_upload_') && isset($value['created_at']) && (time() - $value['created_at']) > 1800) {
        foreach ($value['images'] ?? [] as $img) {
            @unlink($img['tempPath'] ?? '');
        }
        unset($_SESSION[$key]);
    }
}

echo json_encode([
    'sessionId' => $sessionId,
    'images'    => $staged,
    'errors'    => $errors,
]);
