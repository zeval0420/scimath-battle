<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . relative_url('/admin/index.php'));
    exit;
}
Csrf::verify();

$eventId = (int) ($_POST['event_id'] ?? 0);
$slideId = (int) ($_POST['slide_id'] ?? 0);

require_once __DIR__ . '/../../../src/Models/PromotionalSlide.php';
PromotionalSlide::deleteWithFile($slideId);

Flash::success('Promotional slide deleted.');
header('Location: ' . relative_url('/admin/event.php?id=' . $eventId . '#promotional'));
