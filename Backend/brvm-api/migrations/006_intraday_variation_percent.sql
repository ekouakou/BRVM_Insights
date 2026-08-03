-- Migration 006 : ajoute variation_percent à intraday_quotes (présent dans
-- BD.sql depuis l'ajout de BRVMSyncService::recordIntradaySnapshot(), mais
-- jamais migré sur les bases déjà provisionnées avant cet ajout).

-- Pas de "USE brvm_trading_app" ici : exécute ceci sur la base déjà
-- sélectionnée (le nom réel peut différer selon l'hébergeur — voir
-- scripts/migrate.php, qui applique ce fichier sans le modifier).

ALTER TABLE intraday_quotes
    ADD COLUMN variation_percent DECIMAL(10, 4) NULL COMMENT 'Variation en % depuis la clôture précédente'
        AFTER volume;
