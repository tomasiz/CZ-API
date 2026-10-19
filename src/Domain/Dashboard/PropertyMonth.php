<?php

declare(strict_types=1);

namespace Casamiza\Domain\Dashboard;

/** Les chiffres d'un logement pour un mois facturé (centimes entiers) ; `propertyId` nul = prestations sans logement. */
final readonly class PropertyMonth
{
    public function __construct(
        public ?string $propertyId,
        public string $month,
        public int $reservations,
        public int $nights,
        public int $payoutCents,
        public int $ownerNetCents,
        public int $conciergeHtCents,
        public int $conciergeTtcCents,
        public int $linenHtCents,
        public int $linenTtcCents,
        public int $disbursementHtCents,
        public int $disbursementTtcCents,
        public int $softwareCostCents,
    ) {}
}
