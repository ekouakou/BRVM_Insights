-- Migration 036 : indicateur manuel « entreprise de trading » — un
-- jugement de l'admin (pas une donnée extraite d'ANALYSE_ENTREPRISES_BRVM.md)
-- pour marquer les entreprises qu'il classe comme adaptées au trading actif
-- (ex. suivre le cycle des cours mondiaux — voir company_cyclicality_profile,
-- migration 029 — plutôt qu'à la détention longue), distinct de la
-- cyclicité elle-même qui est une lecture du document, pas un choix
-- personnel.
--
-- Pas de "USE brvm_trading_app" ici : exécute ceci sur la base déjà
-- sélectionnée (voir scripts/migrate.php).

ALTER TABLE companies
    ADD COLUMN is_trading_company TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Jugement de l''admin : entreprise classée comme adaptée au trading actif (vs détention longue)'
        AFTER seasonal_detail,
    ADD COLUMN trading_classification_note TEXT NULL
        COMMENT 'Raisonnement de l''admin derrière ce classement (optionnel)'
        AFTER is_trading_company;
