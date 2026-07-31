-- ============================================
-- BASE DE DONNÉES BRVM TRADING APP (VERSION CORRIGÉE)
-- ============================================

CREATE DATABASE IF NOT EXISTS brvm_trading_app 
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE brvm_trading_app;

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
-- TABLE: Historique intrajournalier (optionnel)
-- ============================================
CREATE TABLE intraday_quotes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    quote_datetime DATETIME NOT NULL,
    price DECIMAL(15, 2) NOT NULL,
    volume BIGINT DEFAULT 0,
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
('sync_interval_minutes', '5', 'Intervalle de synchronisation pendant les heures de marché (minutes)'),
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
    char_count INT,
    extracted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (report_id) REFERENCES company_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;