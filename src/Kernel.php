<?php

declare(strict_types=1);

namespace Casamiza;

use Casamiza\Http\ErrorHandler;
use Casamiza\Http\Middleware\CorsMiddleware;
use Casamiza\Http\Middleware\RequestIdMiddleware;
use Casamiza\Http\Middleware\SecurityHeadersMiddleware;
use Casamiza\Shared\Settings;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Factory\AppFactory;

/** Assemble l'application : conteneur, middlewares, routes. */
final class Kernel
{
    /**
     * @param array<string, mixed> $overrides définitions du conteneur à substituer (tests)
     * @param bool $compileContainer compile le conteneur en production (impossible avec des instances substituées)
     */
    public static function createContainer(Settings $settings, array $overrides = [], bool $compileContainer = true): ContainerInterface
    {
        $builder = new ContainerBuilder();
        if ($settings->isProd() && $compileContainer) {
            $builder->enableCompilation($settings->projectRoot . '/storage/cache');
        }
        $builder->addDefinitions(require $settings->projectRoot . '/config/container.php');
        $builder->addDefinitions([Settings::class => $settings] + $overrides);

        return $builder->build();
    }

    /**
     * @param array<string, mixed> $overrides définitions du conteneur à substituer (tests)
     * @param bool $compileContainer compile le conteneur en production (impossible avec des instances substituées)
     * @return App<ContainerInterface|null>
     */
    public static function createApp(Settings $settings, array $overrides = [], bool $compileContainer = true): App
    {
        $container = self::createContainer($settings, $overrides, $compileContainer);

        AppFactory::setContainer($container);
        $app = AppFactory::create();

        // Slim exécute les middlewares du dernier ajouté (le plus extérieur) au premier.
        $app->addRoutingMiddleware();
        $app->addBodyParsingMiddleware();
        $errors = $app->addErrorMiddleware(!$settings->isProd(), true, true);
        $errors->setDefaultErrorHandler($container->get(ErrorHandler::class));

        // Ajoutés après le gestionnaire d'erreurs pour que les réponses d'erreur portent aussi CORS et les en-têtes.
        $app->add(CorsMiddleware::class);
        $app->add(SecurityHeadersMiddleware::class);
        $app->add(RequestIdMiddleware::class);

        (require $settings->projectRoot . '/config/routes.php')($app);

        return $app;
    }
}
