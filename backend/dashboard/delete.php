<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo 'Requête invalide (jeton CSRF manquant ou expiré). Retourne au dashboard et réessaie.';
    exit;
}

$action = $_POST['action'] ?? '';
$removedPaths = [];

try {
    if ($action === 'all') {
        $removedPaths = storage()->deleteAllData();
    } elseif ($action === 'category') {
        $category = $_POST['category'] ?? '';
        $rows = storage()->listAllEmailsWithCounts(100000);
        foreach ($rows as $row) {
            $rowCategory = $row['category'] ?? '';
            if ($rowCategory === $category) {
                $removedPaths = array_merge($removedPaths, storage()->deleteEmail($row['tracking_id']));
            }
        }
    } elseif ($action === 'single') {
        $trackingId = $_POST['tracking_id'] ?? '';
        if (preg_match('/^[a-f0-9\-]{8,64}$/i', $trackingId)) {
            $removedPaths = storage()->deleteEmail($trackingId);
        }
    } else {
        http_response_code(400);
        echo 'Action inconnue.';
        exit;
    }

    // Nettoyage des fichiers physiquement uploadés correspondant aux documents supprimés
    foreach ($removedPaths as $path) {
        $fullPath = UPLOAD_DIR . '/' . basename($path);
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
} catch (\Throwable $e) {
    safe_log_error('dashboard/delete.php', $e);
    http_response_code(500);
    echo 'Erreur serveur pendant la suppression. Vérifie les logs.';
    exit;
}

header('Location: /dashboard/index.php');
exit;
