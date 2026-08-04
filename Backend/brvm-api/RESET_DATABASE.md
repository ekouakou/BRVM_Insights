# Réinitialisation complète de la base de données

Script de suppression totale (tables + vues) pour repartir d'une base vide et
la réimporter à neuf. Utilisable en local (MAMP) comme en production
(cPanel/phpMyAdmin).

## Obtenir un fichier à importer : exporter une base existante

Pour repartir des vraies données de production en local (ou inversement),
utilise **`api_db_dump.php`** plutôt qu'un export phpMyAdmin manuel — c'est
lui qui a produit exactement ce genre de confusion (fichiers multiples,
encodage corrompu) la dernière fois :

- Panneau d'admin → page **Synchronisation** → bouton **"Télécharger un dump
  de la base (.sql)"**
- Ou directement : `GET api_db_dump.php?action=download&token=...`

Dump complet (schéma + données, tables et vues) en pur PHP/PDO — pas de
dépendance à `mysqldump`, encodage UTF-8 propre garanti (requêtes non
bufferisées + écriture progressive, robuste même sur une grosse table sans
déclencher les timeouts de 30s rencontrés ailleurs sur ce projet), clause
`DEFINER` des vues retirée pour rester importable sur n'importe quel compte
MySQL. Le fichier généré s'importe directement (`mysql -u ... < dump.sql` ou
phpMyAdmin → Importer) : il gère lui-même la suppression préalable de tout
ce qu'il va recréer, pas besoin de lancer le script de l'étape 1 avant.

## ⚠️ Avant de lancer quoi que ce soit (suppression manuelle, section suivante)

**Efface irrémédiablement toutes les données** : cotations, entreprises,
rapports/bulletins téléchargés et analysés, comptes admin, tout. Pas de
retour en arrière possible une fois exécuté (sauf sauvegarde préalable).

- **En local** : sans risque particulier, c'est ta base de développement.
- **En production** : à ne faire que si tu es certain de vouloir repartir de
  zéro — pas pour une simple mise à jour de schéma (voir `migrations/` +
  `scripts/migrate.php`, qui gèrent ça sans perte de données).

## Étape 1 — Tout supprimer

À coller dans **phpMyAdmin → onglet SQL** (production) ou à exécuter via
`mysql -u root -p brvm_trading_app < ce_fichier.sql` (local) :

```sql
SET FOREIGN_KEY_CHECKS = 0;

-- Vues (DROP TABLE ne fonctionne pas dessus, il faut DROP VIEW)
DROP VIEW IF EXISTS latest_quotes;
DROP VIEW IF EXISTS top_gainers;
DROP VIEW IF EXISTS top_losers;
DROP VIEW IF EXISTS volume_leaders;

-- Suivi des migrations (voir scripts/migrate.php) — inclus pour une remise à
-- zéro totale, sinon d'anciennes entrées "déjà appliquée" pourraient rester
-- même après suppression des tables qu'elles concernaient.
DROP TABLE IF EXISTS schema_migrations;

-- Tables, dans un ordre quelconque grâce à FOREIGN_KEY_CHECKS=0 ci-dessus
DROP TABLE IF EXISTS
    admin_sessions,
    admin_users,
    combined_analyses,
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
    intraday_quotes,
    stock_quotes,
    companies,
    countries,
    sectors;

SET FOREIGN_KEY_CHECKS = 1;
```

Vérifie ensuite que la base est bien vide (0 table, 0 vue) avant de
continuer — dans phpMyAdmin, la liste des tables à gauche doit être vide.

## Étape 2 — Réimporter le schéma

**En local (MAMP)** :
```bash
mysql -u root -p brvm_trading_app < Backend/brvm-api/BD.sql
```
ou phpMyAdmin → Importer → `BD.sql`.

**En production (cPanel)** — utiliser la variante sans `CREATE DATABASE`/`USE`
(cPanel ne donne pas ce privilège, voir `DEPLOY_CPANEL.md`) :
phpMyAdmin → sélectionner la base → Importer → `Backend/brvm-api/scripts/BD_cpanel_import.sql`.

Si `BD.sql` a été modifié depuis la dernière fois, régénère d'abord la
variante cPanel :
```bash
sed '5,8d' Backend/brvm-api/BD.sql > Backend/brvm-api/scripts/BD_cpanel_import.sql
```
(supprime les lignes `CREATE DATABASE`/`USE`, garde tout le reste — voir
l'en-tête de `BD_cpanel_import.sql` pour le détail).

## Étape 3 — Migrations restant à appliquer

`BD.sql` contient déjà tout ce que les migrations 002 à 006 apporteraient
(marquées `-- ALREADY-IN-BD-SQL` dans `migrations/`) — pas besoin de les
rejouer. Seules les migrations **sans** ce marqueur (ex: `007_seed_company_sectors.sql`,
qui remplit `companies.sector_id`) doivent réellement s'exécuter après
l'import, une fois que les entreprises existent en base.

**En local** — la méthode recommandée, entièrement automatique :
```bash
php Backend/brvm-api/scripts/migrate.php
```
Il détecte la base vide, importe `BD.sql`, marque les migrations déjà
incluses comme faites, et laisse les autres (ex: 007) en attente pour un
prochain lancement (une fois que des entreprises existent, via une synchro).

**En production** (pas de CLI disponible) — reproduire manuellement ce que
`migrate.php` ferait, dans phpMyAdmin → SQL :
```sql
CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL UNIQUE,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (migration) VALUES
    ('002_company_reports.sql'),
    ('003_report_extraction_method.sql'),
    ('004_admin_auth.sql'),
    ('005_sync_interval_10min.sql'),
    ('006_intraday_variation_percent.sql');
```
Puis, une fois que les entreprises existent en base (après une première
synchro ou un rattachement — voir étape 5), appliquer le contenu de
`migrations/007_seed_company_sectors.sql` (copier-coller ses instructions
`UPDATE companies SET sector_id = ...` dans phpMyAdmin → SQL), et enfin :
```sql
INSERT IGNORE INTO schema_migrations (migration) VALUES ('007_seed_company_sectors.sql');
```

**Si une nouvelle migration est ajoutée plus tard** (`008_...` ou plus),
vérifie si son contenu doit aussi être répercuté dans `BD.sql` (et marqué
`-- ALREADY-IN-BD-SQL` si c'est du schéma pur) — sinon elle doit rester "en
attente" comme la 007, à appliquer une fois manuellement en production tant
qu'il n'y a pas de CLI disponible.

## Étape 4 — Recréer le compte admin du panneau

**En local** :
```bash
php Backend/brvm-api/scripts/create_admin_user.php --username=admin --password=un-mot-de-passe-solide
```

**En production** (pas de CLI) — script web temporaire à uploader,
exécuter une fois, puis **supprimer immédiatement** (voir `DEPLOY_CPANEL.md`,
étape 5, pour la marche à suivre complète et les précautions de sécurité).

## Étape 5 — Repeupler les données

Une fois connecté au panneau d'admin :

1. Page **Rapports** → bouton **"Rattacher les entreprises et charger tous
   les rapports"** — rattache les entreprises à l'annuaire brvm.org et
   télécharge/extrait leurs rapports (peut prendre du temps).
2. Le cron intrajournalier (déjà configuré, voir `CRON_SETUP.md` /
   `DEPLOY_CPANEL.md` étape 7) reprendra automatiquement le remplissage de
   `stock_quotes`/`intraday_quotes` au prochain passage — aucune action
   requise si le cron est déjà en place.
3. Une fois que les entreprises existent, applique la migration 007 (voir
   étape 3) si ce n'est pas déjà fait, pour retrouver le classement par
   secteur.

## Résumé express (local, cas courant)

```bash
mysql -u root -p brvm_trading_app < Backend/brvm-api/BD.sql
php Backend/brvm-api/scripts/migrate.php
php Backend/brvm-api/scripts/create_admin_user.php --username=admin --password=...
```
Puis se connecter au panneau et cliquer "Rattacher les entreprises et
charger tous les rapports".
