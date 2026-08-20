-- Migration 033 : historique du prix local pour les entreprises exposées
-- (au moins partiellement) aux cours mondiaux — quand le produit/intrant
-- suit un cours international (voir company_international_pricing_history,
-- migration 032), le prix réellement payé/perçu localement (FCFA) est une
-- donnée distincte et tout aussi utile à suivre dans le temps (ex. prix
-- bord champ payé aux planteurs, prix de vente local encadré).
--
-- Pas de "USE brvm_trading_app" ici : exécute ceci sur la base déjà
-- sélectionnée (voir scripts/migrate.php).

CREATE TABLE company_local_price_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    price_date DATE NOT NULL,
    price_value DECIMAL(14,2) NOT NULL,
    unit VARCHAR(30) NOT NULL COMMENT 'Ex. FCFA/kg, FCFA/tonne, FCFA/litre',
    product_label VARCHAR(150) NULL COMMENT 'Ex. "Huile de palme brute bord champ", "Caoutchouc fond de tasse"',
    source_note VARCHAR(255) NULL,
    source_url VARCHAR(500) NULL,
    created_by_admin_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_admin_user_id) REFERENCES admin_users(id) ON DELETE SET NULL,
    INDEX idx_company_date (company_id, price_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
