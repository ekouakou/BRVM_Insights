-- Migration 020 : historique des propositions d'équipe "Mon Équipe BRVM"
-- (api_portfolio.php, actions propose_team / propose_team_ai — voir
-- TODO_PORTFOLIO_TEAM.md).
--
-- Chaque proposition (qu'elle vienne de l'algorithme déterministe ou de
-- l'IA) est conservée avec son contenu complet (JSON), notable par
-- étoiles (rating 1-5, même principe que chart_analyses) et supprimable.
-- origin distingue les deux sources ; provider/model ne sont renseignés
-- que pour origin='ia'. ai_commentary = raisonnement global de l'IA
-- (NULL pour l'algorithme, dont les justifications par joueur sont déjà
-- dans le JSON de la proposition).
--
-- Scopé par admin_user_id comme les autres tables portfolio_*.

CREATE TABLE portfolio_team_proposals (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    admin_user_id INT NOT NULL,
    origin ENUM('algorithme','ia') NOT NULL,
    provider VARCHAR(30) NULL,
    model VARCHAR(50) NULL,
    profile VARCHAR(20) NOT NULL,
    budget_fcfa DECIMAL(15,2) NULL,
    proposal JSON NOT NULL COMMENT 'Contenu complet de la proposition (team/bench/réserve/notes) tel que renvoyé au frontend',
    ai_commentary TEXT NULL COMMENT 'Raisonnement global de l''IA (origin=ia uniquement)',
    rating TINYINT NULL COMMENT 'Note utilisateur 1-5 étoiles',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    INDEX idx_user_created (admin_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
