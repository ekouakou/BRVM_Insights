-- Migration 016 : élargit market_indices.code (VARCHAR(20) -> VARCHAR(40)).
--
-- Nécessaire pour corriger le scraper d'indices (class/BRVMScraperFixed.php,
-- parseIndicesTable()) : la page /fr/indices contient en réalité 3 blocs
-- d'indices (principaux, sectoriels "Indices sectoriels", et Total Return
-- "Indice Total Return"), mais l'ancien code ne lisait que le premier
-- (->item(0) sur un XPath qui matchait les 3, id partagé côté BRVM). Une
-- fois les 2 blocs manquants pris en compte, plusieurs noms d'indices
-- dépassent 20 caractères une fois normalisés en code, ex:
-- "BRVM-CONSOMMATION-DISCRETIONNAIRE" (34) ou
-- "BRVM-COMPOSITE-TOTAL-RETURN" (28) — tronqués/collés à 20 caractères, ils
-- se seraient marchés dessus ou auraient perdu leur suffixe distinctif.
--
-- Pas de "USE brvm_trading_app" ici : exécute ceci sur la base déjà
-- sélectionnée (voir scripts/migrate.php).

ALTER TABLE market_indices
    MODIFY COLUMN code VARCHAR(40) NOT NULL;
