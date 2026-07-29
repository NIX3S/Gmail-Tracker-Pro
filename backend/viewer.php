<?php
require_once __DIR__ . '/config.php';

$trackingId = $_GET['id'] ?? '';
$docUuid = $_GET['doc'] ?? '';

if (!preg_match('/^[a-f0-9]{32}$/', $trackingId) || !preg_match('/^[a-f0-9]{32}$/', $docUuid)) {
    http_response_code(404);
    echo 'Document introuvable.';
    exit;
}

$doc = storage()->getDocument($docUuid, $trackingId);

if (!$doc) {
    http_response_code(404);
    echo 'Document introuvable.';
    exit;
}

try {
    storage()->addEvent(
        $trackingId,
        'doc_open',
        ['file' => $doc['original_name']],
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
    );
} catch (\Throwable $e) {
    safe_log_error('viewer.php', $e);
}

$safeName = htmlspecialchars($doc['original_name'], ENT_QUOTES, 'UTF-8');
$fileUrl = APP_BASE_URL . '/download.php?doc=' . urlencode($docUuid) . '&id=' . urlencode($trackingId);
$mime = $doc['mime_type'] ?? '';

// Le rendu "no preview available" qu'on avait avec Google Docs Viewer sur les fichiers
// Office (.xlsx/.docx) vient du visualiseur lui-même, pas du tracking (l'évènement doc_open
// est déjà enregistré plus haut, indépendamment de ce qui s'affiche). On choisit maintenant
// le bon moteur de rendu selon le type de fichier :
//  - PDF : les navigateurs modernes ont un lecteur PDF natif, un simple <iframe> suffit.
//  - images : affichage direct, pas besoin de visualiseur externe.
//  - Word/Excel : Google Docs Viewer gère mal ces formats, on passe par Microsoft Office Online,
//    plus fiable pour du .docx/.xlsx.
if ($mime === 'application/pdf') {
    $viewerHtml = '<iframe src="' . htmlspecialchars($fileUrl, ENT_QUOTES) . '"></iframe>';
} elseif (in_array($mime, ['image/png', 'image/jpeg'], true)) {
    $viewerHtml = '<div class="image-wrap"><img src="' . htmlspecialchars($fileUrl, ENT_QUOTES) . '" alt=""></div>';
} elseif (in_array($mime, [
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
], true)) {
    $officeViewerUrl = 'https://view.officeapps.live.com/op/embed.aspx?src=' . urlencode($fileUrl);
    $viewerHtml = '<iframe src="' . htmlspecialchars($officeViewerUrl, ENT_QUOTES) . '"></iframe>';
} else {
    $viewerHtml = '<div class="no-preview">Aperçu non disponible pour ce type de fichier.<br>'
        . '<a href="' . htmlspecialchars($fileUrl, ENT_QUOTES) . '">Télécharger ' . $safeName . '</a></div>';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title><?= $safeName ?> — Document partagé</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    :root {
        --ink: #1c1c1e;
        --paper: #f7f5f2;
        --accent: #3d5a80;
        --line: #dcd7d0;
    }
    * { box-sizing: border-box; }
    body {
        margin: 0; font-family: -apple-system, "Segoe UI", Roboto, sans-serif;
        background: var(--paper); color: var(--ink);
        display: flex; flex-direction: column; height: 100vh;
    }
    header {
        display: flex; align-items: center; justify-content: space-between;
        padding: 14px 20px; border-bottom: 1px solid var(--line); background: #fff;
    }
    header .name { font-weight: 600; font-size: 15px; }
    header .actions { display: flex; align-items: center; gap: 10px; }
    header .badge {
        font-size: 12px; color: var(--accent); border: 1px solid var(--accent);
        padding: 3px 8px; border-radius: 20px;
    }
    header .download-link { font-size: 12px; color: var(--accent); text-decoration: none; }
    header .download-link:hover { text-decoration: underline; }
    #frame-wrap { flex: 1; }
    iframe { width: 100%; height: 100%; border: none; }
    .image-wrap { height: 100%; display: flex; align-items: center; justify-content: center; overflow: auto; background: #2b2b2b; }
    .image-wrap img { max-width: 100%; max-height: 100%; object-fit: contain; }
    .no-preview {
        height: 100%; display: flex; flex-direction: column; align-items: center;
        justify-content: center; gap: 12px; color: #8a8580; text-align: center; font-size: 14px;
    }
    .no-preview a { color: var(--accent); font-weight: 600; }
    footer {
        padding: 8px 20px; font-size: 12px; color: #8a8580;
        border-top: 1px solid var(--line); background: #fff;
    }
</style>
</head>
<body>
<header>
    <span class="name">📄 <?= $safeName ?></span>
    <div class="actions">
        <a class="download-link" href="<?= htmlspecialchars($fileUrl, ENT_QUOTES) ?>">Télécharger</a>
        <span class="badge">Document suivi</span>
    </div>
</header>
<div id="frame-wrap"><?= $viewerHtml ?></div>
<footer>
    Ce lien indique à l'expéditeur que le document a été ouvert (ouverture, temps de lecture).
    Il ne partage pas le contenu de votre lecture au-delà de ces indicateurs.
</footer>

<script>
const trackingId = <?= json_encode($trackingId) ?>;
const startTime = Date.now();
let maxScroll = 0;

function sendEvent(event, extra = {}) {
    const payload = Object.assign({ tracking_id: trackingId, event }, extra);
    navigator.sendBeacon(
        "<?= APP_BASE_URL ?>/api_event.php",
        new Blob([JSON.stringify(payload)], { type: "application/json" })
    );
}

window.addEventListener("scroll", () => {
    const doc = document.documentElement;
    const scrollPercent = Math.round((window.scrollY + window.innerHeight) / doc.scrollHeight * 100);
    if (scrollPercent > maxScroll) {
        maxScroll = scrollPercent;
        sendEvent("doc_scroll", { scrollDepth: maxScroll });
    }
});

window.addEventListener("beforeunload", () => {
    sendEvent("doc_close", { duration: Math.round((Date.now() - startTime) / 1000) });
});
</script>
</body>
</html>
