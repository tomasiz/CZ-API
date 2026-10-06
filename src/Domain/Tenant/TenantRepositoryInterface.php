<?php

declare(strict_types=1);

namespace Casamiza\Domain\Tenant;

/** La table `tenants` n'a pas de `tenant_id` : on la lit toujours avec un identifiant déjà vérifié (JWT, session). */
interface TenantRepositoryInterface
{
    public function find(string $id): ?Tenant;

    public function add(Tenant $tenant): void;
}
