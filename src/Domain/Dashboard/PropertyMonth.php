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
        /** Abonnement Guesty du logement et du mois (factures importées, converties en euros) */
        public int $guestyCostCents,
        /** Frais Beyond Pricing du logement et du mois du check-out */
        public int $beyondCostCents,
    ) {}

    public function softwareCostCents(): int
    {
        return $this->guestyCostCents + $this->beyondCostCents;
    }
}
