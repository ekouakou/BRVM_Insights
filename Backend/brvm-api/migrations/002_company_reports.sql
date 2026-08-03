-- Migration 002 : rapports des sociétés cotées (scraping + stockage + extraction de texte)
-- À appliquer sur une base déjà créée par BD.sql (le schéma de BD.sql inclut déjà
-- ces mêmes objets pour les nouvelles installations).

-- Pas de "USE brvm_trading_app" ici : exécute ceci sur la base déjà
-- sélectionnée (le nom réel peut différer selon l'hébergeur — voir
-- scripts/migrate.php, qui applique ce fichier sans le modifier).

ALTER TABLE companies
    ADD COLUMN brvm_report_slug VARCHAR(150) NULL
        COMMENT 'Slug de /fr/rapports-societe-cotes/{slug} sur brvm.org'
        AFTER logo_url,
    ADD UNIQUE KEY uk_brvm_report_slug (brvm_report_slug);

CREATE TABLE company_reports (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    report_type VARCHAR(50) COMMENT 'annuel, semestriel, trimestriel, etats_financiers, attestation_cac, autre',
    title VARCHAR(500) NOT NULL,
    publish_date DATE COMMENT 'Déduite du préfixe YYYYMMDD du nom de fichier (peut être approximative)',
    file_url VARCHAR(500) NOT NULL COMMENT 'URL source sur brvm.org',
    local_path VARCHAR(500) COMMENT 'Chemin local du PDF téléchargé',
    file_size BIGINT COMMENT 'Taille du fichier en octets',
    file_hash CHAR(64) COMMENT 'SHA-256 du contenu téléchargé (détection de doublons/changements)',
    downloaded_at TIMESTAMP NULL,
    text_extracted TINYINT(1) DEFAULT 0,
    extraction_error TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,

    UNIQUE KEY uk_file_url (file_url(255)),
    INDEX idx_company (company_id),
    INDEX idx_publish_date (publish_date),
    INDEX idx_report_type (report_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE company_report_contents (
    report_id BIGINT PRIMARY KEY,
    extracted_text LONGTEXT,
    char_count INT,
    extracted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (report_id) REFERENCES company_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
