<?php

declare(strict_types=1);

namespace Casamiza\Tests\Infrastructure;

use Casamiza\Infrastructure\Persistence\BaseRepository;

/** Repository minimal pour tester le socle BaseRepository. */
final class OwnerTestRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'owners';
    }

    /** @return list<array<array-key, mixed>> */
    public function all(): array
    {
        return $this->select(suffix: 'ORDER BY id');
    }

    /** @return array<array-key, mixed>|null */
    public function find(string $id): ?array
    {
        return $this->findRow($id);
    }

    public function add(string $id, string $name): void
    {
        $this->insertRow(['id' => $id, 'name' => $name]);
    }

    public function rename(string $id, string $name): int
    {
        return $this->updateRow($id, ['name' => $name]);
    }

    public function remove(string $id): int
    {
        return $this->deleteRow($id);
    }

    public function badInsert(): void
    {
        $this->insertRow(['id' => 'x', 'name) VALUES (1); --' => 'x']);
    }
}
