-- Migration 027 : complète bulletin_market_stats (026) avec les indicateurs
-- manquants du tableau « Statistiques du marché » des Bulletins Officiels de
-- la Cote (BOC) — capitalisation boursière et compteurs de titres
-- (transigés/en hausse/en baisse/inchangés), pour Actions ET Obligations.
-- 026 n'avait extrait que volume échangé et valeur transigée, Actions
-- uniquement.
--
-- Mêmes conventions que 026 : niveaux BIGINT pour les compteurs de titres,
-- DECIMAL(18,2) pour les montants FCFA, DECIMAL(10,4) pour les variations en
-- % — extraction déterministe (regex), pas de colonnes provider/model.
--
-- Pas de "USE brvm_trading_app" ici : exécute ceci sur la base déjà
-- sélectionnée (voir scripts/migrate.php).

ALTER TABLE bulletin_market_stats
    ADD COLUMN actions_capitalization DECIMAL(18,2) NULL COMMENT 'Capitalisation boursière (FCFA), Actions & Droits' AFTER actions_value_change_percent,
    ADD COLUMN actions_capitalization_change_percent DECIMAL(10,4) NULL AFTER actions_capitalization,
    ADD COLUMN actions_titles_traded BIGINT NULL COMMENT 'Nombre de titres transigés (Actions)' AFTER actions_capitalization_change_percent,
    ADD COLUMN actions_titles_traded_change_percent DECIMAL(10,4) NULL AFTER actions_titles_traded,
    ADD COLUMN actions_titles_up BIGINT NULL COMMENT 'Nombre de titres en hausse (Actions)' AFTER actions_titles_traded_change_percent,
    ADD COLUMN actions_titles_up_change_percent DECIMAL(10,4) NULL AFTER actions_titles_up,
    ADD COLUMN actions_titles_down BIGINT NULL COMMENT 'Nombre de titres en baisse (Actions)' AFTER actions_titles_up_change_percent,
    ADD COLUMN actions_titles_down_change_percent DECIMAL(10,4) NULL AFTER actions_titles_down,
    ADD COLUMN actions_titles_unchanged BIGINT NULL COMMENT 'Nombre de titres inchangés (Actions)' AFTER actions_titles_down_change_percent,
    ADD COLUMN actions_titles_unchanged_change_percent DECIMAL(10,4) NULL AFTER actions_titles_unchanged,

    ADD COLUMN obligations_capitalization DECIMAL(18,2) NULL COMMENT 'Capitalisation boursière (FCFA), Obligations' AFTER actions_titles_unchanged_change_percent,
    ADD COLUMN obligations_capitalization_change_percent DECIMAL(10,4) NULL AFTER obligations_capitalization,
    ADD COLUMN obligations_volume BIGINT NULL COMMENT 'Volume échangé (Obligations), en titres' AFTER obligations_capitalization_change_percent,
    ADD COLUMN obligations_volume_change_percent DECIMAL(10,4) NULL AFTER obligations_volume,
    ADD COLUMN obligations_value_traded DECIMAL(18,2) NULL COMMENT 'Valeur transigée (FCFA), Obligations' AFTER obligations_volume_change_percent,
    ADD COLUMN obligations_value_change_percent DECIMAL(10,4) NULL AFTER obligations_value_traded,
    ADD COLUMN obligations_titles_traded BIGINT NULL COMMENT 'Nombre de titres transigés (Obligations)' AFTER obligations_value_change_percent,
    ADD COLUMN obligations_titles_traded_change_percent DECIMAL(10,4) NULL AFTER obligations_titles_traded,
    ADD COLUMN obligations_titles_up BIGINT NULL COMMENT 'Nombre de titres en hausse (Obligations)' AFTER obligations_titles_traded_change_percent,
    ADD COLUMN obligations_titles_up_change_percent DECIMAL(10,4) NULL AFTER obligations_titles_up,
    ADD COLUMN obligations_titles_down BIGINT NULL COMMENT 'Nombre de titres en baisse (Obligations)' AFTER obligations_titles_up_change_percent,
    ADD COLUMN obligations_titles_down_change_percent DECIMAL(10,4) NULL AFTER obligations_titles_down,
    ADD COLUMN obligations_titles_unchanged BIGINT NULL COMMENT 'Nombre de titres inchangés (Obligations)' AFTER obligations_titles_down_change_percent,
    ADD COLUMN obligations_titles_unchanged_change_percent DECIMAL(10,4) NULL AFTER obligations_titles_unchanged;
