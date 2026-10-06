<?php

/**
 * Export endpoint for scorer interface.
 * Returns scores in Excel or PDF format.
 */
require_once __DIR__ . '/../../src/bootstrap.php';
Auth::requireAdmin();

$eventId = (int) ($_GET['event_id'] ?? 0);
$format = (string) ($_GET['format'] ?? 'excel');

if ($eventId <= 0) {
    header('HTTP/1.1 400 Bad Request');
    exit('Invalid event_id');
}

$event = Event::find($eventId);
if (!$event) {
    header('HTTP/1.1 404 Not Found');
    exit('Event not found');
}

$dashboard = CompetitionRuntime::getDashboard($eventId);
$rankings = $dashboard['rankings'] ?? [];
$scores = CompetitionRuntime::getScores($eventId);

if ($format === 'excel') {
    // Generate CSV (Excel-compatible)
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="scimath_scores_' . preg_replace('/[^a-zA-Z0-9]/', '_', $event['name']) . '.csv"');
    
    $fp = fopen('php://output', 'w');
    
    // Header
    fputcsv($fp, [
        'Rank',
        'Contestant Name',
        'Team Code',
        'Acronym',
        'Starting Score',
        'Total Score'
    ]);
    
    // Data
    foreach ($rankings as $r) {
        fputcsv($fp, [
            $r['rank'],
            $r['name'],
            $r['team_code'] ?? '',
            $r['acronym'] ?? '',
            $r['starting_score'] ?? 0,
            $r['total_score']
        ]);
    }
    
    fclose($fp);
    
} elseif ($format === 'pdf') {
    // Simple PDF generation using basic approach
    // For production, consider using a library like TCPDF or mPDF
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="scimath_scores_' . preg_replace('/[^a-zA-Z0-9]/', '_', $event['name']) . '.pdf"');
    
    // Basic PDF using PHP's built-in PDF functions or generate a simple text-based report
    // This is a placeholder - implement proper PDF generation as needed
    echo "%PDF-1.4\n";
    echo "This is a placeholder for PDF export. Implement TCPDF/mPDF for production.\n";
    echo "Event: " . $event['name'] . "\n";
    echo "Generated: " . date('Y-m-d H:i:s') . "\n\n";
    echo "Rankings:\n";
    foreach ($rankings as $r) {
        echo $r['rank'] . ". " . $r['name'] . " - " . $r['total_score'] . " points\n";
    }
} else {
    header('HTTP/1.1 400 Bad Request');
    exit('Invalid format. Use excel or pdf.');
}
