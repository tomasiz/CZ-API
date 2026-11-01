<?php

declare(strict_types=1);

namespace Casamiza\Domain\OwnerStats;

/** Nuits d'un séjour propriétaire, rattachées au mois de son check-out. */
final readonly class OwnerNights
{
    public function __construct(
        public string $propertyId,
        public string $month,
        public int $nights,
    ) {}
}
