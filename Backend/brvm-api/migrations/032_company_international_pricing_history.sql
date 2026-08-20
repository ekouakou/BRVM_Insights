-- Migration 032 : historique du critère « Prix fixés à l'international ? »
-- (ANALYSE_ENTREPRISES_BRVM.md) — un même produit peut basculer d'un prix
-- purement local à un prix indexé sur un cours mondial (ou l'inverse, ex.
-- fin d'une protection tarifaire régionale) ; on historise donc comme
-- company_shareholders (migration 029) plutôt que d'écraser la valeur
-- précédente avec une simple colonne sur `companies`.
--
-- Pas de "USE brvm_trading_app" ici : exécute ceci sur la base déjà
-- sélectionnée (voir scripts/migrate.php).

CREATE TABLE company_international_pricing_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    pricing_level ENUM('non','partiellement','oui') NOT NULL
        COMMENT 'Le prix de vente (ou au minimum le coût des intrants) dépend-il de cours mondiaux plutôt que d''un prix purement local/régulé ?',
    explanation TEXT NULL,
    valid_from DATE NULL COMMENT 'Date de début de validité si connue',
    valid_to DATE NULL COMMENT 'NULL = classification actuelle ; renseigné le jour où elle cesse d''être valide',
    source_note VARCHAR(255) NULL,
    created_by_admin_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_admin_user_id) REFERENCES admin_users(id) ON DELETE SET NULL,
    INDEX idx_company_current (company_id, valid_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
