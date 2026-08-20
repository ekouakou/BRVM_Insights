-- Migration 031 : indicateur de saisonnalité simple par entreprise (voir
-- ANALYSE_ENTREPRISES_BRVM.md, bullets "**Saisonnière**" et "**Détail
-- saisonnier**") — distinct de `company_seasonality_calendar` (migration
-- 029), qui détaille le niveau d'activité mois par mois : ici, un simple
-- triplet oui/non/partiellement + un texte libre, tel que rédigé dans la
-- fiche de chaque entreprise, éditable depuis le front admin au même titre
-- que `products_services` (migration 030).
--
-- `description` (déjà présente, jamais utilisée jusqu'ici — 0 ligne
-- renseignée) sert désormais de champ "Domaine d'activité", conformément à
-- la section "Ce qui existe déjà et n'est pas à recréer" du document : pas
-- de nouvelle colonne pour ça.
--
-- Pas de "USE brvm_trading_app" ici : exécute ceci sur la base déjà
-- sélectionnée (voir scripts/migrate.php).

ALTER TABLE companies
    ADD COLUMN is_seasonal ENUM('non','partiellement','oui') NOT NULL DEFAULT 'non'
        COMMENT 'Activité fortement variable selon les saisons (campagnes agricoles, saison sèche/pluies, rentrée scolaire, fêtes) ?'
        AFTER products_services,
    ADD COLUMN seasonal_detail TEXT NULL
        COMMENT 'Périodes de haute/basse activité dans l''année, en texte libre (ex. "haute saison novembre-avril, basse saison mai-octobre")'
        AFTER is_seasonal;
