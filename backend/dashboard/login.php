<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

start_secure_session();
if (!empty($_SESSION['dashboard_authenticated'])) {
    header('Location: /dashboard/index.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $result = attempt_login($password);

    if ($result === 'ok') {
        header('Location: /dashboard/index.php');
        exit;
    }
    $error = $result === 'locked'
        ? 'Trop de tentatives. Réessaie dans quelques minutes.'
        : 'Mot de passe incorrect.';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Connexion — Tracker</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    :root { --ink:#132a3a; --paper:#f2f4f1; --accent:#c9821f; --line:#d7dad5; }
    * { box-sizing: border-box; }
    body {
        margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
        background:var(--paper); font-family: -apple-system, "Segoe UI", Roboto, sans-serif; color:var(--ink);
    }
    form {
        background:#fff; border:1px solid var(--line); border-radius:10px;
        padding:32px; width:320px; box-shadow:0 2px 12px rgba(19,42,58,0.06);
    }
    h1 { font-size:20px; margin:0 0 4px; }
    p.sub { margin:0 0 24px; color:#66727a; font-size:13px; }
    label { display:block; font-size:12px; margin:14px 0 4px; color:#44515a; }
    input {
        width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:6px; font-size:14px;
    }
    button {
        margin-top:20px; width:100%; padding:11px; border:none; border-radius:6px;
        background:var(--ink); color:#fff; font-size:14px; cursor:pointer;
    }
    button:hover { background:#0d1f2b; }
    .error { background:#fbe9e7; color:#a13d2b; padding:10px 12px; border-radius:6px; font-size:13px; margin-top:16px; }
</style>
</head>
<body>
<form method="post">
    <h1>🔒 Tracker</h1>
    <p class="sub">Mot de passe requis pour accéder au tableau de bord</p>
    <label for="password">Mot de passe</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required autofocus>
    <button type="submit">Se connecter</button>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
</form>
</body>
</html>
