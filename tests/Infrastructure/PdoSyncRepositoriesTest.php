<?php

declare(strict_types=1);

namespace Casamiza\Tests\Infrastructure;

use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerType;
use Casamiza\Domain\Property\Property;
use Casamiza\Infrastructure\Persistence\Database;
use Casamiza\Infrastructure\Persistence\PdoOwnerRepository;
use Casamiza\Infrastructure\Persistence\PdoPropertyRepository;
use Casamiza\Shared\TenantContext;
use Casamiza\Tests\AppTestCase;
use DateTimeImmutable;
use PDO;

/** Les vrais dépôts SQL (sur SQLite) : aller-retour des champs et isolation entre tenants. */
final class PdoSyncRepositoriesTest extends AppTestCase
{
    private Database $database;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec("CREATE TABLE owners (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL, guesty_id TEXT NOT NULL, name TEXT NOT NULL, email TEXT NOT NULL DEFAULT '', phone TEXT NOT NULL DEFAULT '', address TEXT NOT NULL DEFAULT '', type TEXT NOT NULL DEFAULT 'individual', company_name TEXT NOT NULL DEFAULT '', siret TEXT NOT NULL DEFAULT '', commission NUMERIC NULL, archived_at TEXT NULL, UNIQUE (tenant_id, guesty_id))");
        $pdo->exec("CREATE TABLE properties (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL, guesty_id TEXT NOT NULL, owner_id TEXT NULL, name TEXT NOT NULL, address TEXT NOT NULL DEFAULT '', cleaning_fee NUMERIC NOT NULL DEFAULT 0, linen_fee NUMERIC NOT NULL DEFAULT 0, archived_at TEXT NULL, UNIQUE (tenant_id, guesty_id))");
        $this->database = new Database($this->settings(), $pdo);
    }

    private function context(string $tenantId): TenantContext
    {
        $context = new TenantContext();
        $context->set($tenantId);

        return $context;
    }

    /** @param non-empty-string $id */
    private function owner(string $id, string $guestyId = 'g-1', ?DateTimeImmutable $archivedAt = null): Owner
    {
        return new Owner($id, $guestyId, 'P - Alice', 'alice@example.test', '+33600000000', '1 rue du Port', OwnerType::Company, 'SCI Alice', '12345678901234', 18.5, $archivedAt);
    }

    public function testOwnersRoundTripAndAreIsolatedByTenant(): void
    {
        $a = new PdoOwnerRepository($this->database, $this->context('tenant-a'));
        $b = new PdoOwnerRepository($this->database, $this->context('tenant-b'));
        $archived = new DateTimeImmutable('2026-10-12 03:00:00 UTC');

        $a->add($this->owner('owner-a'));
        // Même identifiant Guesty chez un autre tenant : autorisé, lignes distinctes.
        $b->add($this->owner('owner-b'));

        self::assertEquals($this->owner('owner-a'), $a->all()[0]);
        self::assertCount(1, $b->all());

        $a->update($this->owner('owner-a', archivedAt: $archived));
        $b->update($this->owner('owner-a'));   // l'id d'un autre tenant : rien ne bouge chez A

        self::assertEquals($archived, $a->all()[0]->archivedAt);
        self::assertSame('owner-b', $b->all()[0]->id);
    }

    public function testPropertiesRoundTripAndAreIsolatedByTenant(): void
    {
        $a = new PdoPropertyRepository($this->database, $this->context('tenant-a'));
        $b = new PdoPropertyRepository($this->database, $this->context('tenant-b'));

        $a->add(new Property('prop-a', 'l-1', 'owner-a', 'Villa', '2 quai', 83.0, 25.5, null));
        $a->add(new Property('prop-a2', 'l-2', null, 'Orphelin', '', 0.0, 0.0, null));

        self::assertSame([], $b->all());
        $rows = $a->all();
        self::assertCount(2, $rows);
        self::assertSame('owner-a', $rows[1]->ownerId); // ORDER BY name : « Orphelin » puis « Villa »
        self::assertNull($rows[0]->ownerId);
        self::assertSame(25.5, $rows[1]->linenFee);

        $b->update(new Property('prop-a', 'l-1', null, 'Pirate', '', 0.0, 0.0, null));
        self::assertSame('Villa', $a->all()[1]->name);
    }
}
