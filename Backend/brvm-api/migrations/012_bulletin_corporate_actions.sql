-- Migration 012 : suivi structuré des dividendes et opérations sur titres
-- (TODO_ANALYSES.md, point 12).
--
-- Jusqu'ici, l'analyse IA des bulletins (market_bulletin_analyses) ne
-- produit qu'un résumé libre par bulletin (notable_movements en texte).
-- Ici on ajoute une extraction dédiée, structurée et interrogeable :
-- chaque opération (dividende, augmentation de capital, admission,
-- assemblée générale...) mentionnée dans un bulletin devient une ligne,
-- rattachée si possible à une entreprise de la table companies.
--
-- corporate_actions_* sur market_bulletin_contents : mêmes colonnes de
-- statut que markdown_status/markdown_error/... (voir migration des
-- rapports formatés), pour savoir si l'extraction IA a déjà tourné sur
-- ce bulletin sans avoir à interroger market_bulletin_corporate_actions.
--
-- Pas de "USE brvm_trading_app" ici : exécute ceci sur la base déjà
-- sélectionnée (voir scripts/migrate.php).

ALTER TABLE market_bulletin_contents
    ADD COLUMN corporate_actions_status VARCHAR(20) NULL COMMENT 'success|error|null si jamais lancé' AFTER markdown_updated_at,
    ADD COLUMN corporate_actions_error TEXT NULL AFTER corporate_actions_status,
    ADD COLUMN corporate_actions_provider VARCHAR(30) NULL AFTER corporate_actions_error,
    ADD COLUMN corporate_actions_model VARCHAR(50) NULL AFTER corporate_actions_provider,
    ADD COLUMN corporate_actions_updated_at TIMESTAMP NULL AFTER corporate_actions_model;

CREATE TABLE IF NOT EXISTS market_bulletin_corporate_actions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    bulletin_id BIGINT NOT NULL,
    company_id INT NULL,
    company_name_raw VARCHAR(255) NOT NULL COMMENT 'Nom tel qu''écrit dans le bulletin, non corrigé par l''IA',
    match_confidence VARCHAR(10) NULL COMMENT 'exact|fuzzy|NULL si aucune entreprise rattachée',
    action_type VARCHAR(30) NOT NULL COMMENT 'dividende|augmentation_capital|admission|assemblee_generale|autre',
    event_date DATE NULL COMMENT 'Date de l''opération (paiement, AG, ...) si mentionnée',
    amount DECIMAL(15,4) NULL COMMENT 'Montant si pertinent (ex: dividende par action)',
    currency VARCHAR(10) NOT NULL DEFAULT 'FCFA',
    description TEXT NULL,
    source_section VARCHAR(100) NULL COMMENT 'Section du bulletin d''où provient l''info (best-effort, non standardisé)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bulletin_id) REFERENCES market_bulletins(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
    INDEX idx_bulletin (bulletin_id),
    INDEX idx_company (company_id),
    INDEX idx_event_date (event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
