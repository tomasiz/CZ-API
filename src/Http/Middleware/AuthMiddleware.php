<?php

declare(strict_types=1);

namespace Casamiza\Http\Middleware;

use Casamiza\Domain\Auth\AccessClaims;
use Casamiza\Infrastructure\Security\JwtService;
use Casamiza\Shared\Exception\UnauthorizedException;
use Casamiza\Shared\TenantContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Vérifie le JWT `Authorization: Bearer` et fixe le tenant de la requête à partir de son claim `tid`.
 * C'est la seule porte d'entrée du tenant : aucun paramètre, en-tête ni corps ne peut le définir.
 */
final readonly class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private JwtService $jwt, private TenantContext $tenant) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer (\S+)$/', $header, $matches)) {
            throw new UnauthorizedException('unauthenticated');
        }

        $claims = $this->jwt->parse($matches[1]);
        $this->tenant->set($claims->tenantId);

        return $handler->handle($request->withAttribute(AccessClaims::class, $claims));
    }
}
