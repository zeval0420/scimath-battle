<?php
// config/schema.php - small one-time schema additions, safe to run on every request.
// DDL commits any open transaction, so call this before begin_transaction().

function ensure_correct_audio_column(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $r = $conn->query("SELECT 1 FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scimath_event_settings' AND COLUMN_NAME = 'correct_audio_path'");
    if ($r && !$r->fetch_row()) {
        $conn->query('ALTER TABLE scimath_event_settings ADD COLUMN correct_audio_path VARCHAR(255) NULL DEFAULT NULL');
    }
}
