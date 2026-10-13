<?php

declare(strict_types=1);

namespace Casamiza\Domain\Reservation;

/** Lecture des réservations du tenant courant (alimentées par la synchronisation Guesty). */
final readonly class ReservationService
{
    public function __construct(private ReservationRepositoryInterface $reservations) {}

    public function search(ReservationQuery $query): ReservationPage
    {
        return $this->reservations->search($query);
    }
}
