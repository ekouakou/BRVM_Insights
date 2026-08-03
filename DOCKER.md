# Déploiement avec Docker

Ce dépôt peut tourner entièrement en conteneurs — trois services :
`mysql` (base de données), `backend` (API PHP + synchro cron), `frontend`
(panneau d'admin React servi par nginx, avec proxy vers `backend`).

Objectif : harmoniser le déploiement (même image partout, plus de dépendance
à une installation MAMP locale précise) et faciliter la compatibilité entre
machines de dev/prod.

## Démarrage rapide

```bash
cp .env.example .env
# éditer .env : mots de passe DB, clés IA (facultatif)

docker compose up -d --build
```

- Frontend (panneau d'admin) : http://localhost:8080
- Backend (API, testable directement/Postman) : http://localhost:8081
- MySQL (accès direct, ex: client GUI) : localhost:3307

Le frontend appelle l'API via `/api/...` sur son propre port (proxy nginx
interne vers le conteneur `backend`, voir `Frontend/admin-web/nginx.conf`) —
pas de CORS à gérer, fonctionne quel que soit le nom de domaine/IP utilisé.

## Premier démarrage : créer un compte admin

Le panneau d'admin (`Frontend/admin-web`) n'a aucun endpoint d'inscription
publique — un compte se crée uniquement en CLI, à l'intérieur du conteneur
backend :

```bash
docker compose exec backend php scripts/create_admin_user.php --username=admin --password=un-mot-de-passe-solide
```

## Ce que fait chaque service

| Service | Rôle | Notes |
|---|---|---|
| `mysql` | Base de données | Schéma initialisé automatiquement depuis `Backend/brvm-api/BD.sql` au tout premier démarrage (volume `db_data` vide) |
| `backend` | API PHP (Apache) + synchro cron | Démarre `cron` en arrière-plan (voir `Backend/brvm-api/docker/crontab`) puis Apache au premier plan — une seule image sert les deux rôles |
| `frontend` | Panneau d'admin React | Build Vite → fichiers statiques servis par nginx, avec reverse proxy `/api/*` vers `backend` |

La synchro intrajournalière (`cron_sync_brvm.php`, cotations + indices +
indicateurs techniques) tourne désormais dans le conteneur `backend` toutes
les 15 minutes — remplace `scripts/run_daily_sync.sh` + launchd (qui
restent utiles pour un dev en dehors de Docker, voir `CRON_SETUP.md`).
Le script se filtre déjà lui-même en dehors des heures de marché, aucune
action requise.

## Persistance des données

Trois volumes nommés survivent à un `docker compose down` (mais pas à
`docker compose down -v`, qui les supprime) :

- `db_data` — la base MySQL
- `backend_storage` — les PDF téléchargés (rapports/bulletins)
- `backend_logs` — les journaux applicatifs

`cache/`, `uploads/`, `temp/`, `locks/` restent dans le système de fichiers
du conteneur backend (recréés automatiquement au besoin) : pas de perte
gênante si le conteneur est recréé.

## Variables d'environnement (`.env` à la racine)

Voir `.env.example` pour la liste complète et les valeurs par défaut. Les
plus importantes :

- `DB_ROOT_PASSWORD`, `DB_USER`, `DB_PASSWORD` — identifiants MySQL. Change
  les valeurs par défaut avant tout déploiement exposé publiquement.
- `ANTHROPIC_API_KEY` / `GEMINI_API_KEY` — facultatives ; laisser vide
  désactive juste les fonctionnalités d'analyse IA, le reste de l'app
  fonctionne normalement.
- `VITE_API_BASE_URL` — inscrite en dur dans le JS compilé du frontend
  (contrainte Vite), donc un changement nécessite un rebuild :
  `docker compose build frontend`.

## Commandes utiles

```bash
docker compose logs -f backend        # logs Apache + cron du backend
docker compose exec backend bash      # shell dans le conteneur backend
docker compose exec mysql mysql -uroot -p brvm_trading_app   # accès SQL direct
docker compose down                   # arrête les conteneurs, garde les volumes
docker compose down -v                # arrête et supprime aussi les volumes (⚠️ perte de données)
```

## Ce qui n'est PAS couvert par ce Dockerfile

- Certificat TLS/HTTPS : ce setup expose du HTTP brut sur les ports
  publiés — pour un déploiement public, mettre un reverse proxy (Traefik,
  Caddy, nginx externe...) devant avec un certificat, ou terminer le TLS en
  amont (load balancer du fournisseur cloud).
- Sauvegardes automatiques de `db_data`/`backend_storage`.
