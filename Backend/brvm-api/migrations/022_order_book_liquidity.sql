-- Migration 022 : moteur de liquidité & dynamique du carnet d'ordres
-- (voir TODO_CARNET_ORDRES.md). Deux tables de données DÉRIVÉES — 100 %
-- reconstructibles depuis market_bulletin_contents (parse_bulletins) et
-- intraday_quotes (backfill_execution_flow.php) : aucune donnée à
-- transporter entre environnements, rejouer les backfills suffit.
--
-- order_book_snapshots : une photographie du carnet (meilleures limites +
-- quantités résiduelles) par entreprise et par instant. Aujourd'hui
-- quotidienne (table « Quantité résiduelle » du Bulletin Officiel de la
-- Cote, fin de séance) ; le DATETIME + la colonne source permettent
-- d'accueillir plus tard des snapshots intraday (flux SGI) sans migration.
-- Prix NULL avec quantité renseignée = ordres « au marché » (sans limite,
-- affichés "Marché" dans le BOC). Les deux côtés NULL = carnet vide
-- (valeur réservée/suspendue) — ligne conservée, l'absence d'offre est une
-- information de liquidité.

CREATE TABLE order_book_snapshots (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    snapshot_datetime DATETIME NOT NULL COMMENT 'BOC : publish_date du bulletin à 14:30 (fin de séance)',
    source VARCHAR(20) NOT NULL DEFAULT 'bulletin_boc' COMMENT 'bulletin_boc | futurs : sgi_feed, manual',
    bulletin_id BIGINT NULL COMMENT 'Traçabilité vers market_bulletins pour source=bulletin_boc',
    best_bid_price DECIMAL(15,2) NULL COMMENT 'Meilleure limite à l''achat — NULL si côté vide ou ordres au marché',
    best_ask_price DECIMAL(15,2) NULL COMMENT 'Meilleure limite à la vente — NULL si côté vide ou ordres au marché',
    bid_at_market TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = ordres d''achat « au marché » (quantité sans prix limite)',
    ask_at_market TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = ordres de vente « au marché »',
    bid_residual_qty BIGINT NULL COMMENT 'Quantité résiduelle à l''achat (à la meilleure limite) — NULL si côté vide',
    ask_residual_qty BIGINT NULL COMMENT 'Quantité résiduelle à la vente — NULL si côté vide',
    reference_price DECIMAL(15,2) NULL COMMENT 'Cours de référence publié dans la même table du BOC',
    spread_abs DECIMAL(15,2) NULL COMMENT 'ask - bid ; NULL si un des deux prix manque',
    spread_percent DECIMAL(10,4) NULL COMMENT 'spread_abs / prix milieu × 100',
    imbalance_ratio DECIMAL(10,4) NULL COMMENT 'bid_qty / (bid_qty + ask_qty) ; NULL si les deux quantités manquent',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_snapshot (company_id, snapshot_datetime, source),
    KEY idx_company_date (company_id, snapshot_datetime),
    KEY idx_bulletin (bulletin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- intraday_execution_flow : un enregistrement par intervalle entre deux
-- relevés intraday consécutifs d'une même séance, avec le volume exécuté
-- (delta du volume cumulé affiché par brvm.org) pré-calculé. Pièges
-- neutralisés par ExecutionFlowBuilder : relevés d'avant ~09h10 qui
-- affichent encore la séance de la VEILLE (la séance commence au premier
-- reset du cumul), deltas négatifs (correction du site — intervalle
-- ignoré), écart entre dernier cumul et volume officiel de stock_quotes
-- (fixing de clôture → intervalle synthétique is_closing_auction=1).
-- pressure_side est une ESTIMATION (tick rule : prix monte = initiative
-- acheteuse probable) — jamais présentée comme un fait, voir
-- TODO_CARNET_ORDRES.md §2.

CREATE TABLE intraday_execution_flow (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    trading_date DATE NOT NULL,
    interval_start DATETIME NOT NULL COMMENT 'Horodatage du relevé précédent',
    interval_end DATETIME NOT NULL COMMENT 'Horodatage du relevé courant',
    price_start DECIMAL(15,2) NULL,
    price_end DECIMAL(15,2) NULL,
    executed_volume BIGINT NOT NULL COMMENT 'Delta du volume cumulé sur l''intervalle (toujours >= 0)',
    executed_value DECIMAL(20,2) NULL COMMENT 'executed_volume × prix moyen de l''intervalle',
    price_direction TINYINT NOT NULL DEFAULT 0 COMMENT 'Signe de price_end - price_start : -1, 0, +1',
    pressure_side VARCHAR(10) NULL COMMENT 'ESTIMATION tick rule : achat | vente | NULL (prix inchangé ou volume nul)',
    is_closing_auction TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = intervalle synthétique portant l''écart avec le volume officiel du jour',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_interval (company_id, interval_end),
    KEY idx_company_date (company_id, trading_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Statut d'extraction du carnet par bulletin — même pattern que
-- corporate_actions_status (relançable, erreurs visibles côté frontend).
ALTER TABLE market_bulletin_contents
    ADD COLUMN order_book_status VARCHAR(20) NULL COMMENT 'NULL=jamais tenté | processing | success | failed',
    ADD COLUMN order_book_error TEXT NULL;
