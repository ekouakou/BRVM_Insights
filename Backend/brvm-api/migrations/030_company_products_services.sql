-- Migration 030 : champ « Produits / services » par entreprise (voir
-- ANALYSE_ENTREPRISES_BRVM.md, section "Produits / services" — distinct du
-- champ `description` existant, qui sert plutôt au domaine d'activité
-- général) — éditable depuis le front admin (onglet fiche entreprise).
--
-- Pas de "USE brvm_trading_app" ici : exécute ceci sur la base déjà
-- sélectionnée (voir scripts/migrate.php).

ALTER TABLE companies
    ADD COLUMN products_services TEXT NULL
        COMMENT 'Principales lignes de produits ou services (ex. "café soluble, cubes et condiments, lait, nutrition infantile" pour Nestlé CI)'
        AFTER description;
