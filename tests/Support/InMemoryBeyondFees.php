<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Cost\BeyondFeeItem;
use Casamiza\Domain\Cost\BeyondFeeRepositoryInterface;
use Casamiza\Domain\Cost\ReservationLink;
use Casamiza\Shared\TenantContext;

/** Lignes de relevé Beyond en mémoire, bornées au tenant courant comme en base ; `reservationFees` reproduit `reservations.beyond_fee`. */
final class InMemoryBeyondFees implements BeyondFeeRepositoryInterface
{
    /** @var array<string, BeyondFeeItem> clé « tenant|identifiant Beyond de la ligne » */
    public array $items = [];

    /** @var array<string, int> clé « tenant|réservation » => somme des lignes, en centimes */
    public array $reservationFees = [];

    public function __construct(
        private readonly TenantContext $context,
        private readonly InMemoryReservations $reservations,
    ) {}

    public function statementIdsOfItems(array $statementItemIds): array
    {
        $found = [];
        foreach ($statementItemIds as $id) {
            $item = $this->items[$this->key($id)] ?? null;
            if ($item !== null) {
                $found[$id] = $item->statementId;
            }
        }

        return $found;
    }

    public function itemIdsOfStatement(string $statementId): array
    {
        $prefix = $this->context->id() . '|';

        return array_values(array_map(
            static fn(BeyondFeeItem $item): int => $item->line->statementItemId,
            array_filter($this->items, static fn(BeyondFeeItem $i, string $key): bool => str_starts_with($key, $prefix) && $i->statementId === $statementId, ARRAY_FILTER_USE_BOTH),
        ));
    }

    public function reservationsByGuestyId(array $guestyIds): array
    {
        $links = [];
        foreach ($guestyIds as $guestyId) {
            $reservation = $this->reservations->findByGuestyId($guestyId);
            if ($reservation !== null) {
                $links[$guestyId] = new ReservationLink($reservation->id, $reservation->propertyId, $reservation->guest, $reservation->checkIn, $reservation->checkOut);
            }
        }

        return $links;
    }

    public function addItems(array $items): void
    {
        foreach ($items as $item) {
            $this->items[$this->key($item->line->statementItemId)] = $item;
        }
    }

    public function recomputeReservationFees(array $reservationIds): void
    {
        $tenant = $this->context->id();
        foreach ($reservationIds as $reservationId) {
            $sum = 0;
            foreach ($this->items as $key => $item) {
                if (str_starts_with($key, $tenant . '|') && $item->reservationId === $reservationId) {
                    $sum += $item->line->amountCents;
                }
            }
            $this->reservationFees[$tenant . '|' . $reservationId] = $sum;
        }
    }

    public function count(): int
    {
        $prefix = $this->context->id() . '|';

        return count(array_filter($this->items, static fn(string $key): bool => str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY));
    }

    /** Frais portés par une réservation du tenant courant (centimes), 0 si rien. */
    public function feeOf(string $reservationId): int
    {
        return $this->reservationFees[$this->context->id() . '|' . $reservationId] ?? 0;
    }

    private function key(int $statementItemId): string
    {
        return $this->context->id() . '|' . $statementItemId;
    }
}
