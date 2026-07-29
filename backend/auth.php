<?php
require_once __DIR__ . '/config.php';

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => true,   // nécessite HTTPS (c'est le cas sur statistics.ct.ws)
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function require_login(): void
{
    start_secure_session();
    if (empty($_SESSION['dashboard_authenticated'])) {
        header('Location: /dashboard/login.php');
        exit;
    }
}

/**
 * Petit anti-bruteforce indépendant du mode de stockage (JSON ou MySQL) :
 * on garde un compteur d'échecs par IP dans un fichier dédié, avec verrouillage
 * progressif. Volontairement simple — pour une protection plus poussée,
 * mets aussi un mot de passe au niveau du serveur web (auth basique Apache) en plus.
 */
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCKOUT_SECONDS = 300; // 5 minutes

function login_throttle_path(): string
{
    return __DIR__ . '/data/.login_attempts.json';
}

function check_login_throttle(string $ip): bool
{
    $file = login_throttle_path();
    if (!is_file($file)) {
        return true;
    }
    $fp = fopen($file, 'r');
    flock($fp, LOCK_SH);
    $data = json_decode(stream_get_contents($fp) ?: '{}', true) ?: [];
    flock($fp, LOCK_UN);
    fclose($fp);

    $entry = $data[$ip] ?? null;
    if (!$entry) {
        return true;
    }
    if ($entry['count'] >= LOGIN_MAX_ATTEMPTS && (time() - $entry['last']) < LOGIN_LOCKOUT_SECONDS) {
        return false;
    }
    return true;
}

function register_login_failure(string $ip): void
{
    $dir = dirname(login_throttle_path());
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    $file = login_throttle_path();
    if (!is_file($file)) {
        touch($file);
    }
    $fp = fopen($file, 'c+');
    flock($fp, LOCK_EX);
    $data = json_decode(stream_get_contents($fp) ?: '{}', true) ?: [];

    $entry = $data[$ip] ?? ['count' => 0, 'last' => 0];
    // Réinitialise le compteur si la dernière tentative date d'avant la fenêtre de lockout
    if ((time() - $entry['last']) > LOGIN_LOCKOUT_SECONDS) {
        $entry['count'] = 0;
    }
    $entry['count']++;
    $entry['last'] = time();
    $data[$ip] = $entry;

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function clear_login_failures(string $ip): void
{
    $file = login_throttle_path();
    if (!is_file($file)) {
        return;
    }
    $fp = fopen($file, 'c+');
    flock($fp, LOCK_EX);
    $data = json_decode(stream_get_contents($fp) ?: '{}', true) ?: [];
    unset($data[$ip]);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * Vérifie le mot de passe unique du dashboard contre le hash défini dans config.php.
 * Retourne 'ok', 'locked' ou 'invalid'.
 */
function attempt_login(string $password): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if (!check_login_throttle($ip)) {
        return 'locked';
    }

    if (DASHBOARD_PASSWORD_HASH === '' || !password_verify($password, DASHBOARD_PASSWORD_HASH)) {
        register_login_failure($ip);
        return 'invalid';
    }

    clear_login_failures($ip);
    start_secure_session();
    session_regenerate_id(true);
    $_SESSION['dashboard_authenticated'] = true;
    return 'ok';
}

function logout(): void
{
    start_secure_session();
    $_SESSION = [];
    session_destroy();
}

/**
 * Jeton CSRF pour les actions destructrices (suppression d'historique). La session étant déjà
 * en SameSite=Lax, le risque est limité, mais ce jeton ajoute une couche de défense en profondeur
 * pour les formulaires POST du dashboard.
 */
function csrf_token(): string
{
    start_secure_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool
{
    start_secure_session();
    return !empty($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}
