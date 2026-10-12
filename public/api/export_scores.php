<?php

/**
 * Export endpoint for the scorer interface.
 *
 *   GET ?event_id=N&format=excel   -> CSV download (opens in Excel/Sheets)
 *   GET ?event_id=N&format=csv     -> same as excel
 *   GET ?event_id=N&format=pdf     -> printable HTML report (auto-opens the
 *                                     browser's Print dialog to "Save as PDF")
 *
 * All formats render the same plain "quiz bee" table: rows are contestants,
 * columns are questions, plus total and rank.
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Support/ScoreReport.php';
Auth::requireAdmin();

$eventId = (int) ($_GET['event_id'] ?? 0);
$format = strtolower((string) ($_GET['format'] ?? 'excel'));

if ($eventId <= 0) {
    header('HTTP/1.1 400 Bad Request');
    exit('Invalid event_id');
}

$event = Event::find($eventId);
if ($event === null) {
    header('HTTP/1.1 404 Not Found');
    exit('Event not found');
}

$report = ScoreReport::build($eventId);

switch ($format) {
    case 'excel':
    case 'csv':
        ScoreReport::streamCsv($report);
        break;

    case 'pdf':
    case 'print':
        ScoreReport::renderPrintable($report, $format === 'pdf');
        break;

    default:
        header('HTTP/1.1 400 Bad Request');
        exit('Invalid format. Use excel, csv, or pdf.');
}
