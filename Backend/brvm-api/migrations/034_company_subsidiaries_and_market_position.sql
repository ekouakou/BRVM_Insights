-- Migration 034 : filiales détenues par une entreprise cotée, et
-- positionnement/classement (part de marché, rang national/régional/
-- mondial) — deux notions distinctes de l'actionnariat déjà couvert
-- (company_shareholders, migration 029, qui historise QUI détient le
-- capital d'une société cotée) : ici, QUOI une société cotée détient
-- elle-même (ses propres filiales), et où elle se situe face à la
-- concurrence. Voir ANALYSE_ENTREPRISES_BRVM.md.
--
-- Pas de "USE brvm_trading_app" ici : exécute ceci sur la base déjà
-- sélectionnée (voir scripts/migrate.php).

CREATE TABLE company_subsidiaries (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL COMMENT 'La société cotée qui détient la filiale',
    subsidiary_name VARCHAR(200) NOT NULL,
    country VARCHAR(100) NULL,
    ownership_percent DECIMAL(5,2) NULL COMMENT 'NULL si non chiffré précisément par la source',
    linked_company_id INT NULL COMMENT 'Renseigné si la filiale est elle-même une des 47 sociétés cotées (ex. ETIT -> ECOC)',
    description TEXT NULL,
    source_note VARCHAR(255) NULL,
    source_url VARCHAR(500) NULL,
    created_by_admin_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (linked_company_id) REFERENCES companies(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by_admin_user_id) REFERENCES admin_users(id) ON DELETE SET NULL,
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE company_market_position (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    scope ENUM('local', 'national', 'regional', 'mondial') NOT NULL
        COMMENT 'Échelle du classement — local (ex. port d''Abidjan), national, régional (ex. UMOA, Afrique de l''Ouest), mondial',
    category VARCHAR(150) NOT NULL COMMENT 'Ex. "Distribution automobile", "Opérateurs télécoms"',
    rank_value SMALLINT NULL COMMENT 'Ex. 1, 7 — NULL si non chiffré (ex. "leader" sans rang précis donné par la source)',
    rank_label VARCHAR(255) NOT NULL COMMENT 'Texte tel quel de la source, ex. "1er réseau automobile du pays"',
    market_share_percent DECIMAL(5,2) NULL,
    source_note VARCHAR(255) NULL,
    source_url VARCHAR(500) NULL,
    created_by_admin_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_admin_user_id) REFERENCES admin_users(id) ON DELETE SET NULL,
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
