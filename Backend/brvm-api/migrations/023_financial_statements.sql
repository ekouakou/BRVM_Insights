-- Migration 023 : états financiers saisis manuellement, tous formats
--
-- Complète l'extraction IA des rapports par une saisie DIRECTE et fiable :
-- l'IA se trompe régulièrement d'unité (11 405 au lieu de 11 405 000 000 sur
-- un document libellé « en millions ») et ne restitue que quelques agrégats.
--
-- MODÈLE GÉNÉRIQUE plutôt que des colonnes figées : les émetteurs de la BRVM
-- publient des états de structures très différentes — compte de résultat
-- commercial SYSCOHADA, compte de résultat BANCAIRE (produit net bancaire,
-- coût du risque), bilan bancaire, tableau des flux de trésorerie, tableau
-- d'activité trimestriel simplifié. Des colonnes fixes obligeraient à une
-- migration à chaque nouveau format rencontré ; ici un format = une entrée
-- dans le registre PHP (class/FinancialStatementSchemas.php), sans toucher
-- à la base.
--
-- CONVENTIONS DE SIGNE : elles DIFFÈRENT d'un format à l'autre et ne peuvent
-- donc pas être imposées par le schéma. En SYSCOHADA commercial les charges
-- sont négatives (les sous-totaux sont de simples sommes) ; dans le compte
-- de résultat bancaire elles sont positives et soustraites par la formule.
-- Chaque poste porte donc son signe attendu dans le registre PHP, et les
-- formules de sous-totaux y sont déclarées avec leurs coefficients — les
-- quatre formats ont été vérifiés au chiffre près sur des états publiés.
--
-- Aucun sous-total n'est stocké : tous sont recalculés à la lecture, ce qui
-- rend impossible toute divergence entre un total figé et des postes
-- modifiés ensuite.

CREATE TABLE financial_statements (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,

    statement_type VARCHAR(40) NOT NULL
        COMMENT 'Clé du registre FinancialStatementSchemas::TYPES (syscohada_resultat, bancaire_resultat, bancaire_bilan, flux_tresorerie, activite_simplifie)',

    period_end_date DATE NOT NULL COMMENT 'Date de clôture de la période (ex. 2023-12-31)',
    period_type VARCHAR(20) NOT NULL DEFAULT 'annuel' COMMENT 'annuel | semestriel | trimestriel',
    fiscal_year SMALLINT NOT NULL COMMENT 'Exercice de rattachement, pour les comparaisons N / N-1',
    currency VARCHAR(10) NOT NULL DEFAULT 'FCFA',
    unit_multiplier BIGINT NOT NULL DEFAULT 1
        COMMENT '1 = francs saisis tels quels | 1000 = milliers | 1000000 = millions. La valeur est stockée TELLE QUE SAISIE et convertie à la lecture : sans cela, impossible de savoir plus tard si « 11 405 » valait onze mille ou onze milliards.',

    -- Désactivation plutôt que suppression : un état erroné ou remplacé par
    -- une version certifiée doit pouvoir disparaître des graphes SANS perdre
    -- la trace de ce qui avait été saisi ni casser les comparaisons passées.
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    deactivated_reason VARCHAR(255) NULL,

    source_report_id BIGINT NULL COMMENT 'Rapport dont ces chiffres sont issus, si applicable',
    source_note VARCHAR(255) NULL COMMENT 'Ex. « États financiers certifiés 2023, page 12 »',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_etat (company_id, statement_type, period_end_date, period_type),
    KEY idx_company_type (company_id, statement_type, is_active),
    KEY idx_source_report (source_report_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un poste saisi. `line_key` correspond à une ligne déclarée dans le
-- registre PHP du format concerné ; une valeur absente signifie « non
-- renseigné » et non « zéro » — la distinction compte pour les sous-totaux
-- et pour signaler une saisie incomplète.
CREATE TABLE financial_statement_lines (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    statement_id BIGINT NOT NULL,
    line_key VARCHAR(60) NOT NULL,
    value DECIMAL(24,4) NOT NULL,

    UNIQUE KEY uk_ligne (statement_id, line_key),
    KEY idx_statement (statement_id),
    CONSTRAINT fk_statement_lines FOREIGN KEY (statement_id)
        REFERENCES financial_statements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
