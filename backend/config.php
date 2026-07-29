<?php
/**
 * Configuration centrale.
 * IMPORTANT :
 *  - Place ce fichier en dehors du webroot si ton hébergeur le permet,
 *    sinon assure-toi que .htaccess bloque bien l'accès direct (voir .htaccess fourni).
 *  - Ne commit JAMAIS ce fichier avec de vrais secrets dans un repo public.
 */

// --- Mode de stockage ---
// 'mysql' : vraie base de données (recommandé si tu as un accès MySQL sur ton hébergement)
// 'json'  : fichiers JSON verrouillés dans backend/data/ (comme la version d'origine, pour un usage perso/faible volume)
define('STORAGE_MODE', 'json'); // 'mysql' ou 'json'

// --- Base de données (utilisé uniquement si STORAGE_MODE = 'mysql') ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'change_me');
define('DB_USER', 'change_me');
define('DB_PASS', 'change_me');

// --- Stockage JSON (utilisé uniquement si STORAGE_MODE = 'json') ---
define('JSON_DATA_DIR', __DIR__ . '/data');

// --- Sécurité ---
// Clé secrète utilisée pour signer les liens de clic (anti open-redirect) et les tokens API.
// Génère une vraie valeur aléatoire, par ex avec: bin2hex(random_bytes(32))
define('APP_SECRET', 'REPLACE_WITH_A_LONG_RANDOM_SECRET');

// Mot de passe unique protégeant l'accès au dashboard (login.php).
// Ne mets JAMAIS le mot de passe en clair ici : uniquement son hash.
// Génère-le avec : php -r "echo password_hash('ton_mot_de_passe', PASSWORD_DEFAULT);"
// Tant que cette valeur est vide, l'accès au dashboard est refusé (échec fermé, pas ouvert).
define('DASHBOARD_PASSWORD_HASH', '$2y$12$DVC6yFsPYhqvHba4HqxFYOBEX2dH5AHbQCebFrbDoXzgWoOMNCJuS');

// Domaine de ton backend (utilisé pour générer les liens insérés dans les emails)
define('APP_BASE_URL', 'http://127.0.0.1:8000/');

// Dossier de stockage des documents uploadés (hors webroot idéalement)
define('UPLOAD_DIR', __DIR__ . '/uploads');

// Ne jamais laisser PHP ou l'hébergeur afficher une page d'erreur HTML brute :
// ça casse le JSON attendu par l'extension (message "Unexpected token '<'").
// Les erreurs sont toujours loggées côté serveur (error_log), jamais affichées au client.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/**
 * A appeler en tout début des endpoints qui doivent TOUJOURS répondre en JSON
 * (register_email.php, upload_document.php, api_event.php, api_stats.php).
 * Si une erreur ou une exception non interceptée survient n'importe où dans la requête,
 * on répond quand même avec un JSON propre au lieu de laisser l'hébergeur renvoyer
 * sa page d'erreur HTML par défaut (ce qui provoque un "Unexpected token '<'" côté extension).
 */
function install_json_error_boundary(): void
{
    set_exception_handler(function (\Throwable $e) {
        safe_log_error('uncaught_exception', $e);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['status' => 'error', 'message' => 'Erreur serveur inattendue']);
    });

    register_shutdown_function(function () {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            error_log('[fatal] ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['status' => 'error', 'message' => 'Erreur serveur inattendue']);
        }
    });
}

require_once __DIR__ . '/storage/StorageInterface.php';

/**
 * Point d'accès unique au stockage. Le reste du code appelle storage()->... et
 * n'a jamais à savoir si les données vivent en MySQL ou en JSON.
 */
function storage(): StorageInterface
{
    static $instance = null;
    if ($instance !== null) {
        return $instance;
    }

    if (STORAGE_MODE === 'mysql') {
        require_once __DIR__ . '/storage/MysqlStorage.php';
        $instance = new MysqlStorage(db());
    } else {
        require_once __DIR__ . '/storage/JsonStorage.php';
        $instance = new JsonStorage(JSON_DATA_DIR);
    }

    return $instance;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

/**
 * Signe une URL pour empêcher que click.php serve de redirecteur ouvert.
 * Le lien n'est valide que si le HMAC correspond exactement à (tracking_id + url).
 */
function sign_click(string $trackingId, string $url): string
{
    return hash_hmac('sha256', $trackingId . '|' . $url, APP_SECRET);
}

function verify_click(string $trackingId, string $url, string $signature): bool
{
    $expected = sign_click($trackingId, $url);
    return hash_equals($expected, $signature);
}

/** Petit helper pour logguer proprement une erreur sans casser la réponse pixel/redirect */
function safe_log_error(string $context, \Throwable $e): void
{
    error_log('[' . $context . '] ' . $e->getMessage());
}
