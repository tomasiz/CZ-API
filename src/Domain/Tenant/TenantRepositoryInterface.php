<?php

declare(strict_types=1);

namespace Casamiza\Domain\Tenant;

use Casamiza\Domain\Integration\Provider;

/** La table `tenants` n'a pas de `tenant_id` : on la lit toujours avec un identifiant déjà vérifié (JWT, session). */
interface TenantRepositoryInterface
{
    public function find(string $id): ?Tenant;

    public function add(Tenant $tenant): void;

    /**
     * Tenants actifs ayant configuré ce service (tâches planifiées, hors de toute requête authentifiée).
     *
     * @return list<string>
     */
    public function idsWithIntegration(Provider $provider): array;
}
