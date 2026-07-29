<?php
require_once __DIR__ . '/config.php';

/**
 * Authentifie une requête d'extension via un header "Authorization: Bearer <token>".
 * Retourne l'id utilisateur si valide, sinon null.
 */
function authenticate_api_request(): ?int
{
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
        return null;
    }
    $token = trim($m[1]);
    if ($token === '') {
        return null;
    }

    $users = storage()->getUsersWithApiTokens();
    foreach ($users as $row) {
        if (password_verify($token, $row['api_token_hash'])) {
            return (int) $row['id'];
        }
    }
    return null;
}
