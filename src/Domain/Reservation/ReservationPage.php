<?php

declare(strict_types=1);

namespace Casamiza\Domain\Reservation;

/** Une page de résultats. */
final readonly class ReservationPage
{
    /** @param list<ReservationRow> $items */
    public function __construct(
        public array $items,
        public int $total,
        public ReservationTotals $totals,
        public int $page,
        public int $perPage,
    ) {}
}
