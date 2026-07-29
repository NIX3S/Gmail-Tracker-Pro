<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

require_login();
$csrfToken = csrf_token();

$rows = storage()->listAllEmailsWithCounts();

// Palette de couleurs pour les badges de catégorie, choisie de façon stable par catégorie
// (même catégorie = même couleur à chaque affichage, via un hash simple).
$categoryPalette = [
    ['#e8edf3', '#3d5a80'], // bleu
    ['#fdf1e0', '#c9821f'], // ambre
    ['#e7f0e9', '#2f6b45'], // vert
    ['#f3e8f0', '#8a3d6b'], // prune
    ['#e8f0f3', '#2f6b7a'], // sarcelle
    ['#f3ebe8', '#8a4a2f'], // brique
];
function category_colors(string $category, array $palette): array
{
    $i = crc32($category) % count($palette);
    return $palette[$i];
}

// Regroupe les envois qui ont le même sujet + destinataire + catégorie (ex: plusieurs clics sur
// "Envoyer avec tracking" pour le même brouillon) pour éviter les doublons dans la vue principale.
$groups = [];
$allCategories = [];
foreach ($rows as $row) {
    $category = $row['category'] ?? '';
    if ($category !== '') {
        $allCategories[$category] = true;
    }
    $key = ($row['subject'] ?? '') . '||' . ($row['recipient'] ?? '') . '||' . $category;
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'subject' => $row['subject'],
            'recipient' => $row['recipient'],
            'category' => $category,
            'sends' => [],
            'opens' => 0,
            'clicks' => 0,
            'doc_opens' => 0,
            'last_sent' => $row['created_at'],
        ];
    }
    $groups[$key]['sends'][] = $row;
    $groups[$key]['opens'] += (int) $row['opens'];
    $groups[$key]['clicks'] += (int) $row['clicks'];
    $groups[$key]['doc_opens'] += (int) $row['doc_opens'];
    if ($row['created_at'] > $groups[$key]['last_sent']) {
        $groups[$key]['last_sent'] = $row['created_at'];
    }
}
// Tri par dernier envoi, du plus récent au plus ancien
usort($groups, fn($a, $b) => strcmp($b['last_sent'], $a['last_sent']));
ksort($allCategories);
$categoryList = array_keys($allCategories);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Tableau de bord — Tracker</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    :root {
        --ink:#132a3a; --paper:#f2f4f1; --accent:#c9821f; --accent-soft:#fdf1e0;
        --green:#2f6b45; --green-soft:#e7f0e9; --blue:#3d5a80; --blue-soft:#e8edf3;
        --red:#a13d2b; --red-soft:#fbe9e7;
        --line:#d7dad5; --muted:#66727a;
        --mono: "SF Mono", "IBM Plex Mono", Consolas, monospace;
    }
    * { box-sizing:border-box; }
    body { margin:0; font-family:-apple-system,"Segoe UI",Roboto,sans-serif; background:var(--paper); color:var(--ink); }
    header {
        display:flex; align-items:center; justify-content:space-between;
        padding:18px 28px; background:#fff; border-bottom:1px solid var(--line);
    }
    header h1 { font-size:18px; margin:0; }
    header a { color:var(--muted); font-size:13px; text-decoration:none; }
    main { padding:28px; max-width:960px; margin:0 auto; }

    .toolbar {
        display:flex; gap:10px; align-items:center; margin-bottom:20px; flex-wrap:wrap;
    }
    .toolbar input[type=text], .toolbar select {
        padding:8px 12px; border:1px solid var(--line); border-radius:6px; font-size:13px; background:#fff;
    }
    .toolbar input[type=text] { flex:1; min-width:180px; }
    .toolbar .spacer { flex:1; }
    .btn {
        padding:8px 14px; border:1px solid var(--line); border-radius:6px; font-size:13px;
        background:#fff; color:var(--ink); cursor:pointer;
    }
    .btn:hover { background:#f5f5f4; }
    .btn.danger { color:var(--red); border-color:#e6c2bb; }
    .btn.danger:hover { background:var(--red-soft); }
    .btn.small { padding:4px 10px; font-size:12px; }

    .no-match { display:none; }

    .group-card { background:#fff; border:1px solid var(--line); border-radius:10px; margin-bottom:14px; overflow:hidden; }
    .group-top { display:flex; align-items:center; justify-content:space-between; padding:16px 18px; gap:16px; }
    .group-top .info { min-width:0; }
    .group-top .subject-row { display:flex; align-items:center; gap:8px; }
    .group-top .subject { font-weight:600; font-size:15px; }
    .group-top .recipient { color:var(--muted); font-size:13px; margin-top:2px; }
    .group-top .last-sent { color:var(--muted); font-size:12px; margin-top:4px; font-family:var(--mono); }

    .cat-badge { font-size:11px; font-weight:600; padding:2px 8px; border-radius:20px; white-space:nowrap; }

    .badges { display:flex; gap:8px; flex-shrink:0; align-items:center; }
    .badge { font-family:var(--mono); font-size:13px; font-weight:600; padding:4px 10px; border-radius:20px; white-space:nowrap; }
    .badge.opens { background:var(--green-soft); color:var(--green); }
    .badge.clicks { background:var(--accent-soft); color:var(--accent); }
    .badge.docs { background:var(--blue-soft); color:var(--blue); }
    .badge.count { background:#eef0ee; color:var(--muted); }

    details summary { list-style:none; cursor:pointer; padding:0 18px 14px; color:var(--muted); font-size:12px; }
    details summary::-webkit-details-marker { display:none; }
    details[open] summary { color:var(--ink); }

    table.sends { width:100%; border-collapse:collapse; border-top:1px solid var(--line); }
    table.sends th, table.sends td { padding:8px 18px; text-align:left; font-size:12px; border-bottom:1px solid var(--line); }
    table.sends th { color:var(--muted); font-weight:600; text-transform:uppercase; font-size:10px; letter-spacing:.03em; background:#fafaf9; }
    table.sends tr:last-child td { border-bottom:none; }
    table.sends td.num { font-family:var(--mono); }
    table.sends a { color:var(--blue); text-decoration:none; font-size:12px; }
    table.sends a:hover { text-decoration:underline; }
    table.sends form { display:inline; margin-left:10px; }
    table.sends button.link-btn { background:none; border:none; color:var(--red); font-size:12px; cursor:pointer; padding:0; }
    table.sends button.link-btn:hover { text-decoration:underline; }

    .single-link { padding:0 18px 14px; display:flex; align-items:center; gap:12px; }
    .single-link a { color:var(--blue); text-decoration:none; font-size:13px; }
    .single-link a:hover { text-decoration:underline; }
    .single-link form { display:inline; }
    .single-link button.link-btn { background:none; border:none; color:var(--red); font-size:13px; cursor:pointer; padding:0; }
    .single-link button.link-btn:hover { text-decoration:underline; }

    .empty { text-align:center; padding:60px 0; color:#8a949a; }
</style>
</head>
<body>
<header>
    <h1>📬 Tracker — Tableau de bord</h1>
    <a href="/dashboard/logout.php">Se déconnecter</a>
</header>
<main>
    <div class="toolbar">
        <input type="text" id="searchInput" placeholder="Rechercher dans le sujet (ex: Catalogue)...">
        <select id="categoryFilter">
            <option value="">Toutes les catégories</option>
            <?php foreach ($categoryList as $cat): ?>
                <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
            <?php endforeach; ?>
            <option value="__none__">Sans catégorie</option>
        </select>
        <button type="button" class="btn danger small" id="deleteCategoryBtn" style="display:none;">Supprimer cette catégorie</button>
        <div class="spacer"></div>
        <form method="post" action="/dashboard/delete.php" onsubmit="return confirm('Supprimer TOUT l\'historique de tracking ? Cette action est irréversible.');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="all">
            <button type="submit" class="btn danger">🗑 Vider tout l'historique</button>
        </form>
    </div>

    <form method="post" action="/dashboard/delete.php" id="deleteCategoryForm" style="display:none;" onsubmit="return confirm('Supprimer tous les emails trackés de cette catégorie ? Cette action est irréversible.');">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="action" value="category">
        <input type="hidden" name="category" id="deleteCategoryInput" value="">
    </form>

<?php if (empty($groups)): ?>
    <div class="empty">Aucun email tracké pour le moment. Envoie un email depuis Gmail avec l'extension pour le voir apparaître ici.</div>
<?php else: ?>
    <?php foreach ($groups as $group):
        $catColors = $group['category'] !== '' ? category_colors($group['category'], $categoryPalette) : null;
    ?>
        <div class="group-card" data-subject="<?= htmlspecialchars($group['subject'] ?? '') ?>" data-category="<?= htmlspecialchars($group['category'] !== '' ? $group['category'] : '__none__') ?>">
            <div class="group-top">
                <div class="info">
                    <div class="subject-row">
                        <div class="subject"><?= htmlspecialchars($group['subject'] ?: '(sans sujet)') ?></div>
                        <?php if ($catColors): ?>
                            <span class="cat-badge" style="background:<?= $catColors[0] ?>; color:<?= $catColors[1] ?>;"><?= htmlspecialchars($group['category']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="recipient"><?= htmlspecialchars($group['recipient'] ?: '—') ?></div>
                    <div class="last-sent">Dernier envoi : <?= htmlspecialchars($group['last_sent']) ?></div>
                </div>
                <div class="badges">
                    <?php if (count($group['sends']) > 1): ?>
                        <span class="badge count"><?= count($group['sends']) ?> envois</span>
                    <?php endif; ?>
                    <span class="badge opens" title="Ouvertures"><?= $group['opens'] ?> 📬</span>
                    <span class="badge clicks" title="Clics"><?= $group['clicks'] ?> 🔗</span>
                    <?php if ($group['doc_opens'] > 0): ?>
                        <span class="badge docs" title="Documents ouverts"><?= $group['doc_opens'] ?> 📄</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (count($group['sends']) === 1): ?>
                <div class="single-link">
                    <a href="/dashboard/detail.php?id=<?= urlencode($group['sends'][0]['tracking_id']) ?>">Voir le détail →</a>
                    <form method="post" action="/dashboard/delete.php" onsubmit="return confirm('Supprimer cet email tracké ?');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="single">
                        <input type="hidden" name="tracking_id" value="<?= htmlspecialchars($group['sends'][0]['tracking_id']) ?>">
                        <button type="submit" class="link-btn">Supprimer</button>
                    </form>
                </div>
            <?php else: ?>
                <details>
                    <summary>Voir les <?= count($group['sends']) ?> envois individuels</summary>
                    <table class="sends">
                        <thead>
                            <tr><th>Envoyé le</th><th>Ouvertures</th><th>Clics</th><th>Documents</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php
                        $sends = $group['sends'];
                        usort($sends, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
                        foreach ($sends as $send): ?>
                            <tr>
                                <td><?= htmlspecialchars($send['created_at']) ?></td>
                                <td class="num"><?= (int) $send['opens'] ?></td>
                                <td class="num"><?= (int) $send['clicks'] ?></td>
                                <td class="num"><?= (int) $send['doc_opens'] ?></td>
                                <td>
                                    <a href="/dashboard/detail.php?id=<?= urlencode($send['tracking_id']) ?>">Détail →</a>
                                    <form method="post" action="/dashboard/delete.php" onsubmit="return confirm('Supprimer cet envoi ?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="action" value="single">
                                        <input type="hidden" name="tracking_id" value="<?= htmlspecialchars($send['tracking_id']) ?>">
                                        <button type="submit" class="link-btn">Supprimer</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
</main>
<script>
const searchInput = document.getElementById('searchInput');
const categoryFilter = document.getElementById('categoryFilter');
const deleteCategoryBtn = document.getElementById('deleteCategoryBtn');
const deleteCategoryForm = document.getElementById('deleteCategoryForm');
const deleteCategoryInput = document.getElementById('deleteCategoryInput');
const cards = Array.from(document.querySelectorAll('.group-card'));

function applyFilters() {
    const search = searchInput.value.trim().toLowerCase();
    const category = categoryFilter.value;

    cards.forEach((card) => {
        const matchesSearch = !search || card.dataset.subject.toLowerCase().includes(search);
        const matchesCategory = !category || card.dataset.category === category;
        card.style.display = (matchesSearch && matchesCategory) ? '' : 'none';
    });

    deleteCategoryBtn.style.display = category ? 'inline-block' : 'none';
}

searchInput.addEventListener('input', applyFilters);
categoryFilter.addEventListener('change', applyFilters);

deleteCategoryBtn.addEventListener('click', () => {
    const category = categoryFilter.value;
    if (!category) return;
    deleteCategoryInput.value = (category === '__none__') ? '' : category;
    deleteCategoryForm.requestSubmit();
});
</script>
</body>
</html>
