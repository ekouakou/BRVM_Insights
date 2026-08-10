-- ALREADY-IN-BD-SQL
-- Migration 013 : documents complémentaires ajoutés manuellement par
-- entreprise (rapports détaillés publiés sur le site de l'entreprise mais
-- absents/résumés dans les rapports officiels scrapés depuis brvm.org,
-- présentations investisseurs, etc.) — même principe que company_reports
-- (extraction de texte + formatage markdown IA) mais alimenté par upload
-- manuel plutôt que par scraping, voir class/PdfTextExtractor.php et
-- class/CompanyDocumentMarkdownFormatterService.php.
--
-- Pas de "USE brvm_trading_app" ici : exécute ceci sur la base déjà
-- sélectionnée (voir scripts/migrate.php).

CREATE TABLE IF NOT EXISTS company_documents (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    title VARCHAR(500) NOT NULL COMMENT 'Titre donné manuellement lors de l’upload',
    original_filename VARCHAR(255) NOT NULL,
    local_path VARCHAR(500) NOT NULL,
    file_size BIGINT,
    file_hash CHAR(64),
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    text_extracted TINYINT(1) DEFAULT 0,
    extraction_method VARCHAR(10) NULL COMMENT 'text (pdftotext) ou ocr (tesseract)',
    extraction_error TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_document_contents (
    document_id BIGINT PRIMARY KEY,
    extracted_text LONGTEXT,
    formatted_markdown LONGTEXT NULL,
    markdown_status VARCHAR(20) NULL COMMENT 'processing|success|failed',
    markdown_error TEXT NULL,
    markdown_provider VARCHAR(30) NULL,
    markdown_model VARCHAR(50) NULL,
    markdown_updated_at TIMESTAMP NULL,
    char_count INT,
    extracted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (document_id) REFERENCES company_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
