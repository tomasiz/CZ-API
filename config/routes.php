<?php

declare(strict_types=1);

use Casamiza\Domain\User\Role;
use Casamiza\Http\Controller\AuthController;
use Casamiza\Http\Controller\CompanyController;
use Casamiza\Http\Controller\ExtraController;
use Casamiza\Http\Controller\HealthController;
use Casamiza\Http\Controller\IntegrationController;
use Casamiza\Http\Controller\MeController;
use Casamiza\Http\Controller\OwnerController;
use Casamiza\Http\Controller\PropertyController;
use Casamiza\Http\Controller\ReservationController;
use Casamiza\Http\Controller\StatementController;
use Casamiza\Http\Controller\SyncController;
use Casamiza\Http\Controller\TeamController;
use Casamiza\Http\Middleware\AuthMiddleware;
use Casamiza\Http\Middleware\CsrfOriginMiddleware;
use Casamiza\Http\Middleware\RoleMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    $app->get('/health', HealthController::class);

    $app->group('/api/v1', function (RouteCollectorProxy $v1): void {
        // Publiques : le cookie de refresh impose le contrôle d'origine (CSRF).
        $v1->group('/auth', function (RouteCollectorProxy $auth): void {
            $auth->post('/login', [AuthController::class, 'login']);
            $auth->post('/refresh', [AuthController::class, 'refresh']);
            $auth->post('/logout', [AuthController::class, 'logout']);
            $auth->post('/activation', [AuthController::class, 'inspectActivation']);
            $auth->post('/activate', [AuthController::class, 'activate']);
        })->add(CsrfOriginMiddleware::class);

        // Authentifiées : le tenant vient du JWT. Restreindre un rôle : ->add(new RoleMiddleware(Role::Admin)).
        $v1->group('', function (RouteCollectorProxy $api): void {
            $api->get('/me', [MeController::class, 'show']);
            $api->patch('/me', [MeController::class, 'update']);

            // Lecture de l'équipe : tous les membres ; modification : administrateurs seulement.
            $api->get('/team', [TeamController::class, 'index']);
            $api->group('/team', function (RouteCollectorProxy $team): void {
                $team->post('', [TeamController::class, 'invite']);
                $team->patch('/{id}', [TeamController::class, 'update']);
                $team->post('/{id}/invitation', [TeamController::class, 'resendInvitation']);
                $team->delete('/{id}', [TeamController::class, 'remove']);
            })->add(new RoleMiddleware(Role::Admin));

            // Propriétaires et logements (importés de Guesty) : tous les membres ; la synchronisation aussi.
            $api->get('/owners', [OwnerController::class, 'index']);
            $api->get('/properties', [PropertyController::class, 'index']);
            $api->post('/sync/guesty', [SyncController::class, 'guesty']);

            // Réservations (importées de Guesty, montants calculés) : tous les membres ; recherche, filtres et pagination côté API.
            $api->get('/reservations', [ReservationController::class, 'index']);

            // Suivi mensuel d'un propriétaire : prestations supplémentaires et statut de facturation, tous les membres.
            $api->get('/owners/{ownerId}/extras', [ExtraController::class, 'index']);
            $api->post('/owners/{ownerId}/extras', [ExtraController::class, 'create']);
            $api->patch('/owners/{ownerId}/extras/{id}', [ExtraController::class, 'update']);
            $api->delete('/owners/{ownerId}/extras/{id}', [ExtraController::class, 'remove']);
            $api->get('/statements', [StatementController::class, 'index']);
            $api->put('/owners/{id}/statements/{month}/nothing-to-invoice', [StatementController::class, 'nothingToInvoice']);

            // Saisies locales du propriétaire (SIRET, raison sociale, clé Stripe en écriture seule) : administrateurs seulement.
            $api->group('/owners', function (RouteCollectorProxy $owners): void {
                $owners->patch('/{id}', [OwnerController::class, 'update']);
                $owners->delete('/{id}/stripe-key', [OwnerController::class, 'removeStripeKey']);
            })->add(new RoleMiddleware(Role::Admin));

            // Identité de l'entreprise (mentions légales, contact, modèles d'e-mail, logo) : administrateurs seulement.
            $api->group('/settings/company', function (RouteCollectorProxy $company): void {
                $company->get('', [CompanyController::class, 'show']);
                $company->patch('', [CompanyController::class, 'update']);
                $company->get('/logo', [CompanyController::class, 'logo']);
                // POST et non PUT : PHP ne remplit `$_FILES` que pour un POST multipart.
                $company->post('/logo', [CompanyController::class, 'uploadLogo']);
                $company->delete('/logo', [CompanyController::class, 'removeLogo']);
            })->add(new RoleMiddleware(Role::Admin));

            // Services tiers (Guesty…) : administrateurs seulement, les secrets ne sont jamais renvoyés.
            $api->group('/settings/integrations', function (RouteCollectorProxy $integrations): void {
                $integrations->get('', [IntegrationController::class, 'index']);
                $integrations->put('/{provider}', [IntegrationController::class, 'save']);
                $integrations->post('/{provider}/check', [IntegrationController::class, 'check']);
                $integrations->delete('/{provider}', [IntegrationController::class, 'remove']);
            })->add(new RoleMiddleware(Role::Admin));
        })->add(AuthMiddleware::class);
    });
};
