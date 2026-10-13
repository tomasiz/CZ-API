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

    public function find(string $id): ?Owner
    {
        $row = $this->findRow($id);

        return $row === null ? null : $this->hydrate($row);
    }

    public function add(Owner $owner): void
    {
        $this->insertRow(['id' => $owner->id, 'guesty_id' => $owner->guestyId] + $this->guestyValues($owner) + $this->localValues($owner));
    }

    public function update(Owner $owner): void
    {
        $this->updateRow($owner->id, $this->guestyValues($owner));
    }

    public function updateLocal(Owner $owner): void
    {
        $this->updateRow($owner->id, $this->localValues($owner));
    }

    /** @return array<string, mixed> */
    private function guestyValues(Owner $owner): array
    {
        return [
            'name' => $owner->name,
            'first_name' => $owner->firstName,
            'last_name' => $owner->lastName,
            'email' => $owner->email,
            'phone' => $owner->phone,
            'address' => $owner->address,
            'type' => $owner->type->value,
            'commission' => $owner->commission,
            'archived_at' => $owner->archivedAt === null ? null : DbDate::toDb($owner->archivedAt),
        ];
    }

    /** @return array<string, mixed> */
    private function localValues(Owner $owner): array
    {
        return [
            'company_name' => $owner->companyName,
            'siret' => $owner->siret,
            'stripe_secret_encrypted' => $owner->stripeSecretEncrypted,
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
            firstName: (string) $row['first_name'],
            lastName: (string) $row['last_name'],
            email: (string) $row['email'],
            phone: (string) $row['phone'],
            address: (string) $row['address'],
            type: OwnerType::from((string) $row['type']),
            companyName: (string) $row['company_name'],
            siret: (string) $row['siret'],
            commission: $row['commission'] === null ? null : (float) $row['commission'],
            stripeSecretEncrypted: $row['stripe_secret_encrypted'] === null ? null : (string) $row['stripe_secret_encrypted'],
            archivedAt: DbDate::fromDbOrNull($row['archived_at']),
        );
    }
}
