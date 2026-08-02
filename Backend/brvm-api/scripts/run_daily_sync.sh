#!/bin/bash
#
# Synchronisation quotidienne BRVM — pensé pour être lancé par launchd sur une
# machine "désignée" qui n'est pas forcément allumée pendant les heures de
# marché. Démarre MAMP si besoin (MySQL ne tourne pas tout seul par défaut),
# puis lance la synchro PHP (cotations + indices + indicateurs techniques).
#
# cron_sync_brvm.php refuse tout seul de tourner le week-end ou avant
# l'ouverture du marché (voir isMarketOpen()) — ce script n'a pas besoin de
# revérifier ces conditions, juste de s'assurer que MySQL est disponible.
#
# PHP_BIN et MYSQL_PORT dépendent de l'installation MAMP locale (version PHP
# choisie, port MySQL) et diffèrent typiquement d'une machine de dev à
# l'autre. Les valeurs ci-dessous sont des valeurs par défaut ; pour les
# adapter sans les modifier ici (et éviter les conflits git entre machines),
# crée un fichier "run_daily_sync.local.sh" à côté de ce script (voir
# run_daily_sync.local.sh.example) — il est ignoré par git et prioritaire.

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="/Applications/MAMP/bin/php/php8.3.30/bin/php"
MYSQL_HOST="127.0.0.1"
MYSQL_PORT="8889"

LOCAL_CONFIG="$(dirname "${BASH_SOURCE[0]}")/run_daily_sync.local.sh"
if [ -f "$LOCAL_CONFIG" ]; then
    source "$LOCAL_CONFIG"
fi

LOG_FILE="$PROJECT_DIR/logs/launchd_sync.log"

mkdir -p "$PROJECT_DIR/logs"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >> "$LOG_FILE"
}

log "=== Démarrage run_daily_sync.sh ==="

if ! nc -z -w2 "$MYSQL_HOST" "$MYSQL_PORT" 2>/dev/null; then
    log "MySQL injoignable sur $MYSQL_HOST:$MYSQL_PORT, démarrage de MAMP..."
    open -a MAMP

    for i in $(seq 1 30); do
        sleep 2
        if nc -z -w2 "$MYSQL_HOST" "$MYSQL_PORT" 2>/dev/null; then
            log "MySQL prêt après $((i * 2))s"
            break
        fi
    done
fi

if ! nc -z -w2 "$MYSQL_HOST" "$MYSQL_PORT" 2>/dev/null; then
    log "ERREUR: MySQL toujours injoignable après 60s, abandon"
    exit 1
fi

"$PHP_BIN" "$PROJECT_DIR/cron_sync_brvm.php" >> "$LOG_FILE" 2>&1
EXIT_CODE=$?

log "=== Fin run_daily_sync.sh (code $EXIT_CODE) ==="
exit $EXIT_CODE
