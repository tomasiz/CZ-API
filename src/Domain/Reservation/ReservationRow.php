<?php

declare(strict_types=1);

namespace Casamiza\Domain\Reservation;

/** Une réservation avec les noms du propriétaire et du logement, pour l'affichage en liste. */
final readonly class ReservationRow
{
    public function __construct(
        public Reservation $reservation,
        /** Vide si le logement n'a pas de propriétaire dans Guesty. */
        public string $ownerName,
        public string $propertyName,
    ) {}
}
