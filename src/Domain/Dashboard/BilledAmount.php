<?php

declare(strict_types=1);

namespace Casamiza\Domain\Dashboard;

/** Montants facturés d'une catégorie du tableau de bord sur une facture (centimes entiers) ; `propertyId` est nul pour les prestations. */
final readonly class BilledAmount
{
    public function __construct(
        public string $invoiceId,
        public string $ownerId,
        public ?string $propertyId,
        public string $month,
        public BilledCategory $category,
        public int $ttcCents,
        public int $htCents,
    ) {}
}
