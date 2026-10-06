<?php

declare(strict_types=1);

namespace Casamiza\Http\Middleware;

use Casamiza\Shared\RequestContext;
use Casamiza\Shared\TenantContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Premier middleware : remet à zéro le contexte de la requête, attribue un identifiant (toujours généré côté serveur)
 * et le renvoie dans X-Request-Id.
 */
final readonly class RequestIdMiddleware implements MiddlewareInterface
{
    public function __construct(private RequestContext $context, private TenantContext $tenant) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->tenant->reset();
        $requestId = bin2hex(random_bytes(8));
        $this->context->setRequestId($requestId);

        return $handler->handle($request->withAttribute('request_id', $requestId))
            ->withHeader('X-Request-Id', $requestId);
    }
}
