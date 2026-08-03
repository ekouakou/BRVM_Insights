-- Migration 007 : renseigne companies.sector_id pour les 47 entreprises BRVM
-- actuellement listées. La table `sectors` existait déjà (jointe par
-- api_companies.php dans sector_id/sector_name) mais sector_id n'avait
-- jamais été rempli côté companies — tout ressortait NULL, ce qui faisait
-- échouer tout regroupement par secteur côté frontend (ex: des banques
-- listées dans "Autres entreprises" faute de secteur connu).
--
-- Classification établie à partir de l'activité réelle de chaque société
-- (nom + connaissance du marché BRVM) : établissements bancaires -> Banques,
-- télécoms -> Télécommunications, hydrocarbures/électricité/eau -> Énergie,
-- plantations/sucre/hévéa -> Agriculture, fabrication -> Industrie,
-- distribution automobile/quincaillerie -> Distribution, logistique ->
-- Transport, reste (loterie, restauration collective, édition, crédit-bail
-- auto) -> Services.

-- Pas de "USE brvm_trading_app" ici : exécute ceci sur la base déjà
-- sélectionnée (le nom réel peut différer selon l'hébergeur — voir
-- scripts/migrate.php, qui applique ce fichier sans le modifier).

UPDATE companies SET sector_id = (SELECT id FROM sectors WHERE name = 'Banques')
WHERE symbol IN ('BICB','BICC','BOAB','BOABF','BOAC','BOAM','BOAN','BOAS','CBIBF','ECOC','ETIT','NSBC','ORGT','SGBC','SIBC');

UPDATE companies SET sector_id = (SELECT id FROM sectors WHERE name = 'Distribution')
WHERE symbol IN ('BNBC','CFAC','PRSC');

UPDATE companies SET sector_id = (SELECT id FROM sectors WHERE name = 'Agriculture')
WHERE symbol IN ('PALC','SCRC','SICC','SOGC','SPHC');

UPDATE companies SET sector_id = (SELECT id FROM sectors WHERE name = 'Industrie')
WHERE symbol IN ('CABC','FTSC','NTLC','SEMC','SIVC','SLBC','SMBC','STAC','STBC','UNLC','UNXC');

UPDATE companies SET sector_id = (SELECT id FROM sectors WHERE name = 'Transport')
WHERE symbol IN ('SDSC');

UPDATE companies SET sector_id = (SELECT id FROM sectors WHERE name = 'Télécommunications')
WHERE symbol IN ('ONTBF','ORAC','SNTS');

UPDATE companies SET sector_id = (SELECT id FROM sectors WHERE name = 'Énergie')
WHERE symbol IN ('CIEC','SDCC','SHEC','TTLC','TTLS');

UPDATE companies SET sector_id = (SELECT id FROM sectors WHERE name = 'Services')
WHERE symbol IN ('ABJC','LNBB','NEIC','SAFC');
