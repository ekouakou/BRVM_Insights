-- Migration 014 : chat bot IA du tableau de bord entreprise — conversation
-- persistée par entreprise, chaque tour utilisateur/assistant est une ligne
-- (même principe que company_documents : historique simple, pas de notion de
-- "conversations" multiples pour l'instant, une seule discussion continue par
-- entreprise). Voir class/CompanyChatService.php.
--
-- Pas de "USE brvm_trading_app" ici : exécute ceci sur la base déjà
-- sélectionnée (voir scripts/migrate.php).

CREATE TABLE IF NOT EXISTS company_chat_messages (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    role ENUM('user', 'assistant') NOT NULL,
    content LONGTEXT NOT NULL,
    provider VARCHAR(30) NULL COMMENT 'Fournisseur IA ayant généré ce message (NULL pour un message role=user)',
    model VARCHAR(50) NULL,
    sources JSON NULL COMMENT 'Sources web citées par le fournisseur (recherche internet), tableau de {title, url}',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_company_created (company_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
