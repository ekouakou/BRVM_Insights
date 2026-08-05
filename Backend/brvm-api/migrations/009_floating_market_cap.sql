-- Migration 009 : ajoute companies.floating_market_cap — la "capitalisation
-- flottante" telle que publiée par BRVM (https://www.brvm.org/fr/capitalisations/0),
-- distincte de la capitalisation globale (companies.market_cap) : exclut les
-- participations stratégiques/bloquées (État, famille fondatrice, etc.) qui
-- ne sont pas réellement disponibles à l'achat sur le marché.
--
-- Peuplée par scripts/populate_market_cap.php (colonne "Capitalisation
-- flottante" de la page, ignorée jusqu'ici).
--
-- Pas de "USE brvm_trading_app" ici : exécute ceci sur la base déjà
-- sélectionnée (le nom réel peut différer selon l'hébergeur — voir
-- scripts/migrate.php, qui applique ce fichier sans le modifier).

ALTER TABLE companies
    ADD COLUMN floating_market_cap DECIMAL(20,2) DEFAULT NULL
    COMMENT 'Capitalisation flottante (part réellement disponible au marché, hors participations stratégiques)'
    AFTER market_cap;
