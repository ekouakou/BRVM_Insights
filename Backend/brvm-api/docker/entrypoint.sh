#!/bin/bash
#
# Point d'entrée du conteneur backend : attend que MySQL soit prêt (le
# conteneur PHP démarre souvent avant que MySQL ait fini son initialisation,
# même avec depends_on/healthcheck côté docker-compose — ceinture et
# bretelles), démarre cron en arrière-plan (synchro intrajournalière, voir
# docker/crontab et CRON_SETUP.md), puis passe la main à la commande du
# conteneur (apache2-foreground par défaut, voir Dockerfile CMD).
set -e

DB_HOST="${DB_HOST:-mysql}"
DB_PORT="${DB_PORT:-3306}"
DB_USER="${DB_USER:-root}"
DB_PASSWORD="${DB_PASSWORD:-}"

echo "[entrypoint] Attente de MySQL sur ${DB_HOST}:${DB_PORT}..."
attempt=0
until mysqladmin ping -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASSWORD" --silent >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 60 ]; then
        echo "[entrypoint] MySQL injoignable après 60 tentatives (~2min), abandon."
        exit 1
    fi
    sleep 2
done
echo "[entrypoint] MySQL prêt."

# Le fichier crontab (docker/crontab) déclare les tâches sous l'utilisateur
# www-data — cron doit tourner en root pour pouvoir changer d'utilisateur
# vers www-data au moment d'exécuter chaque tâche, comme sous un système
# classique (/etc/cron.d/*).
echo "[entrypoint] Démarrage de cron (synchro intrajournalière)..."
cron

exec "$@"
