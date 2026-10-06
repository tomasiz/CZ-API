<?php

declare(strict_types=1);

namespace Casamiza\Shared;

use Casamiza\Shared\Exception\UnauthorizedException;
use LogicException;

/**
 * Tenant de la requête en cours. Renseigné une seule fois par le middleware d'authentification
 * à partir du claim `tid` du JWT : jamais depuis un paramètre, un en-tête ou le corps de la requête.
 */
final class TenantContext
{
    private ?string $tenantId = null;

    public function set(string $tenantId): void
    {
        if ($this->tenantId !== null) {
            throw new LogicException('Request tenant is already set.');
        }
        $this->tenantId = $tenantId;
    }

    /** Appelé au tout début de chaque requête (RequestIdMiddleware) : un processus durable ne doit rien garder d'une requête à l'autre. */
    public function reset(): void
    {
        $this->tenantId = null;
    }

    public function idOrNull(): ?string
    {
        return $this->tenantId;
    }

    public function id(): string
    {
        return $this->tenantId ?? throw new UnauthorizedException();
    }
}
