#!/bin/bash
#
# Déploiement (initial ou mise à jour) du backend BRVM Insights sur un
# hébergement mutualisé cPanel, via SSH. Alternative scriptée à
# DEPLOY_CPANEL.md (qui reste valable si SSH n'est pas disponible).
#
# À exécuter SUR LE SERVEUR (après connexion SSH), pas depuis ta machine :
#   ssh -i ~/.ssh/brvm_hosting -p <PORT> brimmobi@<HOSTNAME>
#   git clone https://github.com/ekouakou/BRVM_Insights.git ~/brvm_insights_src
#   bash ~/brvm_insights_src/Backend/brvm-api/scripts/deploy_cpanel_ssh.sh
#
# Idempotent : relançable pour mettre à jour (git pull + resynchro des
# fichiers) sans toucher storage/logs/.env déjà en place sur le serveur.
set -euo pipefail

# --- Configuration : ajuste ces 3 valeurs si besoin -------------------------
REPO_URL="https://github.com/ekouakou/BRVM_Insights.git"
SRC_DIR="$HOME/brvm_insights_src"                 # clone git (contient tout le monorepo)
APP_DIR="$HOME/public_html/brvmapi"                   # racine web du backend (voir Étape 3 de DEPLOY_CPANEL.md)
# -----------------------------------------------------------------------------

log() { echo "[deploy] $*"; }

log "=== 1/6 — Récupération du code (git) ==="
log "Note : le dépôt contient ~600 Mo de PDF déjà collectés (storage/reports,"
log "storage/bulletins) — le clone initial sera volumineux même si ce script ne"
log "les copie PAS vers $APP_DIR (voir étape 2). Si ton quota disque est serré,"
log "tu peux supprimer \$SRC_DIR ($SRC_DIR) une fois le déploiement terminé ; une"
log "mise à jour ultérieure re-clonera simplement (plus lent, mais sans risque)."
if [ -d "$SRC_DIR/.git" ]; then
    git -C "$SRC_DIR" pull --ff-only
else
    git clone --depth 1 "$REPO_URL" "$SRC_DIR"
fi

log "=== 2/6 — Synchronisation vers $APP_DIR ==="
mkdir -p "$APP_DIR"
# storage/logs/cache/uploads/temp/locks : gérés uniquement côté serveur (PDF
# téléchargés au runtime, jamais depuis le dépôt) — exclus de la copie ET de
# la suppression, sinon --delete effacerait les PDF déjà téléchargés en prod
# à chaque redéploiement. .env : secrets, jamais dans le dépôt. Dockerfile/
# docker/ : sans objet hors conteneur.
rsync -a --delete \
    --exclude ".git" \
    --exclude ".env" \
    --exclude "Dockerfile" \
    --exclude ".dockerignore" \
    --exclude "docker/" \
    --exclude "storage/" \
    --exclude "logs/" \
    --exclude "cache/" \
    --exclude "uploads/" \
    --exclude "temp/" \
    --exclude "locks/" \
    "$SRC_DIR/Backend/brvm-api/" "$APP_DIR/"

log "=== 3/6 — Répertoires inscriptibles ==="
mkdir -p "$APP_DIR"/{storage/reports,storage/bulletins,logs,cache,uploads,temp,locks}
chmod -R 755 "$APP_DIR"/{storage,logs,cache,uploads,temp,locks}

log "=== 4/6 — Base de données ==="
if [ -f "$APP_DIR/.env" ]; then
    log ".env déjà présent, base de données déjà configurée (pas d'action) — voir Étape 4 pour la modifier."
elif command -v uapi >/dev/null 2>&1; then
    log "uapi disponible : création de la base via l'API cPanel en ligne de commande."
    read -rp "Nom court de la base (sera préfixé par ton login cPanel, ex: brvm) : " DB_SHORT_NAME
    read -rp "Nom court de l'utilisateur MySQL (ex: brvm_user) : " DB_SHORT_USER
    read -rsp "Mot de passe MySQL à créer : " DB_PASS
    echo

    uapi Mysql create_database name="$DB_SHORT_NAME"
    uapi Mysql create_user name="$DB_SHORT_USER" password="$DB_PASS"
    WHOAMI="$(whoami)"
    DB_FULL_NAME="${WHOAMI}_${DB_SHORT_NAME}"
    DB_FULL_USER="${WHOAMI}_${DB_SHORT_USER}"
    uapi Mysql set_privileges_on_database user="$DB_FULL_USER" database="$DB_FULL_NAME" privileges="ALL PRIVILEGES"

    log "Import du schéma (scripts/BD_cpanel_import.sql)..."
    mysql -u "$DB_FULL_USER" -p"$DB_PASS" "$DB_FULL_NAME" < "$APP_DIR/scripts/BD_cpanel_import.sql"

    cat > "$APP_DIR/.env" <<EOF
APP_ENV=production
DB_HOST=localhost
DB_PORT=3306
DB_NAME=$DB_FULL_NAME
DB_USER=$DB_FULL_USER
DB_PASSWORD=$DB_PASS

ANTHROPIC_API_KEY=
GEMINI_API_KEY=
EOF
    chmod 600 "$APP_DIR/.env"
    log "Base de données créée + .env généré (clés IA à renseigner à la main si besoin)."
else
    log "uapi indisponible — crée la base manuellement (cPanel → MySQL® Databases, voir DEPLOY_CPANEL.md étapes 1-2),"
    log "puis relance ce script (il détectera l'absence de .env et s'arrêtera ici tant qu'il n'existe pas)."
    log "Une fois la base créée, crée toi-même $APP_DIR/.env (voir DEPLOY_CPANEL.md étape 4) avant de continuer."
fi

log "=== 5/6 — Protection du .env contre l'accès web direct ==="
HTACCESS="$APP_DIR/.htaccess"
if [ ! -f "$HTACCESS" ] || ! grep -q '<Files ".env">' "$HTACCESS" 2>/dev/null; then
    cat >> "$HTACCESS" <<'EOF'
<Files ".env">
    Require all denied
</Files>
EOF
    log ".htaccess mis à jour."
fi

log "=== 6/6 — Cron (synchro intrajournalière, voir cron_sync_brvm.php) ==="
CRON_CMD="*/5 * * * * php $APP_DIR/cron_sync_brvm.php >> $APP_DIR/logs/cron_intraday.log 2>&1"
if crontab -l 2>/dev/null | grep -qF "cron_sync_brvm.php"; then
    log "Une entrée cron pour cron_sync_brvm.php existe déjà, pas de doublon ajouté."
else
    (crontab -l 2>/dev/null; echo "$CRON_CMD") | crontab -
    log "Cron ajouté : toutes les 5 minutes, tous les jours (le script se filtre lui-même hors heures de marché)."
fi

echo
log "=== Terminé ==="
if [ -f "$APP_DIR/.env" ]; then
    log "Vérifie : https://tondomaine.com/brvmapi/api_companies.php (401 attendu = OK, PHP+DB fonctionnent)"
    log "Compte admin (si pas déjà fait) :"
    log "  php $APP_DIR/scripts/create_admin_user.php --username=admin --password=..."
    log "Diagnostic complet (exec/pdftotext/tesseract/connectivité) :"
    log "  php $APP_DIR/scripts/check_hosting_requirements.php"
else
    log "⚠️  Termine la configuration de la base de données (.env manquant), puis relance ce script."
fi
