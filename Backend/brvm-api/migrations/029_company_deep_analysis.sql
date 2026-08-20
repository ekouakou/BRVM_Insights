-- Migration 029 : structure de stockage pour l'analyse approfondie des 47
-- entreprises cotées documentée dans ANALYSE_ENTREPRISES_BRVM.md
-- (actionnariat, partenaires/clients, indicateurs opérationnels sectoriels,
-- calendrier saisonnier, cyclicité, notes qualitatives, ESG, calendrier de
-- gouvernance, liquidité structurelle) — voir la section « Récapitulatif :
-- où va chaque élément du document » en fin de ce fichier .md pour le détail
-- de la correspondance entre le contenu du document et chaque table.
--
-- Ne recrée pas ce qui existe déjà : chiffres financiers
-- (financial_statements/financial_statement_lines, migration 023),
-- dividendes réellement versés (market_bulletin_corporate_actions,
-- migration 012), événements de marché ponctuels (company_market_events,
-- migration 019), rapports/documents sources (company_reports,
-- company_documents).
--
-- Pas de "USE brvm_trading_app" ici : exécute ceci sur la base déjà
-- sélectionnée (voir scripts/migrate.php).

ALTER TABLE companies
    ADD COLUMN listing_status ENUM('cotee','suspendue','radiee') NOT NULL DEFAULT 'cotee'
        COMMENT 'Ex. Unilever CI suspendue pour flottant insuffisant',
    ADD COLUMN parent_group_name VARCHAR(150) NULL
        COMMENT 'Nom court du groupe/actionnaire de référence pour affichage rapide (ex. "Attijariwafa Bank"), le détail chiffré et historisé vit dans company_shareholders',
    ADD COLUMN free_float_percent DECIMAL(5,2) NULL
        COMMENT 'Dernier flottant connu, en % du capital — copie rapide du dernier snapshot de company_market_liquidity_snapshots',
    ADD COLUMN dividend_regularity ENUM('reguliere','irreguliere','suspendue','jamais_verse') NULL
        COMMENT 'Résumé rapide pour affichage liste/écran de synthèse ; le détail des leviers vit dans company_analysis_notes';

-- 1. Actionnariat, historisé (période de validité) pour capter les
-- changements d'actionnaire sans écraser l'ancienne donnée.
CREATE TABLE company_shareholders (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    shareholder_name VARCHAR(200) NOT NULL,
    shareholder_type ENUM(
        'etat', 'groupe_industriel', 'banque_institution_financiere',
        'fonds_investissement', 'flottant_public', 'salaries', 'autre'
    ) NOT NULL,
    ownership_percent DECIMAL(5,2) NULL COMMENT 'NULL si non chiffré précisément par la source',
    is_reference_shareholder TINYINT(1) NOT NULL DEFAULT 0,
    valid_from DATE NULL COMMENT 'Date de prise de participation si connue',
    valid_to DATE NULL COMMENT 'NULL = participation actuelle ; renseigné le jour où elle cesse (cession, dilution)',
    source_note VARCHAR(255) NULL COMMENT 'Ex. "Rapport annuel 2025" ou "Communiqué BRVM du 12/07/2026"',
    source_url VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_company_current (company_id, valid_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Partenaires (actionnaire technique, licence, fournisseur,
-- équipementier) ET clients (nommés ou par catégorie) : même forme, une
-- seule table avec un type plutôt que deux tables quasi identiques.
CREATE TABLE company_business_relationships (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    relationship_type ENUM(
        'actionnaire_technique', 'licence_marque', 'fournisseur_cle',
        'equipementier', 'distributeur', 'client_principal',
        'client_categorie', 'autre'
    ) NOT NULL,
    counterparty_name VARCHAR(200) NOT NULL COMMENT 'Nom de l''entreprise/entité, ou libellé de catégorie si is_named=0 (ex. "Grande distribution")',
    is_named TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = contrepartie nommée et vérifiée, 0 = catégorie générique de clients',
    rank_importance TINYINT NULL COMMENT '1 = relation la plus importante connue, 2 = suivante, etc. NULL si non classé',
    description TEXT NULL,
    since_date DATE NULL,
    until_date DATE NULL COMMENT 'NULL = relation toujours active',
    source_note VARCHAR(255) NULL,
    source_url VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_company_type (company_id, relationship_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Indicateurs opérationnels sectoriels (nombre de clients/agences d'une
-- banque, tonnage produit, abonnés mobiles, GWh distribués...) — modèle
-- générique clé/valeur, même logique que financial_statement_lines ; les
-- clés valables par secteur vivent dans un registre PHP
-- (class/OperationalMetricSchemas.php, à créer sur le modèle de
-- FinancialStatementSchemas.php).
CREATE TABLE company_operational_metrics (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    metric_key VARCHAR(60) NOT NULL COMMENT 'Ex. nombre_clients, nombre_agences, production_tonnes, abonnes_mobiles, arpu, gwh_distribues, evp_manutentionnes',
    value DECIMAL(24,4) NOT NULL,
    unit VARCHAR(20) NOT NULL COMMENT 'nombre | tonnes | GWh | m3 | FCFA | % | EVP | ...',
    period_end_date DATE NOT NULL,
    period_type ENUM('mensuel','trimestriel','semestriel','annuel') NOT NULL,
    fiscal_year SMALLINT NOT NULL,
    source_type ENUM('rapport_entreprise','communique_entreprise','source_tierce','estimation') NOT NULL,
    source_name VARCHAR(150) NULL COMMENT 'Ex. BCEAO, ARTCI, Port Autonome d''Abidjan, Conseil du Café-Cacao',
    source_url VARCHAR(500) NULL,
    source_report_id BIGINT NULL COMMENT 'Vers company_reports si applicable',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_metric (company_id, metric_key, period_end_date, period_type),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_company_key (company_id, metric_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Version chiffrée/structurée du « détail saisonnier » en texte libre du
-- document — pilote des alertes ou un calendrier visuel dans l'application.
CREATE TABLE company_seasonality_calendar (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    month TINYINT NOT NULL COMMENT '1 à 12',
    activity_level ENUM('haute','normale','basse') NOT NULL,
    note VARCHAR(255) NULL COMMENT 'Ex. "Grande traite du palmier", "Campagne cacao/café"',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    UNIQUE KEY uk_company_month (company_id, month),
    CONSTRAINT chk_month CHECK (month BETWEEN 1 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Profil de cyclicité : une ligne par entreprise, mise à jour en place
-- (la classification change rarement) plutôt qu'historisée.
CREATE TABLE company_cyclicality_profile (
    company_id INT NOT NULL PRIMARY KEY,
    cyclicality_level ENUM('non_cyclique','modere','fort') NOT NULL,
    cycle_driver VARCHAR(100) NULL COMMENT 'Ex. cours_caoutchouc, cours_huile_palme, cycle_btp, cycle_petrole',
    commodity_reference VARCHAR(100) NULL COMMENT 'Ex. SICOM Singapour, Bursa Malaysia, ICE sucre, LME cuivre, Brent',
    notes TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Analyses qualitatives structurées : perspective générale, facteurs de
-- hausse/baisse, signaux d'achat/vente, leviers et perspective de la
-- politique de rémunération — un note_type plutôt que sept tables
-- distinctes, dans la même logique générique que les tables précédentes.
CREATE TABLE company_analysis_notes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    note_type ENUM(
        'perspective_generale', 'facteur_hausse', 'facteur_baisse',
        'signal_achat', 'signal_vente',
        'levier_remuneration', 'perspective_remuneration'
    ) NOT NULL,
    content TEXT NOT NULL,
    display_order TINYINT NOT NULL DEFAULT 0 COMMENT 'Ordre d''affichage entre notes de même type',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Désactiver plutôt que supprimer une note obsolète (même logique que financial_statements.is_active)',
    created_by_admin_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_admin_user_id) REFERENCES admin_users(id) ON DELETE SET NULL,
    INDEX idx_company_type (company_id, note_type, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Durabilité, conformité, gouvernance (certifications, incidents,
-- litiges).
CREATE TABLE company_esg_records (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    record_type ENUM(
        'certification', 'incident_securite', 'litige',
        'conformite_reglementaire', 'autre'
    ) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    status VARCHAR(50) NULL COMMENT 'Ex. actif, résolu, en cours, expiré',
    event_date DATE NULL,
    source_url VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_company_type (company_id, record_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Calendrier d'entreprise : échéances passées ET à venir (AGO,
-- détachement de dividende, publications, échéances de dette,
-- renouvellement de concession/licence).
CREATE TABLE company_governance_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    event_type ENUM(
        'ago', 'detachement_dividende', 'publication_semestrielle',
        'publication_annuelle', 'echeance_dette',
        'renouvellement_licence_concession', 'autre'
    ) NOT NULL,
    event_date DATE NOT NULL,
    is_estimated TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = date projetée par récurrence (ex. AGO "généralement en avril"), 0 = date confirmée par une source',
    description TEXT NULL,
    source_url VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_company_date (company_id, event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Liquidité structurelle : photographie périodique (mensuelle/
-- trimestrielle) du flottant/part étrangère — distincte de
-- order_book_snapshots et intraday_execution_flow qui couvrent déjà la
-- liquidité au jour le jour.
CREATE TABLE company_market_liquidity_snapshots (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    snapshot_date DATE NOT NULL,
    free_float_percent DECIMAL(5,2) NULL,
    foreign_ownership_percent DECIMAL(5,2) NULL,
    avg_daily_volume_30d BIGINT NULL,
    trading_days_with_zero_volume_30d TINYINT NULL,
    is_suspended TINYINT(1) NOT NULL DEFAULT 0,
    suspension_reason VARCHAR(255) NULL COMMENT 'Ex. "Flottant sous le seuil réglementaire de 20 %" (cas Unilever CI)',
    source_note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    UNIQUE KEY uk_company_date (company_id, snapshot_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
