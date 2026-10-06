# Casamiza : API PHP

API REST du SaaS de gestion de conciergerie Casamiza (back-end du front `../Casamiza - Front Admin/`).
**Conception, modèle de données, décisions et feuille de route : `../CLAUDE.md`** (à lire d'abord, et à tenir à jour).
Ce fichier-ci est le guide de travail dans ce dossier.

Stack : PHP **8.4**, Slim 4 + slim/psr7, PHP-DI, PDO direct (MySQL 9), Monolog, vlucas/phpdotenv, Phinx.
Pas d'ORM. Multi-tenant par colonne `tenant_id`.

**Langue : l'API ne renvoie AUCUN texte en français** (ni message d'erreur, ni libellé, ni `detail` rédigé). Elle
renvoie uniquement des **codes** en `snake_case` minuscule, comme `endpoint_not_found`, `invalid_credentials`,
`owner_not_found`, `validation_failed`. C'est le front qui traduit ces codes en français. Code, tables et routes en
anglais. Les statuts et libellés métier sont des valeurs d'enum (`to-invoice`, `airbnb`…), jamais des phrases.

## Commandes

Toujours via `composer` ou `bin/php` (PHP 8.4 de Homebrew : le `php` par défaut du Mac est plus récent).

```bash
composer dev                # MySQL + API http://localhost:8080 + phpMyAdmin http://localhost:8081 (Ctrl+C arrête)
composer migrate            # Phinx : joue les migrations en attente
composer seed               # tenant et équipe de dev (mot de passe : casamiza-dev-2026)
composer console -- tenant:create   # crée un tenant et son premier admin (CLI Symfony Console)
composer rollback           # annule la dernière migration
composer migration:status
composer test               # PHPUnit (SQLite en mémoire, pas besoin de MySQL)
composer stan               # PHPStan niveau 8
composer cs / cs:fix        # PHP-CS-Fixer (PER-CS, strict_types)
composer check              # cs + stan + test : à passer avant de terminer une tâche
bin/php vendor/bin/phinx create NomDeLaMigration
```

`composer dev` crée la base `casamiza` et l'utilisateur `casamiza` (mot de passe dans `.env`) s'ils n'existent pas.
La base `main` de la même instance MySQL appartient à un autre projet : **ne jamais y toucher**.

## Architecture

```
public/index.php      point d'entrée (seul dossier exposé par nginx)
config/               container.php (définitions PHP-DI), routes.php
src/Kernel.php        assemble conteneur + middlewares + routes
src/Http/             Controller (fins), Middleware, Response, ErrorHandler
src/Domain/<Module>/  entités, enums, <Module>RepositoryInterface, <Module>Service  (à venir)
src/Infrastructure/   Persistence (Database, BaseRepository, Pdo*Repository), Integration, Security
src/Shared/           Settings, TenantContext, RequestContext, exceptions métier
migrations/ seeds/    Phinx
tests/                PHPUnit
storage/logs|cache/   hors webroot, non versionnés
```

Flux : **Controller → Service → RepositoryInterface → PdoRepository → MySQL**. La logique métier vit dans les
services ; un contrôleur parse la requête, appelle un service, renvoie une réponse.

Ordre des middlewares (extérieur → intérieur) : RequestId → SecurityHeaders → Cors → Erreurs → Body parsing →
Routing. Slim exécute le dernier `add()` en premier.

## Règles à respecter

**Code**
- `declare(strict_types=1)` partout ; classes `final` (et `readonly` pour DTO / objets valeur) par défaut ;
  injection par constructeur ; enums pour les statuts. Dépendre d'**interfaces** aux frontières (repositories,
  clients tiers).
- PHPStan sans `@phpstan-ignore`, sans baseline, sans `@var` pour faire taire une erreur : on corrige le type.
- Montants en `DECIMAL(12,2)`, jamais en float ; dates en **UTC** en base, ISO 8601 en sortie, mois en `AAAA-MM`.

**Multi-tenant (critique)**
- Le `tenant_id` vient **uniquement du JWT** (`TenantContext`), jamais d'un paramètre, d'un en-tête ni du body.
- Tout repository métier **étend `BaseRepository`** (seules exceptions, avant authentification : `PdoAuthUserRepository`,
  `PdoRefreshTokenRepository`, `PdoLoginAttemptRepository`, `PdoUserTokenRepository`, `PdoTenantRepository`, tous à requêtes
  explicitement bornées) et n'écrit aucun SQL hors de ses méthodes : c'est elle qui impose
  `tenant_id = :tid`. Pas de SQL métier dans les services ni les contrôleurs.
- Toute nouvelle table métier : `tenant_id` + index `(tenant_id, …)` + clés étrangères **composites** quand elle
  référence une autre table métier. Un test d'isolation par endpoint (le tenant A ne voit rien du tenant B,
  un id de B renvoie 404).
- Identifiants exposés : **UUID**, jamais d'auto-incrément (anti-IDOR).

**Sécurité**
- Requêtes **toujours préparées** ; `ORDER BY` / colonnes dynamiques en liste blanche ; `$where` / `$suffix` de
  `BaseRepository` sont du SQL écrit par le développeur, jamais une entrée utilisateur.
- Valider chaque entrée (DTO) et rejeter les champs inconnus.
- Secrets de tiers (Guesty, Mailsend, Pennylane, clé Stripe des propriétaires) **chiffrés au repos**, jamais
  renvoyés, jamais loggés. Pas de donnée personnelle ni de jeton dans les logs.
- Erreurs : lever une `ApiException` (`NotFound`, `Forbidden`, `Validation`…) portant un **code** `snake_case`
  (jamais une phrase, jamais de français) ; `ErrorHandler` produit la réponse d'erreur `{error: {code, fields?}}`. Une exception inattendue ne doit rien révéler en production.
- Pas de `*` en CORS ; ne jamais exposer de stack trace ; `.env` jamais versionné.

**Migrations (Phinx)**
- Une migration = un changement cohérent. **Ne jamais modifier une migration déjà jouée** : en créer une nouvelle.
- Ne pas modifier le schéma à la main dans phpMyAdmin (lecture seule pour la structure).
- Données de test dans `seeds/`, pas dans les migrations. Un vrai tenant se crée en CLI, pas par migration.

**Réponses HTTP**
- **Le statut HTTP est la seule source du succès / échec** (2xx = succès, 4xx/5xx = échec) ; il n'est jamais
  répété dans le corps. Une réponse contient soit `data`, soit `error`, jamais les deux.
- Succès : `{"data": …}` (204 sans corps), **sans exception** (y compris `GET /health` → `{"data": {"status": "ok"}}`).
- Échec : `{"error": {"code": "endpoint_not_found"}}`, et pour la validation
  `{"error": {"code": "validation_failed", "fields": {"email": ["invalid_email"]}}}`. `code` est en `snake_case`.
  Pas de `type`, `title`, `detail` ni texte rédigé. En dev seulement, un 500 ajoute un champ `debug` (hors `error`).
- Identifiant de requête dans l'en-tête `X-Request-Id` (toutes les réponses, exposé en CORS), pas dans le corps.
  Logs applicatifs en anglais.
- À chaque nouveau code, ajouter sa traduction française dans le front (table code → message).
- Préfixe `/api/v1` pour les routes métier. Clés JSON en **camelCase**, codes d'erreur en `snake_case`.

**Erreur métier : quel statut ?** Jamais 200 pour un échec. Le code décrit le cas précis, le statut sa famille.

| Situation | Statut | Exception | Exemple de code |
|---|---|---|---|
| Entrée mal formée, règle sur un champ | 422 | `ValidationException` | `validation_failed` + `fields` |
| Conflit avec l'état actuel (peut changer dans le temps) | 409 | `ConflictException` | `price_already_updated`, `invoice_already_issued`, `month_already_invoiced` |
| Règle métier qui refuse durablement cette opération | 422 | `UnprocessableException` *(à créer au premier besoin)* | `last_admin_cannot_be_removed` |
| Ressource absente ou d'un autre tenant | 404 | `NotFoundException` | `owner_not_found` |
| Rôle insuffisant | 403 | `ForbiddenException` | `forbidden` |
| Non authentifié / jeton invalide | 401 | `UnauthorizedException` | `unauthenticated`, `invalid_credentials` |
| JSON illisible | 400 | (Slim) | `bad_request` |
| Dépendance indisponible | 503 | `ServiceUnavailableException` | `database_unavailable` |

Les règles métier sont levées par les services (pas par les repositories) : `throw new ConflictException('price_already_updated');`.
Cas particulier : conflit d'édition concurrente → 409 pour commencer (412 avec `If-Match` si on versionne un jour).

## Méthode : un endpoint, une intégration front

Pour chaque endpoint : migration → repository (+ interface) → service → contrôleur → route → tests (isolation tenant
incluse) → **branchement dans le front** (hook TanStack Query, suppression du magasin / des données faker
correspondants) → vérification dans un vrai navigateur → mise à jour de `../CLAUDE.md` et du `CLAUDE.md` du front.

Ne pas écrire de code d'une phase non validée : on suit la feuille de route de `../CLAUDE.md` §9 et on attend le feu
vert de l'utilisateur entre les phases.

## Tests

- PHPUnit 12, `tests/AppTestCase.php` construit l'application sur **SQLite en mémoire** (`Kernel::createApp` accepte
  des substitutions de conteneur) et expose `request()` / `json()`. Écrire les tests de repositories de façon portable
  (SQL standard) ou, si MySQL est indispensable, le signaler explicitement.
- Chaque endpoint : cas nominal, validation, droits (rôle), isolation tenant.
- Tests d'authentification : `tests/Auth/AuthTestCase.php` (dépôts en mémoire de `tests/Support/`, horloge figée
  `FixedClock`, deux tenants). Le hachage argon2id y est allégé (`PasswordHasher` avec options réduites).
- Les routes ajoutées dans un test ne peuvent pas être des closures `static` (Slim les lie) : utiliser un objet invocable.
- Un service qui écrit plusieurs lignes ou qui vérifie un invariant (ex. « il reste un admin ») passe par `Shared\Transaction`.
- Les dépôts PDO ne sont pas couverts par PHPUnit (SQL MySQL) : vérifier un nouvel endpoint de bout en bout sur la base de dev.

## Environnement

- Config dans `.env` (copie de `.env.example`) : `APP_ENV`, `CORS_ORIGIN`, `DB_*`, `JWT_SECRET` (≥ 32 caractères),
  `ACCESS_TTL_MINUTES`, `REFRESH_TTL_DAYS`, `SESSION_MAX_DAYS`, `COOKIE_SAMESITE` (`none` en dev, `strict` en
  production sur le même domaine) et `COOKIE_SECURE`. L'application refuse de démarrer si une valeur est invalide.
- Production : CloudPanel (nginx + PHP-FPM 8.4), *root directory* = `public/`, `.env` et `storage/` hors webroot,
  worker et syncs lancés par cron.
- Le front tourne séparément (`pnpm dev`, http://localhost:5173).
