-- ============================================
-- BASE DE DONNÉES BRVM TRADING APP (VERSION CORRIGÉE)
-- ============================================
--
-- Variante de BD.sql pour import via phpMyAdmin sur un hébergement
-- mutualisé (cPanel) : sans "CREATE DATABASE"/"USE" (généré depuis BD.sql —
-- toute évolution du schéma doit être faite dans BD.sql puis reportée ici,
-- pas l'inverse). Sur cPanel, la base est créée par l'outil "MySQL®
-- Databases" (qui préfixe son nom par ton nom de compte, ex:
-- "monlogin_brvm_trading_app") — tu n'as pas le privilège CREATE DATABASE
-- en SQL direct, et "USE brvm_trading_app" échouerait puisque ce nom exact
-- n'existe pas. Importe ce fichier directement DANS la base déjà créée et
-- sélectionnée dans phpMyAdmin (voir DEPLOY_CPANEL.md).
-- ============================================


-- ============================================
-- TABLE: Secteurs d'activité
-- ============================================
CREATE TABLE sectors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: Pays
-- ============================================
CREATE TABLE countries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code CHAR(2) NOT NULL UNIQUE COMMENT 'Code ISO 2 lettres',
    name VARCHAR(100) NOT NULL,
    currency_code CHAR(3) DEFAULT 'XOF' COMMENT 'Code devise ISO',
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: Entreprises cotées
-- ============================================
CREATE TABLE companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    symbol VARCHAR(10) NOT NULL UNIQUE COMMENT 'Symbole boursier',
    name VARCHAR(255) NOT NULL,
    full_name VARCHAR(255),
    sector_id INT,
    country_id INT,
    isin_code VARCHAR(12) COMMENT 'Code ISIN international',
    market_cap DECIMAL(20, 2) COMMENT 'Capitalisation boursière',
    shares_outstanding BIGINT COMMENT 'Nombre d\'actions en circulation',
    listing_date DATE COMMENT 'Date d\'introduction en bourse',
    website VARCHAR(255),
    description TEXT,
    logo_url VARCHAR(255),
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (sector_id) REFERENCES sectors(id) ON DELETE SET NULL,
    FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE SET NULL,
    
    INDEX idx_symbol (symbol),
    INDEX idx_sector (sector_id),
    INDEX idx_country (country_id),
    INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: Cotations en temps réel
-- ============================================
CREATE TABLE stock_quotes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    trading_date DATE NOT NULL,
    open_price DECIMAL(15, 2) NOT NULL COMMENT 'Cours d\'ouverture',
    close_price DECIMAL(15, 2) NOT NULL COMMENT 'Cours de clôture',
    high_price DECIMAL(15, 2) COMMENT 'Plus haut',
    low_price DECIMAL(15, 2) COMMENT 'Plus bas',
    previous_close DECIMAL(15, 2) COMMENT 'Clôture veille',
    volume BIGINT DEFAULT 0 COMMENT 'Volume échangé',
    variation_percent DECIMAL(10, 4) COMMENT 'Variation en %',
    variation_value DECIMAL(15, 2) COMMENT 'Variation en valeur',
    turnover DECIMAL(20, 2) COMMENT 'Valeur échangée',
    trades_count INT DEFAULT 0 COMMENT 'Nombre de transactions',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    
    UNIQUE KEY uk_company_date (company_id, trading_date),
    INDEX idx_trading_date (trading_date),
    INDEX idx_company_id (company_id),
    INDEX idx_close_price (close_price),
    INDEX idx_volume (volume),
    INDEX idx_variation (variation_percent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: Historique intrajournalier
-- ============================================
-- Un enregistrement par entreprise à chaque synchronisation (toutes les 15
-- minutes pendant les heures de marché, voir CRON_SETUP.md) : contrairement à
-- stock_quotes (une ligne par jour, écrasée à chaque synchro), cette table
-- conserve chaque relevé pour pouvoir observer la variation du cours au fil
-- de la séance.
CREATE TABLE intraday_quotes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    quote_datetime DATETIME NOT NULL,
    price DECIMAL(15, 2) NOT NULL,
    volume BIGINT DEFAULT 0,
    variation_percent DECIMAL(10, 4) COMMENT 'Variation en % depuis la clôture précédente',
    bid_price DECIMAL(15, 2) COMMENT 'Meilleure offre achat',
    ask_price DECIMAL(15, 2) COMMENT 'Meilleure offre vente',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,

    INDEX idx_company_datetime (company_id, quote_datetime),
    INDEX idx_datetime (quote_datetime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: Indices boursiers
-- ============================================
CREATE TABLE market_indices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE COMMENT 'Ex: BRVM10, BRVMC',
    name VARCHAR(100) NOT NULL,
    description TEXT,
    base_value DECIMAL(15, 2) DEFAULT 100 COMMENT 'Valeur de base',
    base_date DATE COMMENT 'Date de base',
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: Valeurs des indices
-- ============================================
CREATE TABLE index_values (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    index_id INT NOT NULL,
    trading_date DATE NOT NULL,
    open_value DECIMAL(15, 2),
    close_value DECIMAL(15, 2) NOT NULL,
    high_value DECIMAL(15, 2),
    low_value DECIMAL(15, 2),
    variation_percent DECIMAL(10, 4),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (index_id) REFERENCES market_indices(id) ON DELETE CASCADE,
    
    UNIQUE KEY uk_index_date (index_id, trading_date),
    INDEX idx_trading_date (trading_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: Composition des indices
-- ============================================
CREATE TABLE index_composition (
    id INT AUTO_INCREMENT PRIMARY KEY,
    index_id INT NOT NULL,
    company_id INT NOT NULL,
    weight DECIMAL(10, 6) COMMENT 'Poids en %',
    entry_date DATE,
    exit_date DATE,
    active TINYINT(1) DEFAULT 1,
    
    FOREIGN KEY (index_id) REFERENCES market_indices(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    
    UNIQUE KEY uk_index_company (index_id, company_id),
    INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: Configuration du système
-- ============================================
CREATE TABLE system_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    config_key VARCHAR(100) NOT NULL UNIQUE,
    config_value TEXT,
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_key (config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: Logs de synchronisation (CORRIGÉE)
-- ============================================
CREATE TABLE sync_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    sync_type VARCHAR(50) NOT NULL COMMENT 'quotes, companies, indices',
    sync_status ENUM('started', 'success', 'failed', 'partial') NOT NULL,
    records_processed INT DEFAULT 0,
    records_inserted INT DEFAULT 0,
    records_updated INT DEFAULT 0,
    records_failed INT DEFAULT 0,
    error_message TEXT,
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    execution_time INT COMMENT 'Temps d\'exécution en secondes',
    
    INDEX idx_sync_type (sync_type),
    INDEX idx_sync_status (sync_status),
    INDEX idx_started_at (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: Alertes de prix
-- ============================================
CREATE TABLE price_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    alert_type ENUM('above', 'below', 'change_percent') NOT NULL,
    target_price DECIMAL(15, 2),
    target_percent DECIMAL(10, 4),
    notification_email VARCHAR(255),
    notification_webhook VARCHAR(255),
    triggered TINYINT(1) DEFAULT 0,
    triggered_at TIMESTAMP NULL DEFAULT NULL,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    
    INDEX idx_company_active (company_id, active),
    INDEX idx_triggered (triggered)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: Indicateurs techniques calculés
-- ============================================
CREATE TABLE technical_indicators (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    trading_date DATE NOT NULL,
    
    -- Moyennes mobiles
    sma_10 DECIMAL(15, 4) COMMENT 'Moyenne mobile simple 10 jours',
    sma_20 DECIMAL(15, 4),
    sma_50 DECIMAL(15, 4),
    sma_200 DECIMAL(15, 4),
    ema_10 DECIMAL(15, 4) COMMENT 'Moyenne mobile exponentielle 10 jours',
    ema_20 DECIMAL(15, 4),
    
    -- RSI
    rsi_14 DECIMAL(10, 4) COMMENT 'RSI 14 périodes',
    
    -- MACD
    macd_line DECIMAL(15, 4),
    macd_signal DECIMAL(15, 4),
    macd_histogram DECIMAL(15, 4),
    
    -- Bandes de Bollinger
    bb_upper DECIMAL(15, 4),
    bb_middle DECIMAL(15, 4),
    bb_lower DECIMAL(15, 4),
    
    -- Autres
    atr_14 DECIMAL(15, 4) COMMENT 'Average True Range',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    
    UNIQUE KEY uk_company_date (company_id, trading_date),
    INDEX idx_trading_date (trading_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- DONNÉES INITIALES
-- ============================================

-- Configuration système
INSERT INTO system_config (config_key, config_value, description) VALUES
('market_open_time', '08:30', 'Heure d\'ouverture du marché (format HH:MM)'),
('market_close_time', '16:00', 'Heure de fermeture du marché'),
('trading_days', 'monday,tuesday,wednesday,thursday,friday', 'Jours de trading'),
('sync_interval_minutes', '10', 'Intervalle de synchronisation pendant les heures de marché (minutes)'),
('timezone', 'Africa/Abidjan', 'Fuseau horaire du marché'),
('api_rate_limit', '100', 'Nombre de requêtes API par minute'),
('data_retention_days', '730', 'Nombre de jours de conservation des données (2 ans)');

-- Pays de la zone BRVM
INSERT INTO countries (code, name, currency_code) VALUES
('CI', 'Côte d\'Ivoire', 'XOF'),
('SN', 'Sénégal', 'XOF'),
('BF', 'Burkina Faso', 'XOF'),
('BJ', 'Bénin', 'XOF'),
('TG', 'Togo', 'XOF'),
('NE', 'Niger', 'XOF'),
('ML', 'Mali', 'XOF'),
('GW', 'Guinée-Bissau', 'XOF');

-- Secteurs d'activité
INSERT INTO sectors (name, description) VALUES
('Banques', 'Institutions bancaires et financières'),
('Assurances', 'Compagnies d\'assurance et de réassurance'),
('Distribution', 'Commerce de gros et de détail'),
('Agriculture', 'Agro-industrie et production agricole'),
('Industrie', 'Industries manufacturières'),
('Transport', 'Transport et logistique'),
('Télécommunications', 'Opérateurs télécoms et services'),
('Énergie', 'Production et distribution d\'énergie'),
('Services', 'Services divers'),
('Immobilier', 'Promotion et gestion immobilière');

-- Indices BRVM
-- NB: BRVM10 a été remplacé par BRVM-30 en 2023 (élargissement à 30 valeurs).
-- Ces codes sont ceux affichés sur https://www.brvm.org/fr/indices ; le scraper
-- (class/BRVMSyncService.php) crée aussi automatiquement tout nouvel indice détecté.
INSERT INTO market_indices (code, name, description, base_value, base_date) VALUES
('BRVM-30', 'BRVM-30', 'Indice phare des 30 valeurs les plus actives (a remplacé BRVM10 en 2023)', 100, '1998-09-16'),
('BRVM-COMPOSITE', 'BRVM - COMPOSITE', 'Indice composite de toutes les valeurs', 100, '1998-09-16'),
('BRVM-PRESTIGE', 'BRVM - PRESTIGE', 'Indice des valeurs du compartiment Prestige', 100, '2014-01-02'),
('BRVM-PRINCIPAL', 'BRVM - PRINCIPAL', 'Indice des valeurs du compartiment Principal', 100, '2014-01-02');

-- ============================================
-- VUES UTILES (VERSION COMPATIBLE)
-- ============================================

-- Suppression des vues si elles existent
DROP VIEW IF EXISTS latest_quotes;
DROP VIEW IF EXISTS top_gainers;
DROP VIEW IF EXISTS top_losers;
DROP VIEW IF EXISTS volume_leaders;

-- Vue: Dernière cotation de chaque action
CREATE VIEW latest_quotes AS
SELECT 
    c.id AS company_id,
    c.symbol,
    c.name,
    s.name AS sector,
    sq.trading_date,
    sq.open_price,
    sq.close_price,
    sq.high_price,
    sq.low_price,
    sq.previous_close,
    sq.volume,
    sq.variation_percent,
    sq.turnover
FROM companies c
LEFT JOIN sectors s ON c.sector_id = s.id
LEFT JOIN stock_quotes sq ON c.id = sq.company_id
WHERE sq.trading_date = (
    SELECT MAX(trading_date) 
    FROM stock_quotes 
    WHERE company_id = c.id
)
AND c.active = 1
ORDER BY c.symbol;

-- Vue: Top gainers du jour
CREATE VIEW top_gainers AS
SELECT 
    c.symbol,
    c.name,
    sq.close_price,
    sq.variation_percent,
    sq.volume,
    sq.trading_date
FROM stock_quotes sq
JOIN companies c ON sq.company_id = c.id
WHERE sq.trading_date = (SELECT MAX(trading_date) FROM stock_quotes)
AND sq.variation_percent > 0
AND c.active = 1
ORDER BY sq.variation_percent DESC
LIMIT 10;

-- Vue: Top losers du jour
CREATE VIEW top_losers AS
SELECT 
    c.symbol,
    c.name,
    sq.close_price,
    sq.variation_percent,
    sq.volume,
    sq.trading_date
FROM stock_quotes sq
JOIN companies c ON sq.company_id = c.id
WHERE sq.trading_date = (SELECT MAX(trading_date) FROM stock_quotes)
AND sq.variation_percent < 0
AND c.active = 1
ORDER BY sq.variation_percent ASC
LIMIT 10;

-- Vue: Volume leaders
CREATE VIEW volume_leaders AS
SELECT 
    c.symbol,
    c.name,
    sq.close_price,
    sq.volume,
    sq.turnover,
    sq.variation_percent,
    sq.trading_date
FROM stock_quotes sq
JOIN companies c ON sq.company_id = c.id
WHERE sq.trading_date = (SELECT MAX(trading_date) FROM stock_quotes)
AND c.active = 1
ORDER BY sq.volume DESC
LIMIT 10;

-- ============================================
-- PROCÉDURES STOCKÉES
-- ============================================

DELIMITER //

-- Procédure: Calculer les indicateurs techniques pour une action
CREATE PROCEDURE calculate_technical_indicators(
    IN p_company_id INT,
    IN p_trading_date DATE
)
BEGIN
    DECLARE v_sma_10 DECIMAL(15, 4);
    DECLARE v_sma_20 DECIMAL(15, 4);
    DECLARE v_sma_50 DECIMAL(15, 4);
    
    -- Calcul SMA 10
    SELECT AVG(close_price) INTO v_sma_10
    FROM (
        SELECT close_price 
        FROM stock_quotes 
        WHERE company_id = p_company_id 
        AND trading_date <= p_trading_date
        ORDER BY trading_date DESC 
        LIMIT 10
    ) AS recent_prices;
    
    -- Calcul SMA 20
    SELECT AVG(close_price) INTO v_sma_20
    FROM (
        SELECT close_price 
        FROM stock_quotes 
        WHERE company_id = p_company_id 
        AND trading_date <= p_trading_date
        ORDER BY trading_date DESC 
        LIMIT 20
    ) AS recent_prices;
    
    -- Calcul SMA 50
    SELECT AVG(close_price) INTO v_sma_50
    FROM (
        SELECT close_price 
        FROM stock_quotes 
        WHERE company_id = p_company_id 
        AND trading_date <= p_trading_date
        ORDER BY trading_date DESC 
        LIMIT 50
    ) AS recent_prices;
    
    -- Insertion ou mise à jour
    INSERT INTO technical_indicators (
        company_id, trading_date, sma_10, sma_20, sma_50
    ) VALUES (
        p_company_id, p_trading_date, v_sma_10, v_sma_20, v_sma_50
    )
    ON DUPLICATE KEY UPDATE
        sma_10 = v_sma_10,
        sma_20 = v_sma_20,
        sma_50 = v_sma_50;
END//

DELIMITER ;

-- ============================================
-- INDEX SUPPLÉMENTAIRES POUR PERFORMANCE
-- ============================================

-- Pour les recherches par plage de dates
CREATE INDEX idx_quotes_date_range ON stock_quotes(company_id, trading_date, close_price);

-- Pour les statistiques par secteur
CREATE INDEX idx_companies_sector_active ON companies(sector_id, active);

-- Pour le tri par performance
CREATE INDEX idx_quotes_performance ON stock_quotes(trading_date, variation_percent DESC);

-- ============================================
-- RAPPORTS DES SOCIÉTÉS COTÉES
-- (voir migrations/002_company_reports.sql pour l'historique de cette évolution)
-- ============================================

-- Rattachement d'une entreprise à sa page rapports sur brvm.org
-- (ex: /fr/rapports-societe-cotes/bici-ci) — pas toujours déductible automatiquement
-- du nom/symbole (renommages de sociétés au fil du temps).
ALTER TABLE companies
    ADD COLUMN brvm_report_slug VARCHAR(150) NULL
        COMMENT 'Slug de /fr/rapports-societe-cotes/{slug} sur brvm.org'
        AFTER logo_url,
    ADD UNIQUE KEY uk_brvm_report_slug (brvm_report_slug);

-- Métadonnées de chaque rapport (PDF) publié par une société cotée
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
    extraction_method VARCHAR(10) NULL COMMENT 'text (pdftotext) ou ocr (tesseract)',
    extraction_error TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,

    UNIQUE KEY uk_file_url (file_url(255)),
    INDEX idx_company (company_id),
    INDEX idx_publish_date (publish_date),
    INDEX idx_report_type (report_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contenu texte extrait des PDF (séparé de company_reports pour ne pas alourdir
-- les listages : ce texte peut faire plusieurs dizaines de Ko par rapport).
-- Pensé pour être consommé par une analyse IA (résumé, extraction de chiffres clés...).
CREATE TABLE company_report_contents (
    report_id BIGINT PRIMARY KEY,
    extracted_text LONGTEXT,
    formatted_markdown LONGTEXT NULL COMMENT 'Texte brut restructuré en markdown (tableaux) par IA, voir class/ReportMarkdownFormatterService.php',
    markdown_status VARCHAR(20) NULL COMMENT 'processing|success|failed',
    markdown_error TEXT NULL,
    markdown_provider VARCHAR(30) NULL,
    markdown_model VARCHAR(50) NULL,
    markdown_updated_at TIMESTAMP NULL,
    char_count INT,
    extracted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (report_id) REFERENCES company_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Analyse IA (multi-fournisseurs : Anthropic par défaut, Gemini...) d'un
-- rapport : résumé, chiffres clés, risques, et lecture par rapport au
-- contexte marché récent (stock_quotes/technical_indicators).
-- Une ligne par (rapport, fournisseur, modèle, date de contexte marché) :
-- permet de ré-analyser plus tard sans perdre l'historique, tout en évitant
-- les appels redondants le même jour (voir class/ReportAnalysisService.php).
CREATE TABLE company_report_analyses (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    report_id BIGINT NOT NULL,
    company_id INT NOT NULL,
    provider VARCHAR(30) NOT NULL DEFAULT 'gemini' COMMENT 'anthropic, gemini...',
    model VARCHAR(50) NOT NULL,
    market_context_date DATE NULL COMMENT 'trading_date des cours/indicateurs utilisés comme contexte',
    summary TEXT COMMENT 'résumé exécutif court, pour affichage/listage rapide',
    details JSON NULL COMMENT 'analyse complète structurée (financials, SWOT, risques, thèse, glossaire...)',
    status VARCHAR(20) NOT NULL DEFAULT 'success' COMMENT 'success|failed',
    rating TINYINT UNSIGNED NULL COMMENT 'note 1-5 étoiles, NULL = pas noté',
    notes TEXT NULL COMMENT 'commentaire libre de l’utilisateur sur cette analyse',
    error_message TEXT NULL,
    input_char_count INT NULL,
    raw_response LONGTEXT NULL COMMENT 'réponse brute du fournisseur IA, pour audit/debug',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (report_id) REFERENCES company_reports(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,

    UNIQUE KEY uk_report_provider_model_date (report_id, provider, model, market_context_date),
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Analyse IA comparative de plusieurs rapports sur une période (une ou
-- plusieurs entreprises) : tendance financière dans le temps et/ou
-- comparaison entre entreprises. Réutilise les analyses individuelles déjà
-- faites (company_report_analyses) comme matière première plutôt que de
-- renvoyer le texte brut de chaque rapport à l'IA (voir
-- class/ReportComparisonService.php).
CREATE TABLE company_report_comparisons (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    request_hash CHAR(64) NOT NULL COMMENT 'sha256(company_ids triés + période + report_type)',
    company_ids JSON NOT NULL COMMENT 'entreprises incluses dans la comparaison',
    report_ids JSON NULL COMMENT 'rapports effectivement inclus, calculé à l’appel',
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    report_type VARCHAR(50) NULL,
    provider VARCHAR(30) NOT NULL,
    model VARCHAR(50) NOT NULL,
    computed_date DATE NOT NULL COMMENT 'date du calcul — cache "une fois par jour"',
    summary TEXT,
    details JSON NULL COMMENT 'analyse comparative complète + chart_data',
    status VARCHAR(20) NOT NULL DEFAULT 'success',
    rating TINYINT UNSIGNED NULL COMMENT 'note 1-5 étoiles, NULL = pas noté',
    notes TEXT NULL COMMENT 'commentaire libre de l’utilisateur sur cette analyse',
    error_message TEXT NULL,
    input_char_count INT NULL,
    raw_response LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_comparison (request_hash, provider, model, computed_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Analyse IA générique des graphes/tableaux de comparaison de l'app (voir
-- migrations/009_chart_analyses.sql, class/ChartAnalysisService.php) — un
-- enregistrement par (chart_type, paramètres exacts, fournisseur, modèle),
-- pour à la fois le cache (éviter de refacturer un appel IA identique) et
-- la comparaison de plusieurs fournisseurs/modèles sur la même sélection.
-- ============================================
CREATE TABLE chart_analyses (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    chart_type VARCHAR(50) NOT NULL COMMENT 'ex: quotes_comparison, quotes_signals, quotes_close_sma, market_summary, total_variation, correlation, risk_adjusted, relative_strength, sector_performance, market_breadth, data_quality_reconciliation, data_quality_price_jumps, data_quality_missing_days',
    request_hash CHAR(64) NOT NULL COMMENT 'sha256(chart_type + paramètres normalisés, y compris la sélection de lignes pour les tableaux) — détecte les requêtes identiques',
    parameters LONGTEXT NOT NULL COMMENT 'JSON des paramètres exacts (company_ids, sector, start_date, end_date, granularity, display_mode, selected_rows...)',
    provider VARCHAR(30) NOT NULL DEFAULT 'gemini',
    model VARCHAR(50) NOT NULL,
    summary TEXT NULL,
    details LONGTEXT NULL COMMENT 'JSON structuré : méthodologie expliquée, observations clés, points notables',
    status VARCHAR(20) NOT NULL DEFAULT 'success',
    rating TINYINT UNSIGNED NULL COMMENT 'note 1-5 étoiles, NULL = pas noté',
    notes TEXT NULL COMMENT 'commentaire libre de l’utilisateur sur cette analyse',
    error_message TEXT NULL,
    data_points_count INT NULL COMMENT 'taille des données envoyées, pour contexte/debug',
    raw_response LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_chart_type (chart_type),
    UNIQUE KEY uk_request_provider_model (request_hash, provider, model)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Bulletins Officiels de la Cote (BOC) : PDF quotidien récapitulant la
-- séance (tous titres, volumes, indices), scrapé depuis
-- brvm.org/fr/bulletins-officiels-de-la-cote (mêmes conventions que
-- company_reports/company_report_contents/company_report_analyses).
-- ============================================
CREATE TABLE market_bulletins (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    publish_date DATE NOT NULL COMMENT 'Déduite du préfixe boc_YYYYMMDD du nom de fichier',
    title VARCHAR(255) NOT NULL,
    file_url VARCHAR(500) NOT NULL COMMENT 'URL source sur brvm.org',
    local_path VARCHAR(500) COMMENT 'Chemin local du PDF téléchargé',
    file_size BIGINT COMMENT 'Taille du fichier en octets',
    file_hash CHAR(64) COMMENT 'SHA-256 du contenu téléchargé (détection de doublons/changements)',
    downloaded_at TIMESTAMP NULL,
    text_extracted TINYINT(1) DEFAULT 0,
    extraction_method VARCHAR(10) NULL COMMENT 'text (pdftotext) ou ocr (tesseract)',
    extraction_error TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_publish_date (publish_date),
    UNIQUE KEY uk_file_url (file_url(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE market_bulletin_contents (
    bulletin_id BIGINT PRIMARY KEY,
    extracted_text LONGTEXT,
    formatted_markdown LONGTEXT NULL COMMENT 'Texte brut restructuré en markdown (tableaux) par IA, voir class/BulletinMarkdownFormatterService.php',
    markdown_status VARCHAR(20) NULL COMMENT 'processing|success|failed',
    markdown_error TEXT NULL,
    markdown_provider VARCHAR(30) NULL,
    markdown_model VARCHAR(50) NULL,
    markdown_updated_at TIMESTAMP NULL,
    char_count INT,
    extracted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (bulletin_id) REFERENCES market_bulletins(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Analyse IA d'un bulletin : pas de dimension "market_context_date" comme
-- pour company_report_analyses — le bulletin est déjà daté, il EST le
-- contexte marché du jour (voir class/MarketBulletinAnalysisService.php).
CREATE TABLE market_bulletin_analyses (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    bulletin_id BIGINT NOT NULL,
    provider VARCHAR(30) NOT NULL DEFAULT 'gemini',
    model VARCHAR(50) NOT NULL,
    summary TEXT COMMENT 'résumé de séance court, pour affichage/listage rapide',
    details JSON NULL COMMENT 'analyse complète structurée (mouvements, secteurs, sentiment, glossaire...)',
    status VARCHAR(20) NOT NULL DEFAULT 'success' COMMENT 'success|failed',
    error_message TEXT NULL,
    input_char_count INT NULL,
    raw_response LONGTEXT NULL COMMENT 'réponse brute du fournisseur IA, pour audit/debug',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (bulletin_id) REFERENCES market_bulletins(id) ON DELETE CASCADE,

    UNIQUE KEY uk_bulletin_provider_model (bulletin_id, provider, model)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Analyse IA comparative d'un ensemble de bulletins sélectionnés (tendance
-- des indices, des volumes et de la respiration hausses/baisses sur la
-- période) — réutilise les analyses individuelles déjà faites (voir
-- class/BulletinComparisonService.php), même principe de cache "une fois
-- par jour" que company_report_comparisons.
CREATE TABLE market_bulletin_comparisons (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    request_hash CHAR(64) NOT NULL COMMENT 'sha256(bulletin_ids triés)',
    bulletin_ids JSON NOT NULL,
    provider VARCHAR(30) NOT NULL,
    model VARCHAR(50) NOT NULL,
    computed_date DATE NOT NULL COMMENT 'date du calcul — cache "une fois par jour"',
    summary TEXT,
    details JSON NULL COMMENT 'analyse comparative complète + chart_data',
    status VARCHAR(20) NOT NULL DEFAULT 'success',
    error_message TEXT NULL,
    input_char_count INT NULL,
    raw_response LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_comparison (request_hash, provider, model, computed_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Analyse IA combinée d'un ensemble mixte de rapports de sociétés ET de
-- bulletins de marché sélectionnés librement (pas limité à une seule
-- entreprise ni à une période) — corrèle les fondamentaux d'entreprise
-- (rapports) avec le contexte de marché (bulletins) sur la sélection
-- choisie. Réutilise les analyses individuelles déjà faites par
-- ReportAnalysisService et MarketBulletinAnalysisService (voir
-- class/CombinedAnalysisService.php).
CREATE TABLE combined_analyses (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    request_hash CHAR(64) NOT NULL COMMENT 'sha256(report_ids triés + bulletin_ids triés)',
    report_ids JSON NOT NULL,
    bulletin_ids JSON NOT NULL,
    provider VARCHAR(30) NOT NULL,
    model VARCHAR(50) NOT NULL,
    computed_date DATE NOT NULL COMMENT 'date du calcul — cache "une fois par jour"',
    summary TEXT,
    details JSON NULL COMMENT 'analyse combinée complète + chart_data',
    status VARCHAR(20) NOT NULL DEFAULT 'success',
    rating TINYINT UNSIGNED NULL COMMENT 'note 1-5 étoiles, NULL = pas noté',
    notes TEXT NULL COMMENT 'commentaire libre de l’utilisateur sur cette analyse',
    error_message TEXT NULL,
    input_char_count INT NULL,
    raw_response LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_comparison (request_hash, provider, model, computed_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Documents complémentaires ajoutés manuellement par entreprise (rapports
-- détaillés publiés sur le site de l'entreprise mais absents/résumés dans
-- les rapports officiels scrapés depuis brvm.org, présentations
-- investisseurs...) — même principe que company_reports (extraction de
-- texte + formatage markdown IA) mais alimenté par upload manuel, voir
-- migrations/013_company_documents.sql, class/PdfTextExtractor.php et
-- class/CompanyDocumentMarkdownFormatterService.php. Ces documents servent
-- de contexte additionnel pour les analyses IA (ChartAnalysisService,
-- ReportAnalysisService) au même titre que les rapports officiels.
-- ============================================
CREATE TABLE company_documents (
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

CREATE TABLE company_document_contents (
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

-- ============================================
-- Analyse IA structurée des documents complémentaires — même schéma que
-- company_report_analyses (exécutif, fondamentaux, SWOT, risques,
-- valorisation...), voir migrations/015_company_document_analyses.sql et
-- class/CompanyDocumentAnalysisService.php.
-- ============================================
CREATE TABLE company_document_analyses (
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
    rating TINYINT UNSIGNED NULL COMMENT 'note 1-5 étoiles, NULL = pas noté',
    notes TEXT NULL COMMENT 'commentaire libre de l’utilisateur sur cette analyse',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (document_id) REFERENCES company_documents(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,

    UNIQUE KEY uk_document_provider_model_date (document_id, provider, model, market_context_date),
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Chat bot IA du tableau de bord entreprise — conversation persistée par
-- entreprise (un tour utilisateur/assistant par ligne), voir
-- migrations/014_company_chat.sql et class/CompanyChatService.php.
-- ============================================
CREATE TABLE company_chat_messages (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    role ENUM('user', 'assistant') NOT NULL,
    content LONGTEXT NOT NULL,
    provider VARCHAR(30) NULL COMMENT 'Fournisseur IA ayant généré ce message (NULL pour un message role=user)',
    model VARCHAR(50) NULL,
    sources JSON NULL COMMENT 'Sources web citées par le fournisseur (recherche internet), tableau de {title, url}',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_company_created (company_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Authentification du panneau d'administration (Frontend/admin-web)
-- ============================================
-- Aucun compte créé via un endpoint public — voir scripts/create_admin_user.php.
CREATE TABLE admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tokens opaques (pas de JWT) vérifiés par class/AuthGuard.php via le header
-- X-Auth-Token (pas "Authorization" — non fiable sous mod_fastcgi).
CREATE TABLE admin_sessions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;