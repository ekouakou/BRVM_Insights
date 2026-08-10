-- Migration 015 : analyse IA structurée des documents complémentaires
-- (company_documents) — même schéma que company_report_analyses (exécutif,
-- fondamentaux, SWOT, risques, valorisation...), pour que les documents
-- complémentaires comptent pleinement dans les statistiques agrégées de
-- l'onglet Rapports (voir class/CompanyDocumentAnalysisService.php), au même
-- titre que les rapports officiels — pas seulement comme contexte texte brut
-- pour d'autres analyses (usage précédent, toujours actif en parallèle).
--
-- Pas de "USE brvm_trading_app" ici : exécute ceci sur la base déjà
-- sélectionnée (voir scripts/migrate.php).

CREATE TABLE IF NOT EXISTS company_document_analyses (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    document_id BIGINT NOT NULL,
    company_id INT NOT NULL,
    provider VARCHAR(30) NOT NULL DEFAULT 'gemini' COMMENT 'anthropic, gemini...',
    model VARCHAR(50) NOT NULL,
    market_context_date DATE NULL COMMENT 'trading_date des cours/indicateurs utilisés comme contexte',
    summary TEXT COMMENT 'résumé exécutif court, pour affichage/listage rapide',
    details JSON NULL COMMENT 'analyse complète structurée (financials, SWOT, risques, thèse, glossaire...)',
    status VARCHAR(20) NOT NULL DEFAULT 'success' COMMENT 'success|failed',
    error_message TEXT NULL,
    input_char_count INT NULL,
    raw_response LONGTEXT NULL COMMENT 'réponse brute du fournisseur IA, pour audit/debug',
    rating TINYINT NULL COMMENT '1-5 étoiles',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (document_id) REFERENCES company_documents(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,

    UNIQUE KEY uk_document_provider_model_date (document_id, provider, model, market_context_date),
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
