<?php
require_once __DIR__ . '/config.php';
install_json_error_boundary();

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload) || empty($payload['tracking_id']) || empty($payload['event'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Payload invalide']);
    exit;
}

$trackingId = $payload['tracking_id'];
$event = $payload['event'];

$allowedEvents = ['doc_open', 'doc_scroll', 'doc_time', 'doc_close'];
if (!preg_match('/^[a-f0-9\-]{8,64}$/i', $trackingId) || !in_array($event, $allowedEvents, true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Donnees invalides']);
    exit;
}

// On ne garde que des méta-données whitelistées, en JSON structuré (pas de texte libre concaténé)
$meta = [];
foreach (['scrollDepth', 'duration', 'file'] as $key) {
    if (isset($payload[$key])) {
        $meta[$key] = $payload[$key];
    }
}

try {
    storage()->addEvent(
        $trackingId,
        $event,
        $meta,
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
    );
    echo json_encode(['status' => 'success']);
} catch (\Throwable $e) {
    safe_log_error('api_event.php', $e);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur']);
}
