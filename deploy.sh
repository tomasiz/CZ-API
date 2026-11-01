#!/usr/bin/env bash
# Met à jour l'API en production. À lancer SUR LE VPS, depuis le dossier du site :
#   cd ~/htdocs/api.casamiza.fr && ./deploy.sh
# PHP_BIN et COMPOSER_BIN sont surchargeables (défaut : php8.4 et composer).
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

PHP_BIN="${PHP_BIN:-php8.4}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
BRANCH="${BRANCH:-main}"

if [[ ! -f .env ]]; then
    echo "✗ .env introuvable : ce dossier n'est pas un site configuré." >&2
    exit 1
fi

if ! grep -q '^APP_ENV=prod' .env; then
    echo "✗ APP_ENV n'est pas « prod » dans .env : déploiement annulé." >&2
    exit 1
fi

if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
    echo "✗ Le dépôt contient des modifications locales : déploiement annulé." >&2
    git status --short --untracked-files=no >&2
    exit 1
fi

echo "→ Récupération du code ($BRANCH)"
git fetch origin "$BRANCH"
git checkout "$BRANCH"
git merge --ff-only "origin/$BRANCH"

echo "→ Dépendances"
"$COMPOSER_BIN" install --no-dev -o --no-interaction

echo "→ Dossiers d'exécution"
mkdir -p storage/{logs,logos,invoices,beyond,guesty,cache/pdf-fonts}

echo "→ Conteneur compilé supprimé (régénéré à la première requête)"
rm -f storage/cache/CompiledContainer*.php

echo "→ Migrations en attente :"
"$PHP_BIN" vendor/bin/phinx status | grep -E "^\s+down" || echo "  aucune"

echo "→ Migrations"
"$PHP_BIN" vendor/bin/phinx migrate

echo "→ Contrôle de l'application"
"$PHP_BIN" bin/console list >/dev/null

echo "✓ Déployé : $(git log -1 --format='%h %s')"
