<?php
require_once __DIR__ . '/config.php';

$trackingId = $_GET['id'] ?? '';
$docUuid = $_GET['doc'] ?? '';

if (!preg_match('/^[a-f0-9]{32}$/', $trackingId) || !preg_match('/^[a-f0-9]{32}$/', $docUuid)) {
    http_response_code(404);
    exit;
}

$doc = storage()->getDocument($docUuid, $trackingId);

if (!$doc) {
    http_response_code(404);
    exit;
}

$path = UPLOAD_DIR . '/' . $doc['stored_path'];
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . ($doc['mime_type'] ?: 'application/octet-stream'));
header('Content-Disposition: inline; filename="' . basename($doc['original_name']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
