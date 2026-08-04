-- Migration 008 : ajoute intraday_total_variation, l'accumulateur de
-- "variation totale" (churn/volatilité intrajournalière) par entreprise et
-- par jour — voir TODO_ANALYSES.md point 8 pour la définition complète de
-- la métrique et l'architecture.
--
-- Alimentée en continu par class/BRVMSyncService.php::recordIntradaySnapshot()
-- à chaque synchro (~10 min en heures de marché) : à chaque nouveau relevé,
-- on ajoute la variation absolue depuis le relevé précédent du même jour à
-- total_gain_percent ou total_loss_percent selon le sens du mouvement.
--
-- Pas de "USE brvm_trading_app" ici : exécute ceci sur la base déjà
-- sélectionnée (le nom réel peut différer selon l'hébergeur — voir
-- scripts/migrate.php, qui applique ce fichier sans le modifier).

CREATE TABLE IF NOT EXISTS intraday_total_variation (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    trading_date DATE NOT NULL,
    total_gain_percent DECIMAL(10, 4) NOT NULL DEFAULT 0 COMMENT 'Somme des hausses entre relevés successifs du jour',
    total_loss_percent DECIMAL(10, 4) NOT NULL DEFAULT 0 COMMENT 'Somme des baisses (valeur absolue) entre relevés successifs du jour',
    total_variation_percent DECIMAL(10, 4) NOT NULL DEFAULT 0 COMMENT 'total_gain_percent + total_loss_percent',
    snapshots_count INT NOT NULL DEFAULT 0 COMMENT 'Nombre de relevés intrajournaliers pris en compte ce jour',
    last_price DECIMAL(15, 2) NULL COMMENT 'Dernier prix vu ce jour, pour calculer le prochain delta',
    last_quote_datetime DATETIME NULL,
    UNIQUE KEY uniq_company_day (company_id, trading_date),
    CONSTRAINT fk_itv_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
