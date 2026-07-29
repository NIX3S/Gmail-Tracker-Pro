<?php
require_once __DIR__ . '/config.php';
install_json_error_boundary();
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

start_secure_session();
if (empty($_SESSION['dashboard_authenticated'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentification requise']);
    exit;
}

$trackingId = $_GET['tracking_id'] ?? null;

if ($trackingId) {
    $email = storage()->getEmail($trackingId);

    if (!$email) {
        http_response_code(404);
        echo json_encode(['error' => 'Email introuvable']);
        exit;
    }

    $events = storage()->getEvents($trackingId);

    $opens = array_values(array_filter($events, fn($e) => $e['event_type'] === 'open'));
    $clicks = array_values(array_filter($events, fn($e) => $e['event_type'] === 'click'));
    $docTimeTotal = array_sum(array_map(
        fn($e) => json_decode($e['meta'] ?? '{}', true)['duration'] ?? 0,
        array_filter($events, fn($e) => $e['event_type'] === 'doc_close')
    ));

    echo json_encode([
        'trackingID' => $email['tracking_id'],
        'subject' => $email['subject'],
        'recipient' => $email['recipient'],
        'createdAt' => $email['created_at'],
        'opens' => count($opens),
        'clicks' => count($clicks),
        'timeSpentSeconds' => $docTimeTotal,
        'events' => $events,
    ]);
    exit;
}

// Sinon: liste de tous les emails trackés avec compteurs
echo json_encode(storage()->listAllEmailsWithCounts());
