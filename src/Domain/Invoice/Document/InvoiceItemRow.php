<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice\Document;

/** Ligne imprimée d'une facture : libellé, quantité, prix unitaire HT, taux de TVA (en %), prix unitaire TTC et montant TTC. */
final readonly class InvoiceItemRow
{
    public function __construct(
        public string $label,
        public int $quantity,
        public float $unitHt,
        public float $vatRate,
        public float $unitTtc,
        public float $totalTtc,
    ) {}
}
