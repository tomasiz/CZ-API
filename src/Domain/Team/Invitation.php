<?php

declare(strict_types=1);

namespace Casamiza\Domain\Team;

use Casamiza\Domain\User\User;
use DateTimeImmutable;

/** Invitation émise : le membre, le jeton en clair (affiché une seule fois à l'administrateur) et sa date d'expiration. */
final readonly class Invitation
{
    public function __construct(
        public User $user,
        public string $token,
        public DateTimeImmutable $expiresAt,
    ) {}
}
