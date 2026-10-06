<?php

declare(strict_types=1);

namespace Casamiza\Http\Middleware;

use Casamiza\Domain\Auth\AccessClaims;
use Casamiza\Domain\User\Role;
use Casamiza\Shared\Exception\ForbiddenException;
use Casamiza\Shared\Exception\UnauthorizedException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Restreint une route à certains rôles : `->add(new RoleMiddleware(Role::Admin))`, après AuthMiddleware. */
final readonly class RoleMiddleware implements MiddlewareInterface
{
    /** @var list<Role> */
    private array $allowed;

    public function __construct(Role ...$allowed)
    {
        $this->allowed = array_values($allowed);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $claims = $request->getAttribute(AccessClaims::class);
        if (!$claims instanceof AccessClaims) {
            throw new UnauthorizedException('unauthenticated');
        }
        if (!in_array($claims->role, $this->allowed, true)) {
            throw new ForbiddenException();
        }

        return $handler->handle($request);
    }
}
