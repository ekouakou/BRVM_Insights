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

> **#1451 / #1217 « Impossible de supprimer un enregistrement père » ?**
> C'est que `SET FOREIGN_KEY_CHECKS = 0;` n'a pas été exécuté dans la MÊME
> session que les `DROP`. Cause quasi systématique : n'avoir copié qu'une
> partie du bloc — phpMyAdmin affiche alors la requête fautive, qui commence
> directement par `DROP TABLE`. **Copiez le bloc ci-dessous en entier**, de
> la première à la dernière ligne, et exécutez-le d'un seul coup.

À coller dans **phpMyAdmin → onglet SQL** (production) ou à exécuter via
`mysql -u root -p brvm_trading_app < ce_fichier.sql` (local).

Ce script se met à jour tout seul : il lit la liste réelle des tables et des
vues de la base courante au moment de l'exécution, plutôt que de s'appuyer
sur une liste écrite à la main. Aucune table ajoutée par une future migration
ne peut donc lui échapper — c'était le défaut de la version précédente, qu'il
fallait compléter à chaque migration sous peine de laisser des tables
survivre à la « remise à zéro » et provoquer des `#1050 already exists` au
réimport. Il est relançable sans risque : sur une base déjà vide, il affiche
simplement « Aucune table à supprimer ».

```sql
-- Liste des tables concaténée en une seule commande : la limite par défaut
-- de GROUP_CONCAT (1024 caractères) tronquerait la liste au-delà d'une
-- quarantaine de tables, et la suppression serait silencieusement partielle.
SET SESSION group_concat_max_len = 1000000;

-- Indispensable, et dans la MÊME session que les DROP : sans cela, toute
-- table référencée par une clé étrangère refuse d'être supprimée.
SET FOREIGN_KEY_CHECKS = 0;

-- Procédures stockées : ni des tables ni des vues, DROP TABLE/VIEW ne les
-- touche pas (oubli qui laissait #1304 "already exists" au réimport).
DROP PROCEDURE IF EXISTS calculate_technical_indicators;

-- Vues (DROP TABLE ne fonctionne pas dessus, il faut DROP VIEW)
SET @vues = NULL;
SELECT GROUP_CONCAT(CONCAT('`', TABLE_NAME, '`')) INTO @vues
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'VIEW';
SET @sql = IF(@vues IS NULL, 'SELECT "Aucune vue a supprimer" AS resultat',
              CONCAT('DROP VIEW IF EXISTS ', @vues));
PREPARE requete FROM @sql; EXECUTE requete; DEALLOCATE PREPARE requete;

-- Toutes les tables de la base courante, y compris schema_migrations (pour
-- une remise à zéro totale : sinon d'anciennes entrées "déjà appliquée"
-- resteraient alors que les tables concernées ont disparu).
SET @tables = NULL;
SELECT GROUP_CONCAT(CONCAT('`', TABLE_NAME, '`')) INTO @tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE';
SET @sql = IF(@tables IS NULL, 'SELECT "Aucune table a supprimer" AS resultat',
              CONCAT('DROP TABLE IF EXISTS ', @tables));
PREPARE requete FROM @sql; EXECUTE requete; DEALLOCATE PREPARE requete;

SET FOREIGN_KEY_CHECKS = 1;
```

### Vérifier que la base est bien vide

```sql
SELECT COUNT(*) AS objets_restants
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE();
```

Le résultat doit être `0`. S'il ne l'est pas, c'est que le bloc n'a pas été
exécuté en entier (voir l'avertissement en tête de section).

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
