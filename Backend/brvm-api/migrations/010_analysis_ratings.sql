-- Migration 010 : notation (étoiles 1-5) et commentaire libre sur chaque
-- analyse IA enregistrée, pour les 4 tables d'analyse du projet — permet à
-- l'utilisateur de noter la qualité d'une analyse passée (utile pour
-- repérer les bonnes réponses d'un fournisseur/modèle en particulier dans
-- l'historique, voir TODO_CHART_AI_ANALYSIS.md).
--
-- rating : NULL = pas encore noté, 1-5 = nombre d'étoiles. Contrainte
-- applicative (pas de CHECK ici pour rester compatible MySQL 5.x/MariaDB
-- anciennes versions, où CHECK est parfois ignoré silencieusement) — la
-- validation 1-5 se fait côté service PHP avant l'écriture.
--
-- Pas de "USE brvm_trading_app" ici : exécute ceci sur la base déjà
-- sélectionnée (voir scripts/migrate.php).

ALTER TABLE chart_analyses
    ADD COLUMN rating TINYINT UNSIGNED NULL COMMENT 'note 1-5 étoiles, NULL = pas noté' AFTER status,
    ADD COLUMN notes TEXT NULL COMMENT 'commentaire libre de l’utilisateur sur cette analyse' AFTER rating;

ALTER TABLE company_report_analyses
    ADD COLUMN rating TINYINT UNSIGNED NULL COMMENT 'note 1-5 étoiles, NULL = pas noté' AFTER status,
    ADD COLUMN notes TEXT NULL COMMENT 'commentaire libre de l’utilisateur sur cette analyse' AFTER rating;

ALTER TABLE company_report_comparisons
    ADD COLUMN rating TINYINT UNSIGNED NULL COMMENT 'note 1-5 étoiles, NULL = pas noté' AFTER status,
    ADD COLUMN notes TEXT NULL COMMENT 'commentaire libre de l’utilisateur sur cette analyse' AFTER rating;

ALTER TABLE combined_analyses
    ADD COLUMN rating TINYINT UNSIGNED NULL COMMENT 'note 1-5 étoiles, NULL = pas noté' AFTER status,
    ADD COLUMN notes TEXT NULL COMMENT 'commentaire libre de l’utilisateur sur cette analyse' AFTER rating;
