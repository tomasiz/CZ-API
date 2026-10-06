<?php

declare(strict_types=1);

namespace Casamiza\Http\Middleware;

use Casamiza\Shared\Settings;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** CORS avec une seule origine autorisée (le front), cookies inclus. Jamais de `*`. */
final readonly class CorsMiddleware implements MiddlewareInterface
{
    private const METHODS = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
    private const HEADERS = 'Authorization, Content-Type';

    public function __construct(
        private Settings $settings,
        private ResponseFactoryInterface $responses,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        $allowed = $origin !== '' && $origin === $this->settings->corsOrigin;

        $isPreflight = $request->getMethod() === 'OPTIONS'
            && $origin !== ''
            && $request->hasHeader('Access-Control-Request-Method');

        $response = $isPreflight
            ? $this->responses->createResponse(204)
                ->withHeader('Access-Control-Max-Age', '600')
            : $handler->handle($request);

        $response = $response->withAddedHeader('Vary', 'Origin');

        if (!$allowed) {
            return $response;
        }

        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Expose-Headers', 'X-Request-Id');

        if ($isPreflight) {
            $response = $response
                ->withHeader('Access-Control-Allow-Methods', self::METHODS)
                ->withHeader('Access-Control-Allow-Headers', self::HEADERS);
        }

        return $response;
    }
}
