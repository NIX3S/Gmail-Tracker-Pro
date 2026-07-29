<?php
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

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Payload invalide']);
    exit;
}

try {
    $trackingId = bin2hex(random_bytes(16));

    $category = isset($data['category']) ? trim(substr((string) $data['category'], 0, 100)) : null;
    $category = ($category === '') ? null : $category;

    storage()->createEmail(
        $trackingId,
        $userId,
        substr($data['subject'] ?? '', 0, 500),
        substr($data['recipient'] ?? '', 0, 320),
        $category
    );

    $pixelUrl = APP_BASE_URL . '/track.php?id=' . $trackingId;

    // Les liens sont signés ICI, côté serveur, avec le secret APP_SECRET.
    // L'extension ne connaît jamais ce secret : elle ne peut donc pas forger de faux liens,
    // et click.php peut vérifier la signature avant de rediriger (fini l'open redirect).
    $signedLinks = [];
    if (!empty($data['links']) && is_array($data['links'])) {
        foreach ($data['links'] as $originalUrl) {
            $originalUrl = (string) $originalUrl;
            $scheme = parse_url($originalUrl, PHP_URL_SCHEME);
            if (!in_array($scheme, ['http', 'https'], true)) {
                continue; // on ignore silencieusement les schémas non autorisés
            }
            $sig = sign_click($trackingId, $originalUrl);
            $signedLinks[$originalUrl] = APP_BASE_URL . '/click.php?id=' . $trackingId
                . '&url=' . urlencode($originalUrl) . '&sig=' . $sig;
        }
    }

    echo json_encode([
        'status' => 'success',
        'tracking_id' => $trackingId,
        'pixel_url' => $pixelUrl,
        'signed_links' => $signedLinks,
    ]);
} catch (\Throwable $e) {
    safe_log_error('register_email.php', $e);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur']);
}
