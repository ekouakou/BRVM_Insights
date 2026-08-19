-- Migration 026 : volume et valeur transigés du marché des actions, extraits
-- du tableau « Statistiques du marché » de chaque Bulletin Officiel de la
-- Cote (BOC) — une ligne par bulletin (pas par entreprise ni par valeur).
--
-- Contexte : le bulletin publie un total marché quotidien (« Volume échangé
-- (Actions & Droits) » et « Valeur transigée (FCFA) (Actions & Droits) »,
-- avec leur évolution vs la séance précédente) qui n'a nulle part ailleurs
-- de représentation structurée dans l'application — utile pour un graphe
-- volumes/valeurs par séance sur une période choisie.
--
-- Extraction DÉTERMINISTE (regex sur extracted_text), pas d'IA : ce tableau
-- a un format fixe et prévisible dans tous les BOC (même principe que
-- BulletinOrderBookService pour le carnet d'ordres) — d'où *_status sur
-- market_bulletin_contents mais sans les colonnes provider/model (qui
-- n'auraient pas de sens ici, aucun fournisseur IA n'est appelé).
--
-- Pas de "USE brvm_trading_app" ici : exécute ceci sur la base déjà
-- sélectionnée (voir scripts/migrate.php).

ALTER TABLE market_bulletin_contents
    ADD COLUMN market_stats_status VARCHAR(20) NULL COMMENT 'success|error|null si jamais lancé' AFTER bond_metrics_updated_at,
    ADD COLUMN market_stats_error TEXT NULL AFTER market_stats_status;

CREATE TABLE IF NOT EXISTS bulletin_market_stats (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    bulletin_id BIGINT NOT NULL,
    publish_date DATE NOT NULL,
    actions_volume BIGINT NULL COMMENT 'Volume échangé (Actions & Droits), en titres',
    actions_volume_change_percent DECIMAL(10,4) NULL COMMENT 'Évolution du volume vs la séance précédente, en %',
    actions_value_traded DECIMAL(18,2) NULL COMMENT 'Valeur transigée (FCFA), Actions & Droits',
    actions_value_change_percent DECIMAL(10,4) NULL COMMENT 'Évolution de la valeur transigée vs la séance précédente, en %',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bulletin_id) REFERENCES market_bulletins(id) ON DELETE CASCADE,
    UNIQUE KEY uk_bulletin (bulletin_id),
    INDEX idx_date (publish_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
