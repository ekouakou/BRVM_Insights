-- Migration 025 : marché obligataire, extrait du tableau des obligations de
-- chaque Bulletin Officiel de la Cote (BOC).
--
-- Contexte : jusqu'ici l'application ne couvre que les actions (cotations,
-- PER/rendement officiel — voir migration 024) ; le marché obligataire (plus
-- de 200 lignes obligataires à la BRVM : obligations souveraines,
-- d'institutions financières, d'entreprises, vertes/sociales/durables (GSS),
-- Fonds Communs de Titrisation de Créances (FCTC), Sukuk, convertibles) n'a
-- aucun modèle de données. Chaque BOC publie pourtant, par ligne
-- obligataire, son cours, son coupon couru, sa périodicité et son échéance —
-- cette migration capture cette donnée pour construire un historique
-- interrogeable par titre obligataire.
--
-- Même pattern que les migrations 012/024 : *_status sur
-- market_bulletin_contents pour savoir si l'extraction a déjà tourné sur ce
-- bulletin, une table dédiée pour les lignes extraites.
--
-- Pas de rattachement à `companies` : contrairement aux actions, la plupart
-- des émetteurs obligataires sont des États (Mali, Sénégal, Burkina...) ou
-- des fonds de titrisation (FCTC) sans correspondance dans cette table —
-- même les obligations d'entreprises réellement cotées (ex: SNTS.O2 =
-- Sonatel) resteraient l'exception plutôt que la règle ; le symbole et le
-- titre tels qu'imprimés dans le bulletin suffisent à identifier une ligne
-- obligataire dans le temps (voir uk_bulletin_symbol).
--
-- Pas de "USE brvm_trading_app" ici : exécute ceci sur la base déjà
-- sélectionnée (voir scripts/migrate.php).

ALTER TABLE market_bulletin_contents
    ADD COLUMN bond_metrics_status VARCHAR(20) NULL COMMENT 'success|error|null si jamais lancé' AFTER stock_metrics_updated_at,
    ADD COLUMN bond_metrics_error TEXT NULL AFTER bond_metrics_status,
    ADD COLUMN bond_metrics_provider VARCHAR(30) NULL AFTER bond_metrics_error,
    ADD COLUMN bond_metrics_model VARCHAR(50) NULL AFTER bond_metrics_provider,
    ADD COLUMN bond_metrics_updated_at TIMESTAMP NULL AFTER bond_metrics_model;

CREATE TABLE IF NOT EXISTS bulletin_bond_metrics (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    bulletin_id BIGINT NOT NULL,
    publish_date DATE NOT NULL COMMENT 'Dupliqué de market_bulletins.publish_date pour une recherche "au plus proche d''une date" sans jointure',
    symbol VARCHAR(30) NOT NULL COMMENT 'Code tel qu''écrit dans le bulletin (ex: TPCI.O87, FCTC SONATEL C-1, SUKTG.S1)',
    title VARCHAR(255) NULL COMMENT 'Nom de la ligne obligataire tel qu''écrit dans le bulletin',
    category VARCHAR(30) NOT NULL COMMENT 'sovereign|financial_institution|corporate|gss_financial|gss_corporate|fctc_public|fctc_financial|fctc_corporate|fctc_gss_corporate|sukuk|convertible',
    nominal_value DECIMAL(15,4) NULL COMMENT 'Valeur nominale',
    previous_price DECIMAL(15,4) NULL COMMENT 'Cours précédent',
    day_price DECIMAL(15,4) NULL COMMENT 'Cours du jour — NULL si non coté (NC) ou suspendu (SP) cette séance, voir day_price_status',
    day_price_status VARCHAR(5) NULL COMMENT 'NC (non coté) | SP (suspendu) | NULL si un cours du jour existe',
    reference_price DECIMAL(15,4) NULL COMMENT 'Cours de référence retenu pour la séance',
    volume INT NULL,
    value_traded DECIMAL(18,2) NULL COMMENT 'Valeur transigée (FCFA)',
    accrued_coupon DECIMAL(12,4) NULL COMMENT 'Coupon couru',
    period_type VARCHAR(5) NULL COMMENT 'A=annuel, S=semestriel, T=trimestriel',
    net_amount DECIMAL(12,4) NULL COMMENT 'Montant net du prochain coupon',
    maturity_date DATE NULL COMMENT 'Échéance du prochain coupon',
    amortization_type VARCHAR(10) NULL COMMENT 'Type d''amortissement tel qu''imprimé (ACD, AC, IF...)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bulletin_id) REFERENCES market_bulletins(id) ON DELETE CASCADE,
    UNIQUE KEY uk_bulletin_symbol (bulletin_id, symbol),
    INDEX idx_symbol_date (symbol, publish_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
