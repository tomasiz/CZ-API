<?php

declare(strict_types=1);

namespace Casamiza\Domain\OwnerStats;

/**
 * Les chiffres d'un logement pour un mois facturé (centimes entiers) ; `propertyId` nul = prestations sans logement.
 * Total payé = plateforme + taxe de séjour + ménage + conciergerie + net propriétaire, au centime.
 */
final readonly class MonthStats
{
    public function __construct(
        public ?string $propertyId,
        public string $month,
        public int $reservations,
        public int $nights,
        public int $ownerNights,
        public int $payoutCents,
        public int $platformFeeCents,
        public int $touristTaxCents,
        public int $cleaningCents,
        public int $conciergeCents,
    ) {}

    /** Ce qui reste au propriétaire, après TVA et prestations. */
    public function ownerNetCents(): int
    {
        return $this->payoutCents - $this->platformFeeCents - $this->touristTaxCents - $this->cleaningCents - $this->conciergeCents;
    }
}
