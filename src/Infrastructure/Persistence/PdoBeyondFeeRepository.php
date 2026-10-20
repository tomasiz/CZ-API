<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Persistence;

use Casamiza\Domain\Cost\BeyondFeeRepositoryInterface;
use Casamiza\Domain\Cost\ReservationLink;

final class PdoBeyondFeeRepository extends BaseRepository implements BeyondFeeRepositoryInterface
{
    protected function table(): string
    {
        return 'beyond_fee_items';
    }

    public function statementIdsOfItems(array $statementItemIds): array
    {
        $found = [];
        foreach (array_chunk($statementItemIds, 500) as $chunk) {
            $params = [];
            $in = $this->inList('statement_item_id', 'id', $chunk, $params);
            foreach ($this->select('statement_item_id, statement_id', $in, $params) as $row) {
                $found[(int) $row['statement_item_id']] = (string) $row['statement_id'];
            }
        }

        return $found;
    }

    public function itemIdsOfStatement(string $statementId): array
    {
        return array_map(
            static fn(array $row): int => (int) $row['statement_item_id'],
            $this->select('statement_item_id', 'statement_id = :statement', ['statement' => $statementId]),
        );
    }

    public function reservationsByGuestyId(array $guestyIds): array
    {
        $links = [];
        foreach (array_chunk($guestyIds, 500) as $chunk) {
            $params = [];
            $in = $this->inList('guesty_id', 'g', $chunk, $params);
            $statement = $this->pdo()->prepare("SELECT id, guesty_id, property_id, guest, check_in, check_out FROM reservations WHERE tenant_id = :__tenant AND ({$in})");
            $statement->execute($params + ['__tenant' => $this->tenant->id()]);
            foreach ($statement->fetchAll() as $row) {
                $links[(string) $row['guesty_id']] = new ReservationLink(
                    (string) $row['id'],
                    (string) $row['property_id'],
                    (string) $row['guest'],
                    DbDate::fromDb($row['check_in']),
                    DbDate::fromDb($row['check_out']),
                );
            }
        }

        return $links;
    }

    public function addItems(array $items): void
    {
        foreach ($items as $item) {
            $line = $item->line;
            $this->insertRow([
                'id' => $item->id,
                'statement_id' => $item->statementId,
                'statement_item_id' => $line->statementItemId,
                'pms_reservation_id' => $line->pmsReservationId,
                'reservation_id' => $item->reservationId,
                'property_id' => $item->propertyId,
                'listing_title' => $line->listingTitle,
                'kind' => $line->kind->value,
                'product' => $line->product,
                'booking_date' => $line->bookingDate->format('Y-m-d'),
                'updated_on' => $line->updatedOn?->format('Y-m-d'),
                'check_in' => $line->checkIn->format('Y-m-d'),
                'nights' => $line->nights,
                'currency' => 'EUR',
                'earnings' => self::decimal($line->earningsCents),
                'amount' => self::decimal($line->amountCents),
                'imported_at' => DbDate::toDb($item->importedAt),
            ]);
        }
    }

    public function recomputeReservationFees(array $reservationIds): void
    {
        foreach (array_chunk($reservationIds, 500) as $chunk) {
            $params = [];
            $in = $this->inList('r.id', 'r', $chunk, $params);
            $statement = $this->pdo()->prepare(
                "UPDATE reservations r
                 SET r.beyond_fee = COALESCE((SELECT SUM(i.amount) FROM beyond_fee_items i WHERE i.tenant_id = r.tenant_id AND i.reservation_id = r.id), 0)
                 WHERE r.tenant_id = :__tenant AND ({$in})",
            );
            $statement->execute($params + ['__tenant' => $this->tenant->id()]);
        }
    }

    /**
     * @param list<int|string> $values
     * @param array<string, mixed> $params
     */
    private function inList(string $column, string $prefix, array $values, array &$params): string
    {
        if ($values === []) {
            return '1 = 0';
        }
        $names = [];
        foreach ($values as $i => $value) {
            $names[] = ":{$prefix}{$i}";
            $params["{$prefix}{$i}"] = $value;
        }

        return "{$column} IN (" . implode(', ', $names) . ')';
    }

    /** Centimes signés en « -12.34 » (jamais de flottant). */
    private static function decimal(int $cents): string
    {
        return sprintf('%s%d.%02d', $cents < 0 ? '-' : '', intdiv(abs($cents), 100), abs($cents) % 100);
    }
}
