<?php
require_once __DIR__ . '/config.php';

$trackingId = $_GET['id'] ?? '';
$url        = $_GET['url'] ?? '';
$sig        = $_GET['sig'] ?? '';

http_response_code(400);
header('Content-Type: text/plain; charset=utf-8');

if (!preg_match('/^[a-f0-9\-]{8,64}$/i', $trackingId) || $url === '' || $sig === '') {
    echo 'Requete invalide.';
    exit;
}

// Le lien n'a été signé que par NOTRE serveur au moment de la génération de l'email tracké.
// Sans signature valide, impossible de rediriger -> plus d'open redirect exploitable par un tiers.
if (!verify_click($trackingId, $url, $sig)) {
    echo 'Lien invalide ou expire.';
    exit;
}

// On n'autorise que http/https, pour eviter javascript:, data:, etc.
$scheme = parse_url($url, PHP_URL_SCHEME);
if (!in_array($scheme, ['http', 'https'], true)) {
    echo 'Schema d\'URL non autorise.';
    exit;
}

try {
    storage()->addEvent(
        $trackingId,
        'click',
        ['url' => $url],
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
    );
} catch (\Throwable $e) {
    safe_log_error('click.php', $e);
    // On redirige quand même : le suivi ne doit jamais bloquer l'utilisateur
}

http_response_code(302);
header('Location: ' . $url);
exit;
