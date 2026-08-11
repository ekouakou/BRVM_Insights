-- Migration 021 : annonces émetteurs & publications BRVM — 3e pipeline
-- documentaire du projet, sur le modèle exact des bulletins (market_bulletins/
-- _contents/_analyses) : découverte par scraping des pages d'annonces de
-- brvm.org, téléchargement PDF, extraction texte, restructuration markdown,
-- analyse IA. Types couverts : convocations d'AG, projets de résolution,
-- notations financières, paiements de dividendes, communiqués, changements
-- de dirigeants, franchissements de seuil, avis du marché, données
-- économiques (voir BRVMAnnouncementsScraper::TYPES pour le registre).
--
-- company_id : rattachement automatique via CompanySlugMatcher::
-- matchCompanyName() sur la colonne "Société" des listings (NULL pour les
-- types non liés à un émetteur — avis marché, données économiques — ou si
-- le rapprochement n'a pas la confiance suffisante).
-- publish_date NULL possible : certains listings (données économiques)
-- n'affichent aucune date.

CREATE TABLE issuer_announcements (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    announcement_type VARCHAR(40) NOT NULL COMMENT 'Clé du registre BRVMAnnouncementsScraper::TYPES',
    publish_date DATE NULL,
    company_name_raw VARCHAR(255) NULL COMMENT 'Colonne Société du listing, telle quelle',
    company_id INT NULL,
    match_confidence VARCHAR(10) NULL COMMENT 'exact|fuzzy|NULL',
    title VARCHAR(500) NOT NULL,
    file_url VARCHAR(500) NOT NULL,
    local_path VARCHAR(500) NULL,
    file_size BIGINT NULL,
    file_hash CHAR(64) NULL,
    downloaded_at TIMESTAMP NULL,
    text_extracted TINYINT(1) DEFAULT 0,
    extraction_method VARCHAR(10) NULL,
    extraction_error TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
    UNIQUE KEY uk_file_url (file_url(255)),
    INDEX idx_type_date (announcement_type, publish_date),
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE issuer_announcement_contents (
    announcement_id BIGINT PRIMARY KEY,
    extracted_text LONGTEXT,
    formatted_markdown LONGTEXT NULL,
    markdown_status VARCHAR(20) NULL,
    markdown_error TEXT NULL,
    markdown_provider VARCHAR(30) NULL,
    markdown_model VARCHAR(50) NULL,
    markdown_updated_at TIMESTAMP NULL,
    char_count INT NULL,
    extracted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (announcement_id) REFERENCES issuer_announcements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE issuer_announcement_analyses (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    announcement_id BIGINT NOT NULL,
    provider VARCHAR(30) NOT NULL DEFAULT 'gemini',
    model VARCHAR(50) NOT NULL,
    summary TEXT,
    details JSON NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'success',
    error_message TEXT NULL,
    input_char_count INT NULL,
    raw_response LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (announcement_id) REFERENCES issuer_announcements(id) ON DELETE CASCADE,
    UNIQUE KEY uk_announcement_provider_model (announcement_id, provider, model)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
