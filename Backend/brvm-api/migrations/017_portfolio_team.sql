-- Migration 017 : "Mon Équipe BRVM" (métaphore 4-3-3) — voir
-- TODO_PORTFOLIO_TEAM.md pour le plan complet.
--
-- 3 nouvelles tables, toutes scopées par admin_user_id (première
-- fonctionnalité multi-tenant du projet — jusqu'ici, BRVM Insights ne
-- stockait que des données de marché publiques, jamais de notion de
-- portefeuille personnel) :
--   - portfolio_holdings : une ligne par titre "en équipe", en mode simulé
--     (target_amount_fcfa, aucun achat réel) ou réel (quantity/
--     average_purchase_price/purchase_date renseignés) — voir "Mode simulé
--     vs réel" dans le plan.
--   - portfolio_cash_reserve : le "gardien", réserve de liquidité séparée
--     des positions sur le terrain.
--   - portfolio_thesis : le "carnet du coach" (thèse d'achat + critère de
--     sortie), jamais généré par l'IA — les mots de l'utilisateur.
--
-- Écart volontaire par rapport au brouillon SQL du plan : ajout de
-- UNIQUE KEY uk_holding sur portfolio_thesis.holding_id, omise dans le
-- brouillon mais nécessaire à l'intention réelle du modèle — une seule
-- thèse par position (cible d'upsert 1:1), pas un journal illimité.

CREATE TABLE portfolio_holdings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_user_id INT NOT NULL,
    company_id INT NOT NULL,
    status ENUM('simule','achete') NOT NULL DEFAULT 'simule',
    target_amount_fcfa DECIMAL(15,2) NULL COMMENT 'Montant envisagé (mode simulé) — indicatif, pas un ordre',
    quantity DECIMAL(15,4) NULL COMMENT 'Renseigné uniquement une fois status=achete',
    average_purchase_price DECIMAL(15,2) NULL COMMENT 'Renseigné uniquement une fois status=achete',
    purchase_date DATE NULL,
    -- Rôle : NULL = calculé depuis les sous-scores à chaque affichage
    -- (par défaut) ; sinon override manuel si l'utilisateur n'est pas
    -- d'accord avec le classement automatique (ex: il connaît une raison
    -- non captée par les chiffres).
    role_override ENUM('gardien','defense','milieu','attaque') NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id),
    UNIQUE KEY uk_user_company (admin_user_id, company_id),
    INDEX idx_admin_user (admin_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Le "gardien" : réserve de liquidité, explicitement séparée des actions
-- (le fonds d'urgence n'est pas une position sur le terrain, c'est le
-- dispositif de sécurité autour).
CREATE TABLE portfolio_cash_reserve (
    admin_user_id INT PRIMARY KEY,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    currency VARCHAR(10) NOT NULL DEFAULT 'FCFA',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_user_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Le "carnet du coach" : thèse d'investissement + critère de sortie par
-- position. Volontairement en texte libre, jamais généré par l'IA à la
-- place de l'utilisateur : c'est SA thèse, pas une prédiction du système.
CREATE TABLE portfolio_thesis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    holding_id INT NOT NULL,
    buy_reason TEXT NULL COMMENT 'Pourquoi ce titre a été retenu (valable dès le mode simulé, pas seulement après achat)',
    exit_criteria TEXT NULL COMMENT 'Ce qui ferait changer d''avis',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (holding_id) REFERENCES portfolio_holdings(id) ON DELETE CASCADE,
    UNIQUE KEY uk_holding (holding_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
