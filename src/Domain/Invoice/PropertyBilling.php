<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice;

use Casamiza\Domain\Property\Property;
use Casamiza\Domain\Reservation\Reservation;

/** Un logement du propriétaire et ses réservations du mois (séjours propriétaire exclus), dans l'ordre d'impression. */
final readonly class PropertyBilling
{
    /** @param list<Reservation> $reservations */
    public function __construct(
        public Property $property,
        public array $reservations,
    ) {}
}
