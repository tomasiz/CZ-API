<?php

declare(strict_types=1);

use Casamiza\Domain\User\Role;
use Casamiza\Http\Controller\AuthController;
use Casamiza\Http\Controller\HealthController;
use Casamiza\Http\Controller\MeController;
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
                $team->delete('/{id}', [TeamController::class, 'remove']);
            })->add(new RoleMiddleware(Role::Admin));
        })->add(AuthMiddleware::class);
    });
};
