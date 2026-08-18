-- Réinitialisation complète de la base de données — supprime TOUTES les
-- tables, vues et la procédure stockée pour repartir d'une base vide.
-- Voir Backend/brvm-api/RESET_DATABASE.md pour la marche à suivre complète
-- (réimport du schéma, migrations restantes, recréation du compte admin).
--
-- ⚠️ Efface irrémédiablement toutes les données : cotations, entreprises,
-- rapports/bulletins téléchargés et analysés, comptes admin, tout. Pas de
-- retour en arrière possible une fois exécuté (sauf sauvegarde préalable).
--
-- Usage local (MAMP) :
--   mysql -u root -p brvm_trading_app < Backend/brvm-api/scripts/reset_database.sql
-- Usage production : coller ce contenu dans phpMyAdmin → onglet SQL.

SET FOREIGN_KEY_CHECKS = 0;

-- Sélectionne explicitement la base : nécessaire quand ce script est collé
-- tel quel dans l'onglet SQL de phpMyAdmin sans avoir cliqué sur la base
-- dans le panneau de gauche au préalable (sinon #1046 "Aucune base n'a été
-- sélectionnée"). En production, adapte le nom si ta base n'est pas
-- "brvm_trading_app" (ex. préfixe imposé par l'hébergeur cPanel).
USE brvm_trading_app;

-- Procédure stockée (ni une table ni une vue, DROP TABLE/VIEW ne la touche
-- pas — omise ici par le passé, ce qui laissait #1304 "already exists" au
-- réimport même après avoir vidé tables et vues)
DROP PROCEDURE IF EXISTS calculate_technical_indicators;

-- Vues (DROP TABLE ne fonctionne pas dessus, il faut DROP VIEW)
DROP VIEW IF EXISTS latest_quotes;
DROP VIEW IF EXISTS top_gainers;
DROP VIEW IF EXISTS top_losers;
DROP VIEW IF EXISTS volume_leaders;

-- Suivi des migrations (voir scripts/migrate.php) — inclus pour une remise à
-- zéro totale, sinon d'anciennes entrées "déjà appliquée" pourraient rester
-- même après suppression des tables qu'elles concernaient.
DROP TABLE IF EXISTS schema_migrations;

-- Tables, dans un ordre quelconque grâce à FOREIGN_KEY_CHECKS=0 ci-dessus.
-- ⚠️ Cette liste doit être complétée à CHAQUE nouvelle migration créant une
-- table : une table oubliée ici survit à la « remise à zéro » et provoque un
-- #1050 "already exists" au réimport (constaté par le passé avec la
-- procédure stockée et les vues, d'où les DROP séparés ci-dessus).
DROP TABLE IF EXISTS
    admin_sessions,
    admin_users,
    combined_analyses,
    company_chat_messages,
    company_document_analyses,
    company_document_contents,
    company_documents,
    chart_analyses,
    -- Migrations 017/018/020 : Mon Équipe BRVM (portefeuille simulé)
    portfolio_ai_reviews,
    portfolio_team_proposals,
    portfolio_thesis,
    portfolio_cash_reserve,
    portfolio_holdings,
    -- Migration 019 : journal d'informations de marché
    company_market_events,
    -- Migration 021 : annonces émetteurs BRVM
    issuer_announcement_analyses,
    issuer_announcement_contents,
    issuer_announcements,
    -- Migration 022 : carnet d'ordres & liquidité
    intraday_execution_flow,
    order_book_snapshots,
    -- Migration 024 : PER/rendement officiels BRVM par valeur
    bulletin_stock_metrics,
    -- Migration 025 : marché obligataire
    bulletin_bond_metrics,
    market_bulletin_corporate_actions,
    market_bulletin_comparisons,
    market_bulletin_analyses,
    market_bulletin_contents,
    market_bulletins,
    company_report_comparisons,
    company_report_analyses,
    company_report_contents,
    company_reports,
    price_alerts,
    technical_indicators,
    sync_logs,
    system_config,
    index_composition,
    index_values,
    market_indices,
    intraday_total_variation,
    intraday_quotes,
    stock_quotes,
    companies,
    countries,
    sectors;

SET FOREIGN_KEY_CHECKS = 1;
