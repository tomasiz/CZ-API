<?php

declare(strict_types=1);

namespace Casamiza\Domain\Auth;

use Casamiza\Domain\User\Role;

/** Contenu vérifié d'un access token : seule source du tenant et du rôle pendant une requête. */
final readonly class AccessClaims
{
    public function __construct(
        public string $userId,
        public string $tenantId,
        public Role $role,
    ) {}
}
