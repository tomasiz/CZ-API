<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Reservation\Reservation;
use Casamiza\Domain\Reservation\ReservationPage;
use Casamiza\Domain\Reservation\ReservationQuery;
use Casamiza\Domain\Reservation\ReservationRepositoryInterface;
use Casamiza\Domain\Reservation\ReservationRow;
use Casamiza\Domain\Reservation\ReservationSort;
use Casamiza\Domain\Reservation\ReservationTotals;
use Casamiza\Shared\TenantContext;
use DateTimeImmutable;

/** Réservations en mémoire, bornées au tenant courant comme en base. */
final class InMemoryReservations implements ReservationRepositoryInterface
{
    /** @var array<string, Reservation> clé « tenant|id » */
    public array $rows = [];

    public function __construct(
        private readonly TenantContext $context,
        private readonly ?InMemoryOwners $owners = null,
        private readonly ?InMemoryProperties $properties = null,
    ) {}

    /** Même contrat que la requête SQL (la recherche en MySQL est vérifiée sur la base de dev). */
    public function search(ReservationQuery $query): ReservationPage
    {
        $rows = [];
        foreach ($this->sorted(static fn(Reservation $r): bool => !$r->isArchived()) as $reservation) {
            $owner = $reservation->ownerId === null ? null : $this->owners?->find($reservation->ownerId);
            $property = null;
            foreach ($this->properties?->all() ?? [] as $candidate) {
                if ($candidate->id === $reservation->propertyId) {
                    $property = $candidate;
                }
            }
            $row = new ReservationRow($reservation, $owner->name ?? '', $property->name ?? '');
            if ($this->matches($row, $query, $owner->companyName ?? '', $property->address ?? '')) {
                $rows[] = $row;
            }
        }

        $key = static fn(ReservationRow $r): string|float|int => match ($query->sort) {
            ReservationSort::CheckIn => $r->reservation->checkIn->getTimestamp(),
            ReservationSort::CheckOut => $r->reservation->checkOut->getTimestamp(),
            ReservationSort::Owner => mb_strtolower($r->ownerName),
            ReservationSort::Property => mb_strtolower($r->propertyName),
            ReservationSort::Guest => mb_strtolower($r->reservation->guest),
            ReservationSort::TotalPaid => $r->reservation->totalPayout,
            ReservationSort::ConciergeFee => $r->reservation->conciergeFee,
            ReservationSort::OwnerRevenue => $r->reservation->netOwnerRevenue,
        };
        usort($rows, static fn(ReservationRow $a, ReservationRow $b): int => ($query->descending ? -1 : 1) * ($key($a) <=> $key($b)) ?: $a->reservation->id <=> $b->reservation->id);

        $totals = new ReservationTotals(
            array_sum(array_map(static fn(ReservationRow $r): float => $r->reservation->totalPayout, $rows)),
            array_sum(array_map(static fn(ReservationRow $r): float => $r->reservation->conciergeFee, $rows)),
            array_sum(array_map(static fn(ReservationRow $r): float => $r->reservation->netOwnerRevenue, $rows)),
        );

        return new ReservationPage(array_slice($rows, ($query->page - 1) * $query->perPage, $query->perPage), count($rows), $totals, $query->page, $query->perPage);
    }

    private function matches(ReservationRow $row, ReservationQuery $query, string $companyName, string $propertyAddress): bool
    {
        $r = $row->reservation;
        if (($query->checkOutFrom !== null && $r->checkOut < $query->checkOutFrom) || ($query->checkOutBefore !== null && $r->checkOut >= $query->checkOutBefore)) {
            return false;
        }
        if (($query->ownerIds !== [] && !in_array($r->ownerId, $query->ownerIds, true))
            || ($query->propertyIds !== [] && !in_array($r->propertyId, $query->propertyIds, true))
            || ($query->platforms !== [] && !in_array($r->platform, $query->platforms, true))
            || ($query->flagged !== null && ($r->flags !== []) !== $query->flagged)) {
            return false;
        }
        $haystack = mb_strtolower(implode(' ', [
            $r->guest, $r->platformRef, $r->guestyId, $r->source, $r->platform->value, $row->propertyName, $propertyAddress, $row->ownerName, $companyName,
            $r->checkIn->format('d/m/Y'), $r->checkOut->format('d/m/Y'), $r->checkIn->format('Y-m-d'), $r->checkOut->format('Y-m-d'), $r->nights, $r->guests,
            ...array_map(static fn(float $amount): string => number_format($amount, 2, '.', ''), [$r->totalPayout, $r->channelCommission, $r->paymentFee, $r->touristTax, $r->cleaningFee, $r->netPayout, $r->conciergeFee, $r->netOwnerRevenue]),
        ]));
        foreach ($query->searchTerms() as $term) {
            if (preg_match('/^-?\d+,\d+$/', $term) === 1) {
                $term = str_replace(',', '.', $term);
            }
            if (!str_contains($haystack, mb_strtolower($term))) {
                return false;
            }
        }

        return true;
    }

    public function checkingOutBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->sorted(fn(Reservation $r): bool => !$r->isArchived() && $r->checkOut >= $from && $r->checkOut < $to);
    }

    public function allCheckingOutSince(DateTimeImmutable $from): array
    {
        return $this->sorted(static fn(Reservation $r): bool => $r->checkOut >= $from);
    }

    public function findByGuestyId(string $guestyId): ?Reservation
    {
        return $this->byGuestyId($this->context->id(), $guestyId);
    }

    public function byGuestyId(string $tenantId, string $guestyId): ?Reservation
    {
        foreach ($this->rows as $key => $reservation) {
            if (str_starts_with($key, $tenantId . '|') && $reservation->guestyId === $guestyId) {
                return $reservation;
            }
        }

        return null;
    }

    public function add(Reservation $reservation): void
    {
        $this->rows[$this->context->id() . '|' . $reservation->id] = $reservation;
    }

    public function update(Reservation $reservation): void
    {
        $key = $this->context->id() . '|' . $reservation->id;
        if (isset($this->rows[$key])) {
            $this->rows[$key] = $reservation;
        }
    }

    /**
     * @param callable(Reservation): bool $keep
     * @return list<Reservation>
     */
    private function sorted(callable $keep): array
    {
        $found = array_values(array_filter(
            $this->rows,
            fn(Reservation $r, string $key): bool => str_starts_with($key, $this->context->id() . '|') && $keep($r),
            ARRAY_FILTER_USE_BOTH,
        ));
        usort($found, static fn(Reservation $a, Reservation $b): int => [$a->checkOut, $a->id] <=> [$b->checkOut, $b->id]);

        return $found;
    }
}
