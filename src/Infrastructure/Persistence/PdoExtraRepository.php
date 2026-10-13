<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Persistence;

use Casamiza\Domain\Extra\Extra;
use Casamiza\Domain\Extra\ExtraRepositoryInterface;
use Casamiza\Domain\Extra\ExtraType;
use DateTimeImmutable;
use DateTimeZone;
use UnexpectedValueException;

final class PdoExtraRepository extends BaseRepository implements ExtraRepositoryInterface
{
    protected function table(): string
    {
        return 'extras';
    }

    public function forOwnerMonth(string $ownerId, string $month): array
    {
        return array_map($this->hydrate(...), $this->select(
            where: 'owner_id = :owner AND month = :month AND deleted_at IS NULL',
            params: ['owner' => $ownerId, 'month' => $month],
            // Identifiants UUID v7 : l'ordre des identifiants est l'ordre de saisie.
            suffix: 'ORDER BY id',
        ));
    }

    public function find(string $id): ?Extra
    {
        $row = $this->selectOne('id = :id AND deleted_at IS NULL', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function add(Extra $extra): void
    {
        $this->insertRow(['id' => $extra->id, 'owner_id' => $extra->ownerId, 'month' => $extra->month] + $this->values($extra));
    }

    public function update(Extra $extra): void
    {
        $this->updateRow($extra->id, $this->values($extra));
    }

    public function remove(string $id): void
    {
        $this->updateRow($id, ['deleted_at' => DbDate::toDb(new DateTimeImmutable('now', new DateTimeZone('UTC')))]);
    }

    /** @return array<string, mixed> */
    private function values(Extra $extra): array
    {
        return [
            'type' => $extra->type->value,
            'description' => $extra->description,
            'price_ttc' => $extra->priceTtc,
            'vat_rate' => $extra->vatRate,
            'price_ht' => $extra->priceHt,
        ];
    }

    /** @param array<array-key, mixed> $row */
    private function hydrate(array $row): Extra
    {
        $id = (string) $row['id'];
        if ($id === '') {
            throw new UnexpectedValueException('Prestation sans identifiant.');
        }

        return new Extra(
            id: $id,
            ownerId: (string) $row['owner_id'],
            month: (string) $row['month'],
            type: ExtraType::from((string) $row['type']),
            description: (string) $row['description'],
            priceTtc: (float) $row['price_ttc'],
            vatRate: (float) $row['vat_rate'],
            priceHt: (float) $row['price_ht'],
        );
    }
}
