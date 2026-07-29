-- Schéma de base pour Gmail Tracker Pro
-- A importer via phpMyAdmin (InfinityFree fournit un accès phpMyAdmin dans le panel MySQL)
--
-- Si tu as déjà une base créée avec une version précédente de ce schéma (sans la colonne
-- "category"), exécute cette ligne une fois pour la mettre à jour au lieu de tout réimporter :
--   ALTER TABLE emails ADD COLUMN category VARCHAR(100) DEFAULT NULL, ADD INDEX idx_category (category);

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    -- token utilisé par l'extension Chrome pour s'authentifier auprès de l'API (voir upload_document.php)
    api_token_hash VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS emails (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tracking_id CHAR(36) NOT NULL UNIQUE,
    owner_user_id INT NOT NULL,
    subject VARCHAR(500) DEFAULT NULL,
    recipient VARCHAR(320) DEFAULT NULL,
    category VARCHAR(100) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_owner (owner_user_id),
    INDEX idx_category (category),
    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Une seule table d'évènements au lieu de 6 fichiers texte parsés à coup de regex
CREATE TABLE IF NOT EXISTS events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    tracking_id CHAR(36) NOT NULL,
    event_type ENUM('open','click','doc_open','doc_scroll','doc_time','doc_close') NOT NULL,
    meta JSON DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tracking (tracking_id),
    INDEX idx_type (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Documents envoyés en mode "consultation trackée" (upload explicite, pas de substitution silencieuse)
CREATE TABLE IF NOT EXISTS documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doc_uuid CHAR(36) NOT NULL UNIQUE,
    tracking_id CHAR(36) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tracking (tracking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table pour signer les liens de clic (anti open-redirect)
-- Le token HMAC est calculé côté PHP au moment de la création du lien, pas stocké ici.
