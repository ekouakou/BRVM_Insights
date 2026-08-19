-- Migration 028 : complète bulletin_market_stats (026/027) avec le tableau
-- « Indicateurs du marché » des Bulletins Officiels de la Cote (BOC) — 14
-- indicateurs synthétiques BRVM COMPOSITE (PER moyen, taux de rendement/
-- rentabilité, ratios de liquidité/satisfaction/tendance/couverture, taux
-- de rotation, prime de risque...), un seul niveau chacun (pas de colonne
-- de variation, contrairement aux tableaux « Statistiques du marché »
-- couverts par 026/027).
--
-- Pas de "USE brvm_trading_app" ici : exécute ceci sur la base déjà
-- sélectionnée (voir scripts/migrate.php).

ALTER TABLE bulletin_market_stats
    ADD COLUMN per_moyen_marche DECIMAL(12,4) NULL COMMENT 'PER moyen du marché (BRVM COMPOSITE, souvent calculé sans UNILEVER CI)' AFTER obligations_titles_unchanged_change_percent,
    ADD COLUMN taux_rendement_moyen DECIMAL(10,4) NULL COMMENT 'Taux de rendement moyen du marché, en %' AFTER per_moyen_marche,
    ADD COLUMN taux_rentabilite_moyen DECIMAL(10,4) NULL COMMENT 'Taux de rentabilité moyen du marché, en %' AFTER taux_rendement_moyen,
    ADD COLUMN nombre_societes_cotees INT NULL AFTER taux_rentabilite_moyen,
    ADD COLUMN nombre_lignes_obligataires INT NULL AFTER nombre_societes_cotees,
    ADD COLUMN volume_moyen_annuel_seance DECIMAL(18,2) NULL COMMENT 'Volume moyen annuel par séance, en titres' AFTER nombre_lignes_obligataires,
    ADD COLUMN valeur_moyenne_annuelle_seance DECIMAL(20,2) NULL COMMENT 'Valeur moyenne annuelle par séance, en FCFA' AFTER volume_moyen_annuel_seance,
    ADD COLUMN ratio_moyen_liquidite DECIMAL(12,4) NULL AFTER valeur_moyenne_annuelle_seance,
    ADD COLUMN ratio_moyen_satisfaction DECIMAL(12,4) NULL AFTER ratio_moyen_liquidite,
    ADD COLUMN ratio_moyen_tendance DECIMAL(12,4) NULL AFTER ratio_moyen_satisfaction,
    ADD COLUMN ratio_moyen_couverture DECIMAL(12,4) NULL AFTER ratio_moyen_tendance,
    ADD COLUMN taux_rotation_moyen DECIMAL(10,4) NULL COMMENT 'Taux de rotation moyen du marché, en %' AFTER ratio_moyen_couverture,
    ADD COLUMN prime_risque_marche DECIMAL(10,4) NULL COMMENT 'Prime de risque du marché, en %' AFTER taux_rotation_moyen,
    ADD COLUMN nombre_sgi_participantes INT NULL AFTER prime_risque_marche;
