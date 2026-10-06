<?php

declare(strict_types=1);

namespace Casamiza\Domain\Auth;

use Casamiza\Domain\Tenant\Tenant;
use Casamiza\Domain\User\User;
use DateTimeImmutable;

/** Résultat d'un login ou d'un refresh : jeton d'accès (corps) et refresh token (cookie). */
final readonly class AuthSession
{
    public function __construct(
        public string $accessToken,
        public int $accessExpiresIn,
        public string $refreshToken,
        public DateTimeImmutable $refreshExpiresAt,
        public User $user,
        public Tenant $tenant,
    ) {}
}
