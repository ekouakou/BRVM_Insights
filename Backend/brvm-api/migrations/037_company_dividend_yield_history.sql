-- Migration 037 : historique chiffré de la politique de rémunération
-- (dividende) d'une entreprise — pendant numérique/historisé des notes
-- textuelles déjà en place (company_analysis_notes.levier_remuneration /
-- perspective_remuneration, migration 029). Deux indicateurs distincts,
-- définis dans ANALYSE_ENTREPRISES_BRVM.md (section « Les leviers communs
-- à examiner pour n'importe quelle entreprise ») :
--   - taux_distribution (payout ratio) = dividende total / résultat net
--   - rendement_dividende (dividend yield) = dividende par action / cours
-- Même forme que company_local_price_history/company_world_price_history
-- (migrations 033/035) : une série de relevés datés, saisie manuelle.
--
-- Pas de "USE brvm_trading_app" ici : exécute ceci sur la base déjà
-- sélectionnée (voir scripts/migrate.php).

CREATE TABLE company_dividend_yield_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    record_date DATE NOT NULL COMMENT 'Date de référence (souvent la date de l''AGO / de l''exercice concerné)',
    metric_type ENUM('taux_distribution', 'rendement_dividende') NOT NULL,
    percent_value DECIMAL(6,2) NOT NULL COMMENT 'En %, ex. 65.30',
    fiscal_year SMALLINT NULL COMMENT 'Exercice concerné si connu, ex. 2025',
    note TEXT NULL,
    source_note VARCHAR(255) NULL,
    source_url VARCHAR(500) NULL,
    created_by_admin_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_admin_user_id) REFERENCES admin_users(id) ON DELETE SET NULL,
    INDEX idx_company_type_date (company_id, metric_type, record_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
