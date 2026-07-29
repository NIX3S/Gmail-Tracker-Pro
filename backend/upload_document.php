<?php
/**
 * Ce endpoint remplace l'ancienne logique de "fausse pièce jointe" / substitution silencieuse
 * de PDF (csave.ts). Ici, l'utilisateur choisit EXPLICITEMENT depuis la popup de l'extension
 * d'envoyer un document en mode "consultation trackée", comme le fait DocSend/HubSpot :
 *  - le vrai fichier est uploadé sur le serveur
 *  - un lien de visualisation clairement identifié est renvoyé
 *  - c'est l'utilisateur qui décide de coller ce lien dans son email (rien n'est fait à son insu)
 */

require_once __DIR__ . '/config.php';
install_json_error_boundary();
require_once __DIR__ . '/api_auth.php';

header('Content-Type: application/json; charset=utf-8');
// Ces requêtes viennent du service worker de l'extension (avec permission Chrome explicite,
// voir manifest.json + options.js), ce qui contourne CORS côté navigateur de toute façon.
// On garde un header permissif ici uniquement en secours pour un test direct (curl, Postman...) :
// ces endpoints sont protégés par token, pas par cookie/session, donc '*' n'introduit pas de CSRF.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$userId = authenticate_api_request();
if (!$userId) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Non authentifie']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Fichier manquant']);
    exit;
}

$file = $_FILES['file'];

// Limite de taille (20 Mo) et types autorisés
$allowedMime = ['application/pdf', 'image/png', 'image/jpeg',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Erreur upload']);
    exit;
}
if ($file['size'] > 20 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['status' => 'error', 'message' => 'Fichier trop volumineux']);
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mime, $allowedMime, true)) {
    http_response_code(415);
    echo json_encode(['status' => 'error', 'message' => 'Type de fichier non autorise']);
    exit;
}

try {
    $trackingId = bin2hex(random_bytes(16));
    $docUuid = bin2hex(random_bytes(16));

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }
    $storedName = $docUuid . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $file['name']);
    $storedPath = UPLOAD_DIR . '/' . $storedName;

    if (!move_uploaded_file($file['tmp_name'], $storedPath)) {
        throw new RuntimeException('Impossible de déplacer le fichier uploadé');
    }

    $category = isset($_POST['category']) ? trim(substr((string) $_POST['category'], 0, 100)) : null;
    $category = ($category === '') ? null : $category;

    storage()->createEmail(
        $trackingId,
        $userId,
        $_POST['subject'] ?? null,
        $_POST['recipient'] ?? null,
        $category
    );

    storage()->createDocument(
        $docUuid,
        $trackingId,
        $file['name'],
        $storedName,
        $mime
    );

    echo json_encode([
        'status' => 'success',
        'tracking_id' => $trackingId,
        'doc_uuid' => $docUuid,
        'viewer_url' => APP_BASE_URL . '/viewer.php?id=' . $trackingId . '&doc=' . $docUuid,
    ]);
} catch (\Throwable $e) {
    safe_log_error('upload_document.php', $e);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur']);
}
