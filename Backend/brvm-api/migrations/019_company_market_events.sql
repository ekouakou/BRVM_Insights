-- Migration 019 : journal d'informations susceptibles d'affecter le cours
-- (TODO_PENDING.md, point 27) — une ligne par événement découvert sur une
-- entreprise cotée (annonce, contrat, changement de direction, litige...),
-- saisi manuellement OU trouvé par la recherche web IA puis CONFIRMÉ par
-- l'utilisateur (jamais d'écriture automatique depuis une réponse IA non
-- relue — source_type distingue les deux origines).
--
-- Journal PARTAGÉ entre admins (base de connaissance commune par
-- entreprise), contrairement aux tables portfolio_* scopées par
-- utilisateur : une information de marché n'appartient pas à un compte.
-- impact_assessment est le jugement de l'UTILISATEUR, jamais rempli par
-- l'IA à sa place (même principe que portfolio_thesis).

CREATE TABLE company_market_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    -- Date de l'événement si connue (peut différer de la date de découverte/saisie) — NULL si non précisée par la source.
    event_date DATE NULL,
    source_type ENUM('utilisateur','ia_recherche') NOT NULL DEFAULT 'utilisateur' COMMENT 'Saisie manuelle vs trouvée par la recherche IA (puis confirmée par l''utilisateur)',
    source_url VARCHAR(500) NULL,
    impact_assessment ENUM('positif','negatif','neutre','indetermine') NULL COMMENT 'Jugement de l''utilisateur, jamais généré par l''IA — NULL si non tranché',
    created_by_admin_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_admin_user_id) REFERENCES admin_users(id) ON DELETE SET NULL,
    INDEX idx_company_date (company_id, event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
