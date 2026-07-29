<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../stats_helper.php';

require_login();
$csrfToken = csrf_token();

$trackingId = $_GET['id'] ?? '';
if (!preg_match('/^[a-f0-9\-]{8,64}$/i', $trackingId)) {
    http_response_code(404);
    echo 'Email introuvable.';
    exit;
}

$email = storage()->getEmail($trackingId);
if (!$email) {
    http_response_code(404);
    echo 'Email introuvable.';
    exit;
}

$events = storage()->getEvents($trackingId);
$summary = summarize_events($events);
$documents = storage()->getDocumentsForTracking($trackingId);

$eventLabels = [
    'open' => ['📬', 'Ouverture de l\'email'],
    'click' => ['🔗', 'Clic sur un lien'],
    'doc_open' => ['📄', 'Ouverture d\'un document'],
    'doc_scroll' => ['📜', 'Défilement dans le document'],
    'doc_close' => ['⏱️', 'Fin de consultation du document'],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($email['subject'] ?: '(sans sujet)') ?> — Détail</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    :root {
        --ink:#132a3a; --paper:#f2f4f1; --accent:#c9821f; --accent-soft:#fdf1e0;
        --green:#2f6b45; --green-soft:#e7f0e9; --blue:#3d5a80; --blue-soft:#e8edf3;
        --line:#d7dad5; --muted:#66727a;
        --mono: "SF Mono", "IBM Plex Mono", Consolas, monospace;
    }
    * { box-sizing:border-box; }
    body { margin:0; font-family:-apple-system,"Segoe UI",Roboto,sans-serif; background:var(--paper); color:var(--ink); }
    header {
        display:flex; align-items:center; justify-content:space-between;
        padding:16px 28px; background:#fff; border-bottom:1px solid var(--line);
    }
    header a.back { color:var(--muted); font-size:13px; text-decoration:none; }
    header a.back:hover { color:var(--ink); }
    main { padding:28px; max-width:920px; margin:0 auto; }

    .email-head { margin-bottom:24px; }
    .email-head h1 { font-size:22px; margin:0 0 6px; }
    .email-head .meta { color:var(--muted); font-size:13px; }
    .email-head .meta b { color:var(--ink); font-weight:600; }

    .stat-grid { display:grid; grid-template-columns:repeat(4, 1fr); gap:12px; margin-bottom:28px; }
    .stat-card {
        background:#fff; border:1px solid var(--line); border-radius:10px; padding:16px;
    }
    .stat-card .value { font-family:var(--mono); font-size:26px; font-weight:600; }
    .stat-card .label { font-size:12px; color:var(--muted); margin-top:2px; }
    .stat-card.opens .value { color:var(--green); }
    .stat-card.clicks .value { color:var(--accent); }
    .stat-card.docs .value { color:var(--blue); }

    section { margin-bottom:28px; }
    section h2 { font-size:15px; margin:0 0 12px; color:var(--ink); }
    .empty-hint { color:var(--muted); font-size:13px; background:#fff; border:1px dashed var(--line); border-radius:8px; padding:14px; }

    table { width:100%; border-collapse:collapse; background:#fff; border:1px solid var(--line); border-radius:8px; overflow:hidden; }
    th, td { padding:10px 14px; text-align:left; font-size:13px; border-bottom:1px solid var(--line); }
    th { color:var(--muted); font-weight:600; text-transform:uppercase; font-size:11px; letter-spacing:.03em; }
    tr:last-child td { border-bottom:none; }
    td.url { font-family:var(--mono); font-size:12px; word-break:break-all; max-width:420px; }
    td.num { font-family:var(--mono); text-align:right; }

    .doc-card { background:#fff; border:1px solid var(--line); border-radius:8px; padding:14px 16px; margin-bottom:10px; }
    .doc-card .doc-name { font-weight:600; font-size:14px; }
    .doc-card a { color:var(--blue); text-decoration:none; font-size:13px; }
    .doc-card a:hover { text-decoration:underline; }

    details { background:#fff; border:1px solid var(--line); border-radius:8px; }
    details summary { padding:12px 16px; cursor:pointer; font-size:13px; color:var(--muted); user-select:none; }
    details[open] summary { border-bottom:1px solid var(--line); }
    .timeline { padding:4px 0; }
    .timeline-row { display:flex; gap:10px; padding:8px 16px; font-size:13px; align-items:baseline; }
    .timeline-row .icon { flex-shrink:0; }
    .timeline-row .when { font-family:var(--mono); color:var(--muted); font-size:12px; flex-shrink:0; width:150px; }
    .timeline-row .what { flex:1; }
</style>
</head>
<body>
<header>
    <a class="back" href="/dashboard/index.php">← Retour au tableau de bord</a>
    <form method="post" action="/dashboard/delete.php" onsubmit="return confirm('Supprimer cet email tracké et tout son historique ?');">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="action" value="single">
        <input type="hidden" name="tracking_id" value="<?= htmlspecialchars($trackingId) ?>">
        <button type="submit" style="background:none;border:1px solid #e6c2bb;color:#a13d2b;border-radius:6px;padding:6px 12px;font-size:12px;cursor:pointer;">🗑 Supprimer</button>
    </form>
</header>
<main>
    <div class="email-head">
        <h1><?= htmlspecialchars($email['subject'] ?: '(sans sujet)') ?></h1>
        <div class="meta">
            Envoyé à <b><?= htmlspecialchars($email['recipient'] ?: '—') ?></b>
            le <b><?= htmlspecialchars($email['created_at']) ?></b>
            <?php if (!empty($email['category'])): ?>
                — catégorie <b><?= htmlspecialchars($email['category']) ?></b>
            <?php endif; ?>
        </div>
    </div>

    <div class="stat-grid">
        <div class="stat-card opens">
            <div class="value"><?= $summary['opensCount'] ?></div>
            <div class="label">Ouvertures de l'email</div>
        </div>
        <div class="stat-card clicks">
            <div class="value"><?= $summary['clicksCount'] ?></div>
            <div class="label">Clics sur des liens</div>
        </div>
        <div class="stat-card docs">
            <div class="value"><?= $summary['docOpensCount'] ?></div>
            <div class="label">Ouvertures de documents</div>
        </div>
        <div class="stat-card docs">
            <div class="value"><?= format_duration($summary['docTimeTotalSeconds']) ?></div>
            <div class="label">Temps total sur les documents</div>
        </div>
    </div>

    <section>
        <h2>Liens cliqués</h2>
        <?php if (empty($summary['clicksByUrl'])): ?>
            <div class="empty-hint">Aucun clic enregistré pour cet email.</div>
        <?php else: ?>
            <table>
                <thead><tr><th>Lien</th><th style="text-align:right">Clics</th></tr></thead>
                <tbody>
                <?php foreach ($summary['clicksByUrl'] as $url => $count): ?>
                    <tr>
                        <td class="url"><?= htmlspecialchars($url) ?></td>
                        <td class="num"><?= $count ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section>
        <h2>Documents suivis</h2>
        <?php if (empty($documents)): ?>
            <div class="empty-hint">Aucun document tracké joint à cet email.</div>
        <?php else: ?>
            <?php foreach ($documents as $doc): ?>
                <div class="doc-card">
                    <div class="doc-name">📄 <?= htmlspecialchars($doc['original_name']) ?></div>
                    <a href="<?= APP_BASE_URL ?>/viewer.php?id=<?= urlencode($trackingId) ?>&doc=<?= urlencode($doc['doc_uuid']) ?>" target="_blank">
                        Ouvrir le lien de consultation ↗
                    </a>
                </div>
            <?php endforeach; ?>
            <?php if ($summary['docMaxScrollDepth'] > 0): ?>
                <div class="empty-hint" style="border-style:solid; margin-top:10px;">
                    Profondeur de lecture maximale atteinte : <b><?= $summary['docMaxScrollDepth'] ?>%</b> de la page.
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section>
        <h2>Historique complet</h2>
        <details>
            <summary><?= count($events) ?> évènement(s) — cliquer pour dérouler</summary>
            <div class="timeline">
                <?php if (empty($events)): ?>
                    <div class="timeline-row"><div class="what" style="color:var(--muted)">Aucun évènement pour le moment.</div></div>
                <?php endif; ?>
                <?php foreach (array_reverse($events) as $ev):
                    [$icon, $label] = $eventLabels[$ev['event_type']] ?? ['•', $ev['event_type']];
                    $meta = is_string($ev['meta'] ?? null) ? (json_decode($ev['meta'], true) ?: []) : [];
                    $detail = '';
                    if ($ev['event_type'] === 'click' && !empty($meta['url'])) {
                        $detail = ' — ' . $meta['url'];
                    } elseif ($ev['event_type'] === 'doc_scroll' && isset($meta['scrollDepth'])) {
                        $detail = ' — ' . $meta['scrollDepth'] . '%';
                    } elseif ($ev['event_type'] === 'doc_close' && isset($meta['duration'])) {
                        $detail = ' — ' . format_duration((int) $meta['duration']);
                    } elseif ($ev['event_type'] === 'doc_open' && !empty($meta['file'])) {
                        $detail = ' — ' . $meta['file'];
                    }
                ?>
                    <div class="timeline-row">
                        <div class="icon"><?= $icon ?></div>
                        <div class="when"><?= htmlspecialchars($ev['created_at']) ?></div>
                        <div class="what"><?= htmlspecialchars($label . $detail) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </details>
    </section>
</main>
</body>
</html>
