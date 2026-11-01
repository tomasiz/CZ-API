<?php

declare(strict_types=1);

namespace Casamiza\Domain\OwnerStats;

/** Montant TTC facturé d'un poste sur une facture ; `propertyId` est nul pour une prestation. */
final readonly class StatsBilled
{
    public function __construct(
        public string $invoiceId,
        public ?string $propertyId,
        public string $month,
        public StatsPoste $poste,
        public int $ttcCents,
    ) {}
}
