-- Migration 024 : PER et rendement net officiels BRVM, extraits du tableau
-- par valeur de chaque Bulletin Officiel de la Cote (BOC).
--
-- Contexte : États financiers (FinancialStatements.tsx) calcule son propre
-- PER/PBR à partir de companies.shares_outstanding, qui n'a PAS d'historique
-- (une seule valeur "actuelle" par entreprise, pas de suivi dans le temps) —
-- un PER calculé sur un rapport ancien utilise donc le nombre d'actions
-- d'aujourd'hui, faussé en cas d'augmentation de capital/rachat/split
-- depuis. Chaque BOC publie pourtant déjà, par valeur et par séance, un PER
-- et un rendement net calculés par la BRVM elle-même avec le bon nombre
-- d'actions à cette date précise — cette migration capture cette donnée
-- pour l'afficher à côté de notre calcul, sans avoir à reconstruire un
-- historique d'actions en circulation.
--
-- Même pattern exact que la migration 012 (market_bulletin_corporate_actions) :
-- stock_metrics_* sur market_bulletin_contents pour savoir si l'extraction a
-- déjà tourné sur ce bulletin, une table dédiée pour les lignes extraites.
-- Rattachement par SYMBOLE (pas par nom via CompanySlugMatcher comme les
-- opérations sur titres) : le tableau du BOC liste déjà le code exact
-- (ex: "SNTS", "ORAC"), qui correspond directement à companies.symbol —
-- plus fiable qu'un rapprochement flou sur le nom.
--
-- Pas de "USE brvm_trading_app" ici : exécute ceci sur la base déjà
-- sélectionnée (voir scripts/migrate.php).

ALTER TABLE market_bulletin_contents
    ADD COLUMN stock_metrics_status VARCHAR(20) NULL COMMENT 'success|error|null si jamais lancé' AFTER corporate_actions_updated_at,
    ADD COLUMN stock_metrics_error TEXT NULL AFTER stock_metrics_status,
    ADD COLUMN stock_metrics_provider VARCHAR(30) NULL AFTER stock_metrics_error,
    ADD COLUMN stock_metrics_model VARCHAR(50) NULL AFTER stock_metrics_provider,
    ADD COLUMN stock_metrics_updated_at TIMESTAMP NULL AFTER stock_metrics_model;

CREATE TABLE IF NOT EXISTS bulletin_stock_metrics (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    bulletin_id BIGINT NOT NULL,
    publish_date DATE NOT NULL COMMENT 'Dupliqué de market_bulletins.publish_date pour permettre une recherche "au plus proche d''une date" sans jointure',
    company_id INT NULL,
    symbol_raw VARCHAR(20) NOT NULL COMMENT 'Code tel qu''écrit dans le bulletin (ex: SNTS) — non corrigé',
    company_name_raw VARCHAR(255) NULL,
    close_price DECIMAL(15,4) NULL COMMENT 'Cours de clôture du jour, tel qu''imprimé dans le BOC',
    per DECIMAL(12,4) NULL COMMENT 'PER officiel BRVM pour cette séance, tel qu''imprimé dans le BOC',
    yield_net_percent DECIMAL(10,4) NULL COMMENT 'Rendement net officiel BRVM (colonne "Rdt. Net"), en %',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bulletin_id) REFERENCES market_bulletins(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
    UNIQUE KEY uk_bulletin_symbol (bulletin_id, symbol_raw),
    INDEX idx_company_date (company_id, publish_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
