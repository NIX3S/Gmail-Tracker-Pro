<?php
require_once __DIR__ . '/StorageInterface.php';

/**
 * Stockage "comme avant" en fichiers JSON, mais avec verrouillage (flock) pour éviter
 * qu'une requête écrase les données d'une autre en cas d'accès concurrent.
 * Adaptée à un usage perso / faible volume. Pour un usage à plus grande échelle,
 * bascule STORAGE_MODE sur 'mysql' dans config.php.
 */
class JsonStorage implements StorageInterface
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/');
        if (!is_dir($this->dir) && !mkdir($this->dir, 0770, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Impossible de créer le dossier de stockage JSON : {$this->dir}. Vérifie les permissions.");
        }
        if (!is_writable($this->dir)) {
            throw new \RuntimeException("Le dossier de stockage JSON n'est pas accessible en écriture : {$this->dir}. Fais un chmod 770 dessus.");
        }
    }

    private function path(string $name): string
    {
        return $this->dir . '/' . $name . '.json';
    }

    /** Lecture seule, verrou partagé */
    private function readAll(string $name): array
    {
        $file = $this->path($name);
        if (!is_file($file)) {
            return [];
        }
        $fp = fopen($file, 'r');
        if ($fp === false) {
            throw new \RuntimeException("Impossible d'ouvrir $file en lecture. Vérifie les permissions du dossier data/.");
        }
        flock($fp, LOCK_SH);
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $data = json_decode($content ?: '[]', true);
        return is_array($data) ? $data : [];
    }

    /**
     * Lit, modifie via le callback (qui reçoit le tableau par référence et peut renvoyer une valeur),
     * puis réécrit — le tout sous un verrou exclusif unique pour éviter les races.
     */
    private function transact(string $name, callable $mutator)
    {
        $file = $this->path($name);
        if (!is_file($file) && touch($file) === false) {
            throw new \RuntimeException("Impossible de créer $file. Vérifie les permissions du dossier data/.");
        }
        $fp = fopen($file, 'c+');
        if ($fp === false) {
            throw new \RuntimeException("Impossible d'ouvrir $file en écriture. Vérifie les permissions du dossier data/.");
        }
        flock($fp, LOCK_EX);
        $content = stream_get_contents($fp);
        $data = json_decode($content ?: '[]', true);
        if (!is_array($data)) {
            $data = [];
        }

        $result = $mutator($data);

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return $result;
    }

    private function nextId(array $rows): int
    {
        if (empty($rows)) {
            return 1;
        }
        return max(array_column($rows, 'id')) + 1;
    }

    public function createUser(string $username, string $passwordHash, string $apiTokenHash): int
    {
        return $this->transact('users', function (array &$rows) use ($username, $passwordHash, $apiTokenHash) {
            $id = $this->nextId($rows);
            $rows[] = [
                'id' => $id,
                'username' => $username,
                'password_hash' => $passwordHash,
                'api_token_hash' => $apiTokenHash,
                'created_at' => date('Y-m-d H:i:s'),
            ];
            return $id;
        });
    }

    public function getUserByUsername(string $username): ?array
    {
        foreach ($this->readAll('users') as $row) {
            if ($row['username'] === $username) {
                return $row;
            }
        }
        return null;
    }

    public function getUsersWithApiTokens(): array
    {
        return array_values(array_filter(
            $this->readAll('users'),
            fn($row) => !empty($row['api_token_hash'])
        ));
    }

    public function createEmail(string $trackingId, int $ownerUserId, ?string $subject, ?string $recipient, ?string $category = null): int
    {
        return $this->transact('emails', function (array &$rows) use ($trackingId, $ownerUserId, $subject, $recipient, $category) {
            $id = $this->nextId($rows);
            $rows[] = [
                'id' => $id,
                'tracking_id' => $trackingId,
                'owner_user_id' => $ownerUserId,
                'subject' => $subject,
                'recipient' => $recipient,
                'category' => $category,
                'created_at' => date('Y-m-d H:i:s'),
            ];
            return $id;
        });
    }

    public function getEmail(string $trackingId, ?int $ownerUserId = null): ?array
    {
        foreach ($this->readAll('emails') as $row) {
            if ($row['tracking_id'] !== $trackingId) {
                continue;
            }
            if ($ownerUserId !== null && (int) $row['owner_user_id'] !== $ownerUserId) {
                continue;
            }
            return $row;
        }
        return null;
    }

    public function listEmailsWithCounts(int $userId, int $limit = 200): array
    {
        $emails = array_values(array_filter(
            $this->readAll('emails'),
            fn($row) => (int) $row['owner_user_id'] === $userId
        ));
        return $this->attachCountsAndSort($emails, $limit);
    }

    public function listAllEmailsWithCounts(int $limit = 200): array
    {
        return $this->attachCountsAndSort($this->readAll('emails'), $limit);
    }

    private function attachCountsAndSort(array $emails, int $limit): array
    {
        usort($emails, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        $emails = array_slice($emails, 0, $limit);

        $events = $this->readAll('events');

        foreach ($emails as &$email) {
            $opens = 0;
            $clicks = 0;
            $docOpens = 0;
            foreach ($events as $ev) {
                if ($ev['tracking_id'] !== $email['tracking_id']) {
                    continue;
                }
                if ($ev['event_type'] === 'open') $opens++;
                if ($ev['event_type'] === 'click') $clicks++;
                if ($ev['event_type'] === 'doc_open') $docOpens++;
            }
            $email['opens'] = $opens;
            $email['clicks'] = $clicks;
            $email['doc_opens'] = $docOpens;
        }

        return $emails;
    }

    public function addEvent(string $trackingId, string $eventType, ?array $meta, ?string $ip, ?string $userAgent): void
    {
        $this->transact('events', function (array &$rows) use ($trackingId, $eventType, $meta, $ip, $userAgent) {
            $id = $this->nextId($rows);
            $rows[] = [
                'id' => $id,
                'tracking_id' => $trackingId,
                'event_type' => $eventType,
                'meta' => $meta !== null ? json_encode($meta) : null,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'created_at' => date('Y-m-d H:i:s'),
            ];
            return null;
        });
    }

    public function getEvents(string $trackingId): array
    {
        $rows = array_values(array_filter(
            $this->readAll('events'),
            fn($row) => $row['tracking_id'] === $trackingId
        ));
        usort($rows, fn($a, $b) => strcmp($a['created_at'], $b['created_at']));
        return $rows;
    }

    public function createDocument(string $docUuid, string $trackingId, string $originalName, string $storedPath, ?string $mimeType): int
    {
        return $this->transact('documents', function (array &$rows) use ($docUuid, $trackingId, $originalName, $storedPath, $mimeType) {
            $id = $this->nextId($rows);
            $rows[] = [
                'id' => $id,
                'doc_uuid' => $docUuid,
                'tracking_id' => $trackingId,
                'original_name' => $originalName,
                'stored_path' => $storedPath,
                'mime_type' => $mimeType,
                'created_at' => date('Y-m-d H:i:s'),
            ];
            return $id;
        });
    }

    public function getDocument(string $docUuid, string $trackingId): ?array
    {
        foreach ($this->readAll('documents') as $row) {
            if ($row['doc_uuid'] === $docUuid && $row['tracking_id'] === $trackingId) {
                return $row;
            }
        }
        return null;
    }

    public function getDocumentsForTracking(string $trackingId): array
    {
        return array_values(array_filter(
            $this->readAll('documents'),
            fn($row) => $row['tracking_id'] === $trackingId
        ));
    }

    public function deleteEmail(string $trackingId): array
    {
        $removedPaths = $this->transact('documents', function (array &$rows) use ($trackingId) {
            $kept = [];
            $paths = [];
            foreach ($rows as $row) {
                if ($row['tracking_id'] === $trackingId) {
                    $paths[] = $row['stored_path'];
                } else {
                    $kept[] = $row;
                }
            }
            $rows = $kept;
            return $paths;
        });

        $this->transact('events', function (array &$rows) use ($trackingId) {
            $rows = array_values(array_filter($rows, fn($row) => $row['tracking_id'] !== $trackingId));
            return null;
        });

        $this->transact('emails', function (array &$rows) use ($trackingId) {
            $rows = array_values(array_filter($rows, fn($row) => $row['tracking_id'] !== $trackingId));
            return null;
        });

        return $removedPaths;
    }

    public function deleteAllData(): array
    {
        $removedPaths = $this->transact('documents', function (array &$rows) {
            $paths = array_column($rows, 'stored_path');
            $rows = [];
            return $paths;
        });

        $this->transact('events', function (array &$rows) {
            $rows = [];
            return null;
        });

        $this->transact('emails', function (array &$rows) {
            $rows = [];
            return null;
        });

        return $removedPaths;
    }
}
