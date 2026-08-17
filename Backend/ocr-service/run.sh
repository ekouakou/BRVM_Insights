#!/usr/bin/env bash
# Démarre l'API OCR. Crée l'environnement Python et installe les dépendances
# au premier lancement, puis se contente de démarrer les fois suivantes.
set -euo pipefail
cd "$(dirname "$0")"

if [ ! -d .venv ]; then
  echo "Premier lancement : création de l'environnement Python…"
  python3 -m venv .venv
  ./.venv/bin/pip install --quiet --upgrade pip
  ./.venv/bin/pip install --quiet -r requirements.txt
fi

PORT="${PORT:-8077}"
echo "API OCR : http://127.0.0.1:${PORT}  (documentation interactive : /docs)"
exec ./.venv/bin/uvicorn app.main:app --host 127.0.0.1 --port "$PORT" "$@"
