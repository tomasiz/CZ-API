<?php

declare(strict_types=1);

namespace Casamiza\Tests\Infrastructure;

use Casamiza\Infrastructure\Persistence\Database;
use Casamiza\Shared\Exception\UnauthorizedException;
use Casamiza\Shared\TenantContext;
use Casamiza\Tests\AppTestCase;
use InvalidArgumentException;
use PDO;

/** Isolation multi-tenant : un tenant ne voit, ne modifie et ne supprime jamais les lignes d'un autre. */
final class BaseRepositoryTest extends AppTestCase
{
    private Database $database;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('CREATE TABLE owners (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL, name TEXT NOT NULL)');
        $this->database = new Database($this->settings(), $pdo);
    }

    private function repository(string $tenantId): OwnerTestRepository
    {
        $tenant = new TenantContext();
        $tenant->set($tenantId);

        return new OwnerTestRepository($this->database, $tenant);
    }

    public function testTenantsAreIsolated(): void
    {
        $a = $this->repository('tenant-a');
        $b = $this->repository('tenant-b');
        $a->add('1', 'Alice');
        $b->add('2', 'Bob');

        self::assertCount(1, $a->all());
        self::assertSame('Alice', $a->all()[0]['name']);
        self::assertNull($a->find('2'));
        self::assertSame(0, $a->rename('2', 'Hack'));
        self::assertSame(0, $a->remove('2'));
        self::assertSame('Bob', $b->find('2')['name'] ?? null);
    }

    public function testTenantIdCannotBeOverriddenOnInsert(): void
    {
        $a = $this->repository('tenant-a');
        $a->add('1', 'Alice');

        $statement = $this->database->pdo()->query('SELECT tenant_id FROM owners');
        self::assertNotFalse($statement);
        self::assertSame(['tenant-a'], $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testInvalidColumnNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repository('tenant-a')->badInsert();
    }

    public function testNoTenantMeansNoQuery(): void
    {
        $repository = new OwnerTestRepository($this->database, new TenantContext());

        $this->expectException(UnauthorizedException::class);
        $repository->all();
    }
}
