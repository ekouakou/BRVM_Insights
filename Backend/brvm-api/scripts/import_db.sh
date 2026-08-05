#!/usr/bin/env bash
set -euo pipefail

# Réimport propre d'un dump SQL en local (MAMP) : supprime tout le contenu
# existant de la base cible (tables, vues, procédure) puis importe le fichier
# donné — équivalent scripté des étapes manuelles de RESET_DATABASE.md.
# Accepte un .sql.gz (dump téléchargé via api_db_dump.php, compressé) tel
# quel — pas besoin de le décompresser à la main au préalable.
#
# Usage : scripts/import_db.sh chemin/vers/dump.sql[.gz]
#
# Variables surchargeables (par défaut : MAMP local) :
#   MYSQL_BIN, MYSQL_SOCK, DB_NAME, DB_USER, DB_PASS

DUMP_FILE="${1:-}"
if [ -z "$DUMP_FILE" ] || [ ! -f "$DUMP_FILE" ]; then
    echo "Usage: $0 chemin/vers/dump.sql[.gz]" >&2
    exit 1
fi

MYSQL_BIN="${MYSQL_BIN:-/Applications/MAMP/Library/bin/mysql80/bin/mysql}"
MYSQL_SOCK="${MYSQL_SOCK:-/Applications/MAMP/tmp/mysql/mysql.sock}"
DB_NAME="${DB_NAME:-brvm_trading_app}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-root}"

if [ ! -x "$MYSQL_BIN" ]; then
    echo "Binaire mysql introuvable : $MYSQL_BIN (ajuste-le via la variable d'environnement MYSQL_BIN)" >&2
    exit 1
fi

MYSQL=("$MYSQL_BIN" -u"$DB_USER" -p"$DB_PASS" -S "$MYSQL_SOCK" --default-character-set=utf8mb4)

echo "Cible : base '$DB_NAME' (socket $MYSQL_SOCK)"
echo "Ceci va supprimer TOUTES les tables/vues existantes de cette base avant d'importer :"
echo "  $DUMP_FILE"
read -r -p "Continuer ? (taper OUI) " CONFIRM
[ "$CONFIRM" = "OUI" ] || { echo "Annulé."; exit 1; }

echo "→ Base '$DB_NAME' (création si absente)…"
"${MYSQL[@]}" -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "→ Suppression du contenu existant (tables, vues, procédure)…"
{
    echo "SET FOREIGN_KEY_CHECKS = 0;"
    echo "DROP PROCEDURE IF EXISTS calculate_technical_indicators;"
    # Vues et tables découvertes dynamiquement plutôt qu'une liste figée à
    # maintenir — pas besoin de mettre ce script à jour à chaque nouvelle table.
    "${MYSQL[@]}" -N "$DB_NAME" -e "SHOW FULL TABLES WHERE Table_type = 'VIEW'" 2>/dev/null \
        | awk '{print "DROP VIEW IF EXISTS `" $1 "`;"}'
    "${MYSQL[@]}" -N "$DB_NAME" -e "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'" 2>/dev/null \
        | awk '{print "DROP TABLE IF EXISTS `" $1 "`;"}'
    echo "SET FOREIGN_KEY_CHECKS = 1;"
} | "${MYSQL[@]}" "$DB_NAME"

echo "→ Import de $DUMP_FILE…"
case "$DUMP_FILE" in
    *.gz) gunzip -c "$DUMP_FILE" | "${MYSQL[@]}" "$DB_NAME" ;;
    *) "${MYSQL[@]}" "$DB_NAME" < "$DUMP_FILE" ;;
esac

echo "→ Vérification…"
"${MYSQL[@]}" -N "$DB_NAME" -e \
    "SELECT CONCAT(COUNT(*), ' tables/vues importées') FROM information_schema.tables WHERE table_schema = '$DB_NAME';"

echo "Import terminé."
