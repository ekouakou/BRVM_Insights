# Déploiement du backend sur un hébergement mutualisé (cPanel)

Cible : `tondomaine.com/brvmapi` (sous-dossier de `public_html`).

## Avec accès SSH (recommandé)

Si SSH est activé sur ton compte (cPanel → Security → SSH Access), utilise
plutôt **`scripts/deploy_cpanel_ssh.sh`** : il automatise tout ce que ce
guide décrit à la main (clone git, base de données via `uapi`, permissions,
`.env`, cron) et est relançable pour les mises à jour futures. Voir
l'en-tête du script pour les 3 commandes à lancer une fois connecté en SSH.

Le reste de ce guide (étapes manuelles via l'interface cPanel) reste valable
si SSH n'est pas disponible, ou en repli si `uapi`/`git` manquent sur le
serveur.

## Sans accès SSH

Les étapes 1 à 7 ci-dessous n'utilisent que l'interface cPanel (Gestionnaire
de fichiers, MySQL® Databases, phpMyAdmin, Cron Jobs).

## ⚠️ À savoir avant de commencer

Un hébergement mutualisé est beaucoup plus restreint qu'un poste de dev
(MAMP) ou qu'un VPS/Docker : pas d'accès root, souvent `exec()`/
`shell_exec()` désactivés par sécurité, et aucun binaire système
(`pdftotext`, `tesseract`) installé par défaut. Deux fonctionnalités de
l'app en dépendent :

- **Extraction de texte des PDF** (`class/PdfTextExtractor.php`) — rapports
  et bulletins.
- **Formatage markdown en arrière-plan** (`format_markdown` dans
  `api_reports.php`/`api_bulletins.php`) — lance un process PHP détaché, un
  pattern déjà signalé comme fragile même sous MAMP.

**Solution de repli déjà intégrée à l'app**, si ces fonctions ne marchent
pas sur ton hébergement : sur ta machine de dev (où `pdftotext`/`tesseract`
sont déjà installés), traite/formate le PDF normalement, télécharge le
markdown généré (bouton "Télécharger le markdown"), puis importe-le sur le
serveur de prod via le bouton **"Importer un markdown"** — ce chemin-là
n'a besoin d'aucun `exec()` côté serveur. Un peu plus manuel, mais
fonctionne partout.

Le reste de l'app (API, scraping cours/indices, synchro cron, panneau
d'admin) ne dépend d'aucune de ces deux fonctionnalités et tournera
normalement.

## Étape 0 — Vérifier ce que l'hébergement autorise réellement

Avant de tout configurer, upload **juste** `scripts/check_hosting_requirements.php`
(voir ce fichier) dans un dossier temporaire accessible par le web (ex:
`public_html/check.php`), ouvre-le dans le navigateur, note le résultat,
**puis supprime-le immédiatement** (il expose des détails de config
serveur). Ça te dit tout de suite si `pdftotext`/`exec()` sont dispo, si les
extensions PHP requises (pdo_mysql, curl, mbstring, dom) sont actives, et si
le serveur peut sortir vers brvm.org en HTTPS.

## Étape 1 — Créer la base de données (cPanel → MySQL® Databases)

1. cPanel → **MySQL® Databases** → créer une base (cPanel préfixe
   automatiquement par ton nom de compte, ex: `monlogin_brvm_trading_app`).
2. Créer un utilisateur MySQL dédié (pas root), mot de passe robuste.
3. L'associer à la base créée avec **tous les privilèges** (ALL
   PRIVILEGES).
4. Noter les 3 valeurs générées (nom de base, utilisateur, mot de passe) —
   nécessaires à l'étape 4.

## Étape 2 — Importer le schéma (phpMyAdmin)

1. cPanel → **phpMyAdmin**, sélectionner la base créée à l'étape 1 (bien
   vérifier qu'elle est sélectionnée dans le menu de gauche avant
   d'importer).
2. Onglet **Importer** → choisir **`scripts/BD_cpanel_import.sql`** (PAS
   `BD.sql` directement — celui-ci contient un `CREATE DATABASE`/`USE` qui
   échoue sans le privilège CREATE DATABASE que cPanel ne donne pas).
3. Lancer l'import (~30 Ko, quelques secondes).
4. Vérifier qu'une trentaine de tables sont apparues (`companies`,
   `stock_quotes`, `market_bulletins`, `admin_users`...).

## Étape 3 — Uploader le code

1. cPanel → **Gestionnaire de fichiers** → aller dans `public_html`, créer
   un dossier `brvmapi`.
2. Sur ta machine : compresser le contenu de `Backend/brvm-api/` (le
   contenu du dossier, pas le dossier lui-même) en `.zip` — **exclure**
   `storage/`, `logs/`, `cache/`, `uploads/`, `temp/`, `locks/`, `.git`,
   `.env` (mêmes exclusions que `.dockerignore`, pas la peine d'uploader du
   contenu régénérable ou des secrets).
3. Uploader ce zip dans `public_html/brvmapi/`, puis "Extract" via le
   Gestionnaire de fichiers.
4. Vérifier que `public_html/brvmapi/config.php`, `api_reports.php`, etc. sont
   bien à la racine de `brvmapi/` (pas dans un sous-dossier `brvm-api/`
   imbriqué — piège classique de l'extraction zip).
5. Recréer les dossiers manquants avec permissions en écriture : `storage`,
   `storage/reports`, `storage/bulletins`, `logs`, `cache`, `uploads`,
   `temp`, `locks` (clic droit → **Permissions** → 755, ou 775 si 755 ne
   suffit pas selon la config de l'hébergeur).

## Étape 4 — Configurer `.env`

Dans le Gestionnaire de fichiers, créer `public_html/brvmapi/.env` (fichier
texte, pas de zip) avec :

```
APP_ENV=production
DB_HOST=localhost
DB_PORT=3306
DB_NAME=monlogin_brvm_trading_app
DB_USER=monlogin_brvm_user
DB_PASSWORD=le-mot-de-passe-de-l-etape-1

ANTHROPIC_API_KEY=
GEMINI_API_KEY=
```

`DB_HOST` est presque toujours `localhost` sur du mutualisé (MySQL tourne
sur la même machine). Laisser les clés IA vides désactive juste l'analyse
IA, le reste fonctionne sans.

Vérifier que ce `.env` n'est PAS accessible publiquement : ouvrir
`https://tondomaine.com/brvmapi/.env` dans le navigateur, ça doit renvoyer une
erreur (403/404), pas le contenu du fichier. Si le contenu s'affiche,
ajouter dans `public_html/brvmapi/.htaccess` :

```apache
<Files ".env">
    Require all denied
</Files>
```

## Étape 5 — Créer le compte admin du panneau

Sans SSH, pas de CLI directe pour `scripts/create_admin_user.php`. Deux
options :

- **Terminal cPanel** si ton offre l'inclut (cPanel → **Terminal**) :
  ```bash
  cd public_html/brvmapi
  php scripts/create_admin_user.php --username=admin --password=un-mot-de-passe-solide
  ```
- Sinon, créer un petit script web temporaire (ex: `public_html/brvmapi/setup_admin.php`)
  qui appelle la même logique, l'exécuter une fois depuis le navigateur,
  **puis le supprimer immédiatement** (ne jamais laisser un endpoint de
  création de compte accessible publiquement).

## Étape 6 — Vérifier que l'API répond

Ouvrir `https://tondomaine.com/brvmapi/api_companies.php` (ou tester via
Postman avec `psman.json`, en changeant `base_url` pour
`https://tondomaine.com/brvmapi`) — doit renvoyer un JSON (401 "Authentification
requise" est normal pour la plupart des endpoints, c'est le signe que PHP et
la connexion DB fonctionnent).

## Étape 7 — Configurer le cron (ne pas rater l'ouverture de la bourse)

cPanel → **Cron Jobs**.

**Commande** (adapter le chemin PHP — voir note ci-dessous) :

```
/usr/local/bin/php /home/monlogin/public_html/brvmapi/cron_sync_brvm.php >> /home/monlogin/public_html/brvmapi/logs/cron_intraday.log 2>&1
```

**Fréquence recommandée : toutes les 5 minutes, tous les jours, toute la
journée** (`*/5 * * * *` dans l'UI cPanel : Minute=`*/5`, Heure=`*`,
Jour=`*`, Mois=`*`, Jour de semaine=`*`).

Pourquoi "toute la journée" et pas juste 8h-16h : `cron_sync_brvm.php`
recalcule lui-même l'heure d'ouverture en fuseau **Africa/Abidjan** à chaque
exécution (`isMarketOpen()`, voir le code) et s'arrête immédiatement si le
marché est fermé — indépendamment du fuseau horaire *du serveur*, qu'on ne
connaît pas forcément à l'avance sur un mutualisé. Le faire tourner toute la
journée élimine tout risque de mauvais calage horaire et donc de rater
l'ouverture — le coût est négligeable (le script sort en quelques
millisecondes hors heures de marché, aucune requête vers brvm.org).

**Chemin du binaire PHP** : varie selon l'hébergeur et la version PHP
choisie pour le compte. Pistes pour le trouver :
- cPanel → **Cron Jobs** propose parfois un menu déroulant pour choisir la
  version PHP directement dans la commande.
- cPanel → **Sélecteur PHP / MultiPHP Manager** affiche la version active
  pour le compte.
- À défaut, essayer simplement `php` (sans chemin complet) — fonctionne sur
  beaucoup d'hébergeurs si le binaire est dans le `PATH` du cron.

Après la première exécution planifiée, vérifier
`public_html/brvmapi/logs/cron_intraday.log` pour confirmer que ça tourne sans
erreur.

## Étape 8 — Mettre à jour le code (Git™ Version Control)

Une fois le déploiement initial fait (étapes 1-7), les mises à jour de code
n'ont plus besoin de zip/upload manuel si cPanel propose **Git™ Version
Control** — un fichier `.cpanel.yml` (à la racine du dépôt) automatise la
synchronisation vers `public_html/brvmapi/` à chaque déploiement.

**Mise en place (une seule fois) :**

1. cPanel → **Git™ Version Control** → **Create**.
2. **Clone URL** : `https://github.com/ekouakou/BRVM_Insights.git`
3. **Repository Path** : laisser la valeur par défaut proposée par cPanel,
   ou choisir explicitement un chemin **en dehors de `public_html`** (ex:
   `/home/brimmobi/repositories/brvm_insights`) — c'est une copie de travail
   git, elle ne doit jamais être le dossier servi publiquement.
4. Valider. Le clonage télécharge tout le monorepo, y compris les ~600 Mo de
   PDF déjà collectés (`Backend/brvm-api/storage/`) — normal, ça ne prend
   pas de place supplémentaire dans `public_html/brvmapi/` (le
   `.cpanel.yml` ne copie qu'une liste précise de fichiers/dossiers de code,
   jamais `storage/`).

**À chaque mise à jour :**

1. cPanel → **Git™ Version Control** → **Manage** sur le dépôt.
2. Onglet **Pull or Deploy**.
3. **Pull** (récupère les derniers commits depuis GitHub) puis **Deploy
   HEAD Commit** (exécute `.cpanel.yml`, qui copie le code mis à jour vers
   `public_html/brvmapi/`).

`.env`, `storage/`, `logs/`, `cache/`, `uploads/`, `temp/`, `locks/` et
`.htaccess` ne sont jamais touchés par ce déploiement (liste blanche
explicite dans `.cpanel.yml`, pas de suppression de ce qui n'y figure pas)
— aucun risque d'écraser la config ou les PDF déjà téléchargés en prod.

## Résumé des fichiers concernés

| Fichier | Rôle |
|---|---|
| `.cpanel.yml` (racine du dépôt) | Automatise le déploiement via Git™ Version Control (étape 8) |
| `scripts/deploy_cpanel_ssh.sh` | Déploiement automatisé si SSH est disponible (voir section dédiée en haut de ce guide) |
| `scripts/check_hosting_requirements.php` | Diagnostic à uploader/supprimer en premier (étape 0) |
| `scripts/BD_cpanel_import.sql` | Schéma sans `CREATE DATABASE`/`USE`, pour import phpMyAdmin (étape 2) — utilisé aussi par `deploy_cpanel_ssh.sh` |
| `config.php` | Lit maintenant `.env` **avant** de définir la config DB (corrigé pour ce cas d'usage) |
