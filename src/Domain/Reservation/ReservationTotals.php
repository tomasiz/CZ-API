<?php

declare(strict_types=1);

namespace Casamiza\Domain\Reservation;

/** Totaux de **toutes** les réservations correspondant à la recherche (pas seulement de la page affichée). */
final readonly class ReservationTotals
{
    public function __construct(
        public float $totalPayout = 0.0,
        public float $conciergeFee = 0.0,
        public float $netOwnerRevenue = 0.0,
    ) {}
}
