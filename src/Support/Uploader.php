<?php

/**
 * Secure image upload handling for event branding, question slides, and
 * contestant logos.
 *
 * Defense layers, in order:
 *   1. Reject PHP upload errors outright (partial upload, no file, etc).
 *   2. Enforce a max file size BEFORE touching the file content.
 *   3. Never trust the client-supplied filename or MIME type -- detect the
 *      real MIME type server-side via fileinfo (magic bytes), and cross-check
 *      it against the allow-list.
 *   4. Confirm the file actually decodes as an image using getimagesize()
 *      AND by successfully loading it with GD -- rejects polyglot files
 *      (e.g. a GIF89a header glued to embedded PHP) that pass a naive MIME
 *      sniff but aren't real, single-purpose images.
 *   5. Re-encode the image via GD and write out a fresh file. This strips
 *      any non-pixel-data payload (embedded scripts, EXIF-based exploits,
 *      trailing appended data) that survived the checks above -- the file
 *      written to disk is always a clean, freshly-generated image, never a
 *      byte-for-byte copy of what was uploaded.
 *   6. Generate the on-disk filename ourselves (random bytes + a
 *      server-determined extension) -- the original filename is discarded
 *      entirely, so it can't be used for path traversal or to smuggle a
 *      double extension like "logo.png.php".
 *   7. Store files outside of any web-executable PHP directory logic (the
 *      uploads directory ships with a .htaccess denying script execution --
 *      see public/uploads/.htaccess) as a defense-in-depth backstop even
 *      though re-encoding already removes executable content.
 */
class Uploader
{
    /**
     * @param array $file One entry from $_FILES, e.g. $_FILES['logo']
     * @param string $subdirectory e.g. "logos", "covers", "questions", "contestants"
     * @param int $eventId Used to namespace uploads per event
     * @return string Relative path (from the uploads base_url) to store in the DB
     * @throws RuntimeException on any validation failure, with a user-safe message
     */
    public static function handleImage(array $file, string $subdirectory, int $eventId): string
    {
        $config = require __DIR__ . '/../../config/app.php';
        $rules = $config['uploads'];

        self::assertNoUploadError($file);
        self::assertWithinSizeLimit($file, $rules['max_bytes']);

        $detectedMime = self::detectRealMimeType($file['tmp_name']);
        if (!isset($rules['allowed_mime_types'][$detectedMime])) {
            throw new RuntimeException(
                'Unsupported file type. Please upload a PNG, JPG/JPEG, or WebP image.'
            );
        }
        $extension = $rules['allowed_mime_types'][$detectedMime];

        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            throw new RuntimeException('The uploaded file is not a valid image.');
        }

        $image = self::loadImageResource($file['tmp_name'], $detectedMime);
        if ($image === false) {
            throw new RuntimeException('The uploaded image could not be processed. It may be corrupted.');
        }

        $targetDir = rtrim($rules['base_path'], '/') . '/' . $subdirectory . '/' . $eventId;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Could not create the upload directory.');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = $targetDir . '/' . $filename;

        $written = self::writeImageResource($image, $destination, $detectedMime);
        imagedestroy($image);

        if (!$written) {
            throw new RuntimeException('Failed to save the uploaded image.');
        }

        chmod($destination, 0644);

        return $rules['base_url'] . '/' . $subdirectory . '/' . $eventId . '/' . $filename;
    }

    /**
     * Deletes a previously stored upload (identified by the relative path
     * returned from handleImage()). Silently no-ops if the path is empty or
     * the file no longer exists -- callers use this when replacing or
     * removing an asset and shouldn't have to special-case "no old file".
     */
    public static function delete(?string $relativePath): void
    {
        if ($relativePath === null || $relativePath === '') {
            return;
        }

        $config = require __DIR__ . '/../../config/app.php';
        $rules = $config['uploads'];

        $baseUrl = rtrim($rules['base_url'], '/') . '/';
        if (!str_starts_with($relativePath, $baseUrl)) {
            // Not one of our managed paths; refuse to touch it.
            return;
        }

        $withinBase = substr($relativePath, strlen($baseUrl));
        // Guard against ../ path traversal in a stored value, however
        // unlikely, before building a filesystem path from it.
        if (str_contains($withinBase, '..')) {
            return;
        }

        $fullPath = rtrim($rules['base_path'], '/') . '/' . $withinBase;
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    private static function assertNoUploadError(array $file): void
    {
        if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('No file was uploaded.');
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $messages = [
                UPLOAD_ERR_INI_SIZE   => 'The file exceeds the server\'s maximum upload size.',
                UPLOAD_ERR_FORM_SIZE  => 'The file exceeds the maximum upload size.',
                UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded. Please try again.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server upload configuration error (no temp directory).',
                UPLOAD_ERR_CANT_WRITE => 'Server upload configuration error (cannot write file).',
                UPLOAD_ERR_EXTENSION  => 'The upload was blocked by a server extension.',
            ];

            throw new RuntimeException($messages[$file['error']] ?? 'The file upload failed.');
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            // Defends against attempts to reference an arbitrary server path
            // instead of a genuine browser upload.
            throw new RuntimeException('Invalid upload.');
        }
    }

    private static function assertWithinSizeLimit(array $file, int $maxBytes): void
    {
        if ($file['size'] <= 0 || $file['size'] > $maxBytes) {
            $maxMb = round($maxBytes / (1024 * 1024), 1);
            throw new RuntimeException("The file must be a valid image no larger than {$maxMb} MB.");
        }
    }

    private static function detectRealMimeType(string $tmpPath): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        return $mime ?: 'application/octet-stream';
    }

    /** @return \GdImage|false */
    private static function loadImageResource(string $tmpPath, string $mime)
    {
        return match ($mime) {
            'image/png'  => @imagecreatefrompng($tmpPath),
            'image/jpeg' => @imagecreatefromjpeg($tmpPath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : false,
            default      => false,
        };
    }

    private static function writeImageResource($image, string $destination, string $mime): bool
    {
        return match ($mime) {
            'image/png'  => imagepng($image, $destination, 6),
            'image/jpeg' => imagejpeg($image, $destination, 88),
            'image/webp' => function_exists('imagewebp') ? imagewebp($image, $destination, 85) : false,
            default      => false,
        };
    }
}
