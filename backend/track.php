<?php
require_once __DIR__ . '/config.php';

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate');

$trackingId = $_GET['id'] ?? '';

// Valide le format UUID pour éviter d'insérer n'importe quoi
if (preg_match('/^[a-f0-9\-]{8,64}$/i', $trackingId)) {
    try {
        storage()->addEvent(
            $trackingId,
            'open',
            null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
        );
    } catch (\Throwable $e) {
        safe_log_error('track.php', $e);
        // On continue quand même : le pixel doit toujours se charger normalement
    }
}

// Toujours renvoyer un vrai pixel transparent, même en cas d'ID invalide
readfile(__DIR__ . '/assets/transparent.png');
