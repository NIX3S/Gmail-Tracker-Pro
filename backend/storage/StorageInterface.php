<?php
/**
 * Interface commune : le reste de l'application (track.php, click.php, dashboard...)
 * ne parle qu'à cette interface, jamais directement à PDO ou aux fichiers JSON.
 * Ça permet de changer de backend de stockage (STORAGE_MODE dans config.php)
 * sans toucher au reste du code.
 */
interface StorageInterface
{
    public function createUser(string $username, string $passwordHash, string $apiTokenHash): int;

    /** @return array{id:int,username:string,password_hash:string,api_token_hash:?string}|null */
    public function getUserByUsername(string $username): ?array;

    /** @return array<array{id:int,api_token_hash:string}> */
    public function getUsersWithApiTokens(): array;

    public function createEmail(string $trackingId, int $ownerUserId, ?string $subject, ?string $recipient, ?string $category = null): int;

    /** @return array|null */
    public function getEmail(string $trackingId, ?int $ownerUserId = null): ?array;

    /**
     * Liste des emails d'un utilisateur avec compteurs d'ouvertures/clics/documents,
     * triés du plus récent au plus ancien. Chaque ligne contient au moins :
     * tracking_id, subject, recipient, category, created_at, opens, clicks, doc_opens.
     */
    public function listEmailsWithCounts(int $userId, int $limit = 200): array;

    /**
     * Liste TOUS les emails (tous comptes/API tokens confondus) avec compteurs.
     * Utilisée par le dashboard, qui est protégé par un mot de passe unique (pas par compte).
     */
    public function listAllEmailsWithCounts(int $limit = 200): array;

    public function addEvent(string $trackingId, string $eventType, ?array $meta, ?string $ip, ?string $userAgent): void;

    /** @return array Liste des évènements pour un tracking_id, triés du plus ancien au plus récent */
    public function getEvents(string $trackingId): array;

    public function createDocument(string $docUuid, string $trackingId, string $originalName, string $storedPath, ?string $mimeType): int;

    /** @return array|null */
    public function getDocument(string $docUuid, string $trackingId): ?array;

    /** @return array Tous les documents attachés à un tracking_id donné */
    public function getDocumentsForTracking(string $trackingId): array;

    /**
     * Supprime un email tracké et tout ce qui s'y rattache (évènements, documents).
     * Ne touche pas aux fichiers physiquement uploadés : retourne la liste des noms de
     * fichiers (stored_path) à supprimer du disque, à la charge de l'appelant (qui connaît
     * UPLOAD_DIR — la couche de stockage reste indépendante du système de fichiers d'upload).
     * @return string[] Liste des stored_path des documents supprimés
     */
    public function deleteEmail(string $trackingId): array;

    /**
     * Supprime TOUT l'historique (tous les emails, évènements, documents, tous comptes confondus).
     * @return string[] Liste de tous les stored_path de documents supprimés (à effacer du disque)
     */
    public function deleteAllData(): array;
}
