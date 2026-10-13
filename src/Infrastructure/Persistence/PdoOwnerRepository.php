<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Persistence;

use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerRepositoryInterface;
use Casamiza\Domain\Owner\OwnerType;
use UnexpectedValueException;

final class PdoOwnerRepository extends BaseRepository implements OwnerRepositoryInterface
{
    protected function table(): string
    {
        return 'owners';
    }

    public function all(): array
    {
        return array_map($this->hydrate(...), $this->select(suffix: 'ORDER BY name, id'));
    }

    public function add(Owner $owner): void
    {
        $this->insertRow(['id' => $owner->id, 'guesty_id' => $owner->guestyId] + $this->values($owner));
    }

    public function update(Owner $owner): void
    {
        $this->updateRow($owner->id, $this->values($owner));
    }

    /** @return array<string, mixed> */
    private function values(Owner $owner): array
    {
        return [
            'name' => $owner->name,
            'email' => $owner->email,
            'phone' => $owner->phone,
            'address' => $owner->address,
            'type' => $owner->type->value,
            'company_name' => $owner->companyName,
            'siret' => $owner->siret,
            'commission' => $owner->commission,
            'archived_at' => $owner->archivedAt === null ? null : DbDate::toDb($owner->archivedAt),
        ];
    }

    /** @param array<array-key, mixed> $row */
    private function hydrate(array $row): Owner
    {
        $id = (string) $row['id'];
        if ($id === '') {
            throw new UnexpectedValueException('Propriétaire sans identifiant.');
        }

        return new Owner(
            id: $id,
            guestyId: (string) $row['guesty_id'],
            name: (string) $row['name'],
            email: (string) $row['email'],
            phone: (string) $row['phone'],
            address: (string) $row['address'],
            type: OwnerType::from((string) $row['type']),
            companyName: (string) $row['company_name'],
            siret: (string) $row['siret'],
            commission: $row['commission'] === null ? null : (float) $row['commission'],
            archivedAt: DbDate::fromDbOrNull($row['archived_at']),
        );
    }
}
