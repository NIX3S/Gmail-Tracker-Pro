<?php
/**
 * Construit un résumé lisible à partir de la liste brute d'évènements d'un tracking_id
 * (celle renvoyée par storage()->getEvents()). Centralisé ici pour que le dashboard
 * (dashboard/detail.php) et l'API (api_stats.php) calculent les stats de la même façon.
 */
function summarize_events(array $events): array
{
    $opens = [];
    $clicksByUrl = [];
    $docOpens = [];
    $docCloses = [];
    $maxScrollDepth = 0;
    $lastEventAt = null;

    foreach ($events as $ev) {
        $meta = $ev['meta'] ?? null;
        $meta = is_string($meta) ? (json_decode($meta, true) ?: []) : ($meta ?? []);

        if ($lastEventAt === null || $ev['created_at'] > $lastEventAt) {
            $lastEventAt = $ev['created_at'];
        }

        switch ($ev['event_type']) {
            case 'open':
                $opens[] = $ev['created_at'];
                break;
            case 'click':
                $url = $meta['url'] ?? '(url inconnue)';
                $clicksByUrl[$url] = ($clicksByUrl[$url] ?? 0) + 1;
                break;
            case 'doc_open':
                $docOpens[] = ['at' => $ev['created_at'], 'file' => $meta['file'] ?? null];
                break;
            case 'doc_scroll':
                $maxScrollDepth = max($maxScrollDepth, (int) ($meta['scrollDepth'] ?? 0));
                break;
            case 'doc_close':
                $docCloses[] = ['at' => $ev['created_at'], 'duration' => (int) ($meta['duration'] ?? 0)];
                break;
        }
    }

    return [
        'opensCount' => count($opens),
        'openTimestamps' => $opens,
        'clicksCount' => array_sum($clicksByUrl),
        'clicksByUrl' => $clicksByUrl,
        'docOpensCount' => count($docOpens),
        'docOpens' => $docOpens,
        'docCloses' => $docCloses,
        'docTimeTotalSeconds' => array_sum(array_column($docCloses, 'duration')),
        'docMaxScrollDepth' => $maxScrollDepth,
        'lastEventAt' => $lastEventAt,
    ];
}

/** Formate une durée en secondes en texte lisible ("1 min 20 s", "45 s"...) */
function format_duration(int $seconds): string
{
    if ($seconds <= 0) {
        return '0 s';
    }
    $minutes = intdiv($seconds, 60);
    $rest = $seconds % 60;
    if ($minutes === 0) {
        return "{$rest} s";
    }
    return "{$minutes} min" . ($rest > 0 ? " {$rest} s" : '');
}
