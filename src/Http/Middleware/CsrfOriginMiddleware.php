<?php

declare(strict_types=1);

namespace Casamiza\Http\Middleware;

use Casamiza\Shared\Exception\ForbiddenException;
use Casamiza\Shared\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Protection CSRF des routes qui s'appuient sur un cookie (refresh, logout) : une requête qui modifie
 * l'état doit venir de l'origine du front. Les navigateurs envoient toujours `Origin` sur ces requêtes.
 */
final readonly class CsrfOriginMiddleware implements MiddlewareInterface
{
    public function __construct(private Settings $settings) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        $safe = in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true);

        if (!$safe && $origin !== '' && $origin !== $this->settings->corsOrigin) {
            throw new ForbiddenException('forbidden_origin');
        }

        return $handler->handle($request);
    }
}
