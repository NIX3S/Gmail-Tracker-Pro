<?php
require_once __DIR__ . '/StorageInterface.php';

class MysqlStorage implements StorageInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function createUser(string $username, string $passwordHash, string $apiTokenHash): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (username, password_hash, api_token_hash) VALUES (:u, :p, :t)'
        );
        $stmt->execute(['u' => $username, 'p' => $passwordHash, 't' => $apiTokenHash]);
        return (int) $this->pdo->lastInsertId();
    }

    public function getUserByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, username, password_hash, api_token_hash FROM users WHERE username = :u LIMIT 1');
        $stmt->execute(['u' => $username]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getUsersWithApiTokens(): array
    {
        $stmt = $this->pdo->query('SELECT id, api_token_hash FROM users WHERE api_token_hash IS NOT NULL');
        return $stmt->fetchAll();
    }

    public function createEmail(string $trackingId, int $ownerUserId, ?string $subject, ?string $recipient, ?string $category = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO emails (tracking_id, owner_user_id, subject, recipient, category) VALUES (:tid, :uid, :subject, :recipient, :category)'
        );
        $stmt->execute(['tid' => $trackingId, 'uid' => $ownerUserId, 'subject' => $subject, 'recipient' => $recipient, 'category' => $category]);
        return (int) $this->pdo->lastInsertId();
    }

    public function getEmail(string $trackingId, ?int $ownerUserId = null): ?array
    {
        if ($ownerUserId !== null) {
            $stmt = $this->pdo->prepare('SELECT * FROM emails WHERE tracking_id = :tid AND owner_user_id = :uid LIMIT 1');
            $stmt->execute(['tid' => $trackingId, 'uid' => $ownerUserId]);
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM emails WHERE tracking_id = :tid LIMIT 1');
            $stmt->execute(['tid' => $trackingId]);
        }
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listEmailsWithCounts(int $userId, int $limit = 200): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.tracking_id, e.subject, e.recipient, e.category, e.created_at,
                SUM(ev.event_type = "open") AS opens,
                SUM(ev.event_type = "click") AS clicks,
                SUM(ev.event_type = "doc_open") AS doc_opens
             FROM emails e
             LEFT JOIN events ev ON ev.tracking_id = e.tracking_id
             WHERE e.owner_user_id = :uid
             GROUP BY e.id
             ORDER BY e.created_at DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll();
    }

    public function listAllEmailsWithCounts(int $limit = 200): array
    {
        $stmt = $this->pdo->query(
            'SELECT e.tracking_id, e.subject, e.recipient, e.category, e.created_at,
                SUM(ev.event_type = "open") AS opens,
                SUM(ev.event_type = "click") AS clicks,
                SUM(ev.event_type = "doc_open") AS doc_opens
             FROM emails e
             LEFT JOIN events ev ON ev.tracking_id = e.tracking_id
             GROUP BY e.id
             ORDER BY e.created_at DESC
             LIMIT ' . (int) $limit
        );
        return $stmt->fetchAll();
    }

    public function addEvent(string $trackingId, string $eventType, ?array $meta, ?string $ip, ?string $userAgent): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO events (tracking_id, event_type, meta, ip_address, user_agent) VALUES (:tid, :type, :meta, :ip, :ua)'
        );
        $stmt->execute([
            'tid' => $trackingId,
            'type' => $eventType,
            'meta' => $meta !== null ? json_encode($meta) : null,
            'ip' => $ip,
            'ua' => $userAgent,
        ]);
    }

    public function getEvents(string $trackingId): array
    {
        $stmt = $this->pdo->prepare('SELECT event_type, meta, created_at FROM events WHERE tracking_id = :tid ORDER BY created_at ASC');
        $stmt->execute(['tid' => $trackingId]);
        return $stmt->fetchAll();
    }

    public function createDocument(string $docUuid, string $trackingId, string $originalName, string $storedPath, ?string $mimeType): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO documents (doc_uuid, tracking_id, original_name, stored_path, mime_type) VALUES (:duuid, :tid, :name, :path, :mime)'
        );
        $stmt->execute(['duuid' => $docUuid, 'tid' => $trackingId, 'name' => $originalName, 'path' => $storedPath, 'mime' => $mimeType]);
        return (int) $this->pdo->lastInsertId();
    }

    public function getDocument(string $docUuid, string $trackingId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM documents WHERE doc_uuid = :d AND tracking_id = :t LIMIT 1');
        $stmt->execute(['d' => $docUuid, 't' => $trackingId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getDocumentsForTracking(string $trackingId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM documents WHERE tracking_id = :t ORDER BY created_at ASC');
        $stmt->execute(['t' => $trackingId]);
        return $stmt->fetchAll();
    }

    public function deleteEmail(string $trackingId): array
    {
        $stmt = $this->pdo->prepare('SELECT stored_path FROM documents WHERE tracking_id = :t');
        $stmt->execute(['t' => $trackingId]);
        $paths = array_column($stmt->fetchAll(), 'stored_path');

        $this->pdo->prepare('DELETE FROM documents WHERE tracking_id = :t')->execute(['t' => $trackingId]);
        $this->pdo->prepare('DELETE FROM events WHERE tracking_id = :t')->execute(['t' => $trackingId]);
        $this->pdo->prepare('DELETE FROM emails WHERE tracking_id = :t')->execute(['t' => $trackingId]);

        return $paths;
    }

    public function deleteAllData(): array
    {
        $paths = array_column($this->pdo->query('SELECT stored_path FROM documents')->fetchAll(), 'stored_path');

        $this->pdo->exec('DELETE FROM documents');
        $this->pdo->exec('DELETE FROM events');
        $this->pdo->exec('DELETE FROM emails');

        return $paths;
    }
}
