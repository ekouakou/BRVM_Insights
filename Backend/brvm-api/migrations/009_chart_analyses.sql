-- Migration 009 : analyse IA des graphes/tableaux de comparaison (voir
-- TODO_CHART_AI_ANALYSIS.md) — un enregistrement par (chart_type,
-- paramètres exacts, fournisseur, modèle), pour permettre à la fois le
-- cache (éviter de refacturer un appel IA identique) et la comparaison de
-- plusieurs fournisseurs/modèles sur la même sélection.
--
-- Même conventions que les tables d'analyse déjà en place
-- (company_report_analyses, market_bulletin_analyses, combined_analyses) :
-- request_hash + provider + model comme clé de dédoublonnage, summary +
-- details (JSON) + raw_response pour le résultat, status/error_message
-- pour les échecs.
--
-- Pas de "USE brvm_trading_app" ici : exécute ceci sur la base déjà
-- sélectionnée (le nom réel peut différer selon l'hébergeur — voir
-- scripts/migrate.php, qui applique ce fichier sans le modifier).

CREATE TABLE IF NOT EXISTS chart_analyses (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    chart_type VARCHAR(50) NOT NULL COMMENT 'ex: quotes_comparison, quotes_signals, quotes_close_sma, market_summary, total_variation, correlation, risk_adjusted, relative_strength, sector_performance, market_breadth, data_quality_reconciliation, data_quality_price_jumps, data_quality_missing_days',
    request_hash CHAR(64) NOT NULL COMMENT 'sha256(chart_type + paramètres normalisés, y compris la sélection de lignes pour les tableaux) — détecte les requêtes identiques',
    parameters LONGTEXT NOT NULL COMMENT 'JSON des paramètres exacts (company_ids, sector, start_date, end_date, granularity, display_mode, selected_rows...)',
    provider VARCHAR(30) NOT NULL DEFAULT 'gemini',
    model VARCHAR(50) NOT NULL,
    summary TEXT NULL,
    details LONGTEXT NULL COMMENT 'JSON structuré : méthodologie expliquée, observations clés, points notables',
    status VARCHAR(20) NOT NULL DEFAULT 'success',
    error_message TEXT NULL,
    data_points_count INT NULL COMMENT 'taille des données envoyées, pour contexte/debug',
    raw_response LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_chart_type (chart_type),
    UNIQUE KEY uk_request_provider_model (request_hash, provider, model)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
