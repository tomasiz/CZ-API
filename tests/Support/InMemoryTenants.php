<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Tenant\Tenant;
use Casamiza\Domain\Tenant\TenantRepositoryInterface;
use Casamiza\Domain\Tenant\TenantStatus;

final class InMemoryTenants implements TenantRepositoryInterface
{
    /** @var array<string, Tenant> */
    public array $tenants = [];

    public function find(string $id): ?Tenant
    {
        return $this->tenants[$id] ?? null;
    }

    public function add(Tenant $tenant): void
    {
        $this->tenants[$tenant->id] = $tenant;
    }

    public function isActive(string $id): bool
    {
        return ($this->tenants[$id]->status ?? null) === TenantStatus::Active;
    }
}
