-- Migration 018 : historique des avis du coach IA sur "Mon Équipe BRVM"
-- (api_portfolio.php, action ai_review — voir TODO_PORTFOLIO_TEAM.md).
--
-- team_snapshot conserve la composition COMPLÈTE de l'équipe au moment de
-- l'avis (positions, lignes, alertes, score d'équilibre) : un avis relu
-- plus tard n'est interprétable qu'avec ce contexte — l'équipe a pu
-- changer depuis, notamment via l'application des propositions de l'avis
-- lui-même. Les propositions historisées sont en LECTURE SEULE côté
-- frontend (jamais ré-applicables : elles portaient sur une composition
-- révolue).
--
-- Scopé par admin_user_id comme les autres tables portfolio_* (migration
-- 017) — même exigence d'isolation multi-tenant.

CREATE TABLE portfolio_ai_reviews (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    admin_user_id INT NOT NULL,
    provider VARCHAR(30) NOT NULL,
    model VARCHAR(50) NOT NULL,
    team_snapshot JSON NOT NULL COMMENT 'Composition complète de l''équipe au moment de l''avis (contexte indispensable à la relecture)',
    overall_opinion TEXT,
    strengths JSON NULL,
    weaknesses JSON NULL,
    proposals JSON NULL COMMENT 'Propositions déjà validées côté serveur au moment de l''avis',
    dropped_proposals_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    INDEX idx_user_created (admin_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
