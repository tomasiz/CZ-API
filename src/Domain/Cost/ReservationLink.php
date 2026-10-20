<?php

declare(strict_types=1);

namespace Casamiza\Domain\Cost;

use DateTimeImmutable;

/** Réservation de Casamiza retrouvée par son identifiant Guesty, avec son logement. */
final readonly class ReservationLink
{
    public function __construct(
        public string $reservationId,
        public string $propertyId,
        public string $guest,
        public DateTimeImmutable $checkIn,
        public DateTimeImmutable $checkOut,
    ) {}
}
