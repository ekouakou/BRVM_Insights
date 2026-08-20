-- Migration 035 : historique du cours mondial de la matière première suivie
-- par une entreprise (ex. caoutchouc SICOM, huile de palme Bursa Malaysia,
-- Brent) — pendant symétrique de company_local_price_history (migration
-- 033) : même forme (date, valeur, unité), mais côté cours mondial plutôt
-- que prix local, pour permettre un graphe comparatif des deux séries. Voir
-- ANALYSE_ENTREPRISES_BRVM.md et company_cyclicality_profile.commodity_reference
-- (migration 029) pour le nom de la référence de marché suivie.
--
-- Pas de "USE brvm_trading_app" ici : exécute ceci sur la base déjà
-- sélectionnée (voir scripts/migrate.php).

CREATE TABLE company_world_price_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    price_date DATE NOT NULL,
    price_value DECIMAL(14,2) NOT NULL,
    unit VARCHAR(30) NOT NULL COMMENT 'Ex. USD/tonne, USD/baril, USd/lb',
    commodity_label VARCHAR(150) NULL COMMENT 'Ex. "Caoutchouc naturel (SICOM)", "Brent", "Huile de palme (Bursa Malaysia)"',
    source_note VARCHAR(255) NULL,
    source_url VARCHAR(500) NULL,
    created_by_admin_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_admin_user_id) REFERENCES admin_users(id) ON DELETE SET NULL,
    INDEX idx_company_date (company_id, price_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
