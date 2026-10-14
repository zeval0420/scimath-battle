<?php
// admin/event_create.php - event creation logic.
// Slides (one .zip or several images) are saved as promotional slides, ordered by file name.

const SLIDE_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const MAX_SLIDES = 200;

function create_event(mysqli $conn, array $post, ?array $files): array
{
    $name     = trim($post['name'] ?? '');
    $subtitle = trim($post['subtitle'] ?? '');
    $date     = trim($post['date'] ?? '');
    $errors   = [];

    if ($name === '' || mb_strlen($name) > 150) $errors[] = 'Name is required (max 150 characters).';
    if (mb_strlen($subtitle) > 255) $errors[] = 'Subtitle is too long (max 255 characters).';
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) $errors[] = 'A valid date is required.';

    $uploads = normalize_uploads($files, $errors);
    if ($errors) return ['ok' => false, 'errors' => $errors];

    $uploadRoot = null;
    $conn->begin_transaction();
    try {
        $sub = $subtitle === '' ? null : $subtitle;
        $stmt = $conn->prepare("INSERT INTO scimath_events (name, subtitle, event_date, status) VALUES (?, ?, ?, 'draft')");
        $stmt->bind_param('sss', $name, $sub, $date);
        $stmt->execute();
        $eventId = (int)$conn->insert_id;

        $conn->query("INSERT INTO scimath_event_settings (event_id) VALUES ($eventId)");
        $conn->query("INSERT INTO scimath_competition_sessions (event_id) VALUES ($eventId)");

        if ($uploads) {
            $relDir     = "uploads/events/$eventId/slides";
            $uploadRoot = __DIR__ . "/../uploads/events/$eventId";
            $absDir     = __DIR__ . "/../$relDir";
            if (!is_dir($absDir) && !mkdir($absDir, 0775, true)) {
                throw new RuntimeException('Could not create the upload folder.');
            }
            $saved = save_slides($uploads, $absDir);
            if (!$saved) throw new RuntimeException('No valid images were found in the upload.');

            $stmt = $conn->prepare(
                "INSERT INTO scimath_promotional_slides (event_id, title, file_path, file_type, display_order)
                 VALUES (?, ?, ?, 'image', ?)"
            );
            foreach ($saved as $i => $fileName) {
                $title = pathinfo($fileName, PATHINFO_FILENAME);
                $path  = "$relDir/$fileName";
                $order = $i + 1;
                $stmt->bind_param('issi', $eventId, $title, $path, $order);
                $stmt->execute();
            }
        }
        $conn->commit();
        return ['ok' => true, 'id' => $eventId];
    } catch (Throwable $e) {
        $conn->rollback();
        if ($uploadRoot) remove_dir($uploadRoot);
        return ['ok' => false, 'errors' => ['Could not create the event: ' . $e->getMessage()]];
    }
}

/** Flatten $_FILES['slides'] into [['name','tmp','kind'=>'zip|image'], ...] and validate. */
function normalize_uploads(?array $files, array &$errors): array
{
    $out = [];
    if (!$files || !isset($files['name'])) return $out;
    foreach ((array)$files['name'] as $i => $name) {
        $err = $files['error'][$i];
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if ($err !== UPLOAD_ERR_OK) {
            $errors[] = "Upload failed for \"$name\" (error code $err; check upload_max_filesize / post_max_size).";
            continue;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'zip') {
            $kind = 'zip';
        } elseif (in_array($ext, SLIDE_EXTS, true) && @getimagesize($files['tmp_name'][$i]) !== false) {
            $kind = 'image';
        } else {
            $errors[] = "\"$name\" is not a supported file (use a .zip or images: " . implode(', ', SLIDE_EXTS) . ').';
            continue;
        }
        $out[] = ['name' => $name, 'tmp' => $files['tmp_name'][$i], 'kind' => $kind];
    }
    $zips = count(array_filter($out, fn($u) => $u['kind'] === 'zip'));
    if ($zips > 1 || ($zips === 1 && count($out) > 1)) {
        $errors[] = 'Upload either one zip file or multiple images, not a mix.';
    }
    if (count($out) > MAX_SLIDES) $errors[] = 'Too many files (max ' . MAX_SLIDES . ').';
    return $out;
}

/** Writes slides into $absDir as 001.ext, 002.ext, ... in natural file-name order. Returns saved file names. */
function save_slides(array $uploads, string $absDir): array
{
    $items = [];
    $zip = null;
    if ($uploads[0]['kind'] === 'zip') {
        $zip = new ZipArchive();
        if ($zip->open($uploads[0]['tmp']) !== true) throw new RuntimeException('The zip file could not be opened.');
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if (substr($entry, -1) === '/' || stripos($entry, '__MACOSX/') !== false) continue;
            $base = basename($entry);
            $ext  = strtolower(pathinfo($base, PATHINFO_EXTENSION));
            if ($base[0] === '.' || !in_array($ext, SLIDE_EXTS, true)) continue;
            $items[] = ['sort' => $entry, 'ext' => $ext, 'zip' => $i];
        }
    } else {
        foreach ($uploads as $u) {
            $items[] = ['sort' => $u['name'], 'ext' => strtolower(pathinfo($u['name'], PATHINFO_EXTENSION)), 'tmp' => $u['tmp']];
        }
    }
    if (count($items) > MAX_SLIDES) throw new RuntimeException('Too many slides (max ' . MAX_SLIDES . ').');
    usort($items, fn($a, $b) => strnatcasecmp($a['sort'], $b['sort']));

    $saved = [];
    foreach ($items as $n => $it) {
        $fileName = sprintf('%03d.%s', count($saved) + 1, $it['ext']);
        $dest = "$absDir/$fileName";
        if (isset($it['zip'])) {
            $data = $zip->getFromIndex($it['zip']);
            if ($data === false || @getimagesizefromstring($data) === false) continue; // not a real image
            file_put_contents($dest, $data);
        } else {
            if (!move_uploaded_file($it['tmp'], $dest)) throw new RuntimeException('Could not save an uploaded image.');
        }
        $saved[] = $fileName;
    }
    if ($zip) $zip->close();
    return $saved;
}

function remove_dir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}
