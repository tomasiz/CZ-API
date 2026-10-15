<?php

declare(strict_types=1);

namespace Casamiza\Domain\Dashboard;

use Casamiza\Domain\Extra\ExtraType;

/** Montants facturés d'une catégorie sur une facture (centimes entiers) ; `propertyId` est nul pour les prestations. */
final readonly class BilledAmount
{
    public function __construct(
        public string $invoiceId,
        public string $ownerId,
        public ?string $propertyId,
        public string $month,
        public ExtraType $category,
        public int $ttcCents,
        public int $htCents,
    ) {}
}
