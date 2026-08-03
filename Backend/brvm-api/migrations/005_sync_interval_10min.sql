-- Migration 005 : passe l'intervalle de synchro affiché/documenté de 15 (ou 5,
-- valeur historique) à 10 minutes. N'affecte pas le comportement réel du
-- cron (contrôlé par docker/crontab ou launchd, pas par cette table), mais
-- garde la valeur informative de system_config cohérente avec le déclencheur
-- effectif.

USE brvm_trading_app;

INSERT INTO system_config (config_key, config_value, description)
VALUES ('sync_interval_minutes', '10', 'Intervalle de synchronisation pendant les heures de marché (minutes)')
ON DUPLICATE KEY UPDATE config_value = '10';
