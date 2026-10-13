<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Persistence;

use Casamiza\Domain\Integration\Provider;
use Casamiza\Domain\Tenant\Tenant;
use Casamiza\Domain\Tenant\TenantRepositoryInterface;
use Casamiza\Domain\Tenant\TenantStatus;

final readonly class PdoTenantRepository implements TenantRepositoryInterface
{
    public function __construct(private Database $database) {}

    public function find(string $id): ?Tenant
    {
        $statement = $this->database->pdo()->prepare('SELECT id, name, status FROM tenants WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return new Tenant((string) $row['id'], (string) $row['name'], TenantStatus::from((string) $row['status']));
    }

    public function add(Tenant $tenant): void
    {
        $this->database->pdo()
            ->prepare('INSERT INTO tenants (id, name, status) VALUES (:id, :name, :status)')
            ->execute(['id' => $tenant->id, 'name' => $tenant->name, 'status' => $tenant->status->value]);
    }

    public function idsWithIntegration(Provider $provider): array
    {
        $statement = $this->database->pdo()->prepare(
            "SELECT t.id FROM tenants t
             JOIN integrations i ON i.tenant_id = t.id AND i.provider = :provider
             WHERE t.status = 'active' ORDER BY t.id",
        );
        $statement->execute(['provider' => $provider->value]);

        return array_values(array_map(strval(...), $statement->fetchAll(\PDO::FETCH_COLUMN)));
    }
}
