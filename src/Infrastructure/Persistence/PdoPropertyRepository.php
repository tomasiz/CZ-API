<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Persistence;

use Casamiza\Domain\Property\Property;
use Casamiza\Domain\Property\PropertyRepositoryInterface;
use UnexpectedValueException;

final class PdoPropertyRepository extends BaseRepository implements PropertyRepositoryInterface
{
    protected function table(): string
    {
        return 'properties';
    }

    public function all(): array
    {
        return array_map($this->hydrate(...), $this->select(suffix: 'ORDER BY name, id'));
    }

    public function add(Property $property): void
    {
        $this->insertRow(['id' => $property->id, 'guesty_id' => $property->guestyId] + $this->values($property));
    }

    public function update(Property $property): void
    {
        $this->updateRow($property->id, $this->values($property));
    }

    /** @return array<string, mixed> */
    private function values(Property $property): array
    {
        return [
            'owner_id' => $property->ownerId,
            'name' => $property->name,
            'address' => $property->address,
            'cleaning_fee' => $property->cleaningFee,
            'linen_fee' => $property->linenFee,
            'archived_at' => $property->archivedAt === null ? null : DbDate::toDb($property->archivedAt),
        ];
    }

    /** @param array<array-key, mixed> $row */
    private function hydrate(array $row): Property
    {
        $id = (string) $row['id'];
        if ($id === '') {
            throw new UnexpectedValueException('Logement sans identifiant.');
        }

        return new Property(
            id: $id,
            guestyId: (string) $row['guesty_id'],
            ownerId: $row['owner_id'] === null ? null : (string) $row['owner_id'],
            name: (string) $row['name'],
            address: (string) $row['address'],
            cleaningFee: (float) $row['cleaning_fee'],
            linenFee: (float) $row['linen_fee'],
            archivedAt: DbDate::fromDbOrNull($row['archived_at']),
        );
    }
}
