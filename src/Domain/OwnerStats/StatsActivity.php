<?php

declare(strict_types=1);

namespace Casamiza\Domain\OwnerStats;

/** Activité figée des réservations d'une facture pour un logement et une plateforme (centimes entiers). Les séjours propriétaire n'y figurent pas. */
final readonly class StatsActivity
{
    public function __construct(
        public string $invoiceId,
        public string $propertyId,
        public string $month,
        public string $platform,
        public int $reservations,
        public int $nights,
        public int $payoutCents,
        /** Commission de la plateforme + frais de paiement */
        public int $platformFeeCents,
        public int $touristTaxCents,
    ) {}
}
