<?php
/**
 * Usage (en ligne de commande, sur le serveur ou en local avec les mêmes creds DB/data) :
 *   php create_user.php mon_identifiant
 *
 * Crée un compte "technique" utilisé UNIQUEMENT par l'extension Chrome (register_email.php,
 * upload_document.php) via un token API. Ne sert plus à se connecter au dashboard :
 * le dashboard est maintenant protégé par un mot de passe unique défini dans
 * config.php (DASHBOARD_PASSWORD_HASH), généré comme ceci :
 *
 *   php -r "echo password_hash('ton_mot_de_passe', PASSWORD_DEFAULT);"
 *
 * puis collé dans config.php.
 */
require_once __DIR__ . '/config.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php create_user.php <username>\n");
    exit(1);
}

$username = $argv[1];
$apiToken = bin2hex(random_bytes(24));

// On ne se sert plus du mot de passe "compte" nulle part, mais la colonne existe encore
// dans le schéma : on y met un hash aléatoire jamais communiqué, jamais utilisable.
$unusedPasswordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

storage()->createUser($username, $unusedPasswordHash, password_hash($apiToken, PASSWORD_DEFAULT));

echo "Compte technique créé pour l'extension.\n";
echo "Token API (à coller dans les options de l'extension Chrome) : $apiToken\n";
echo "\n⚠️  Ce token ne sera plus jamais affiché, note-le maintenant.\n";
