<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice\Document;

/** Une ligne du « Détail de la TVA » : code (« Normale »…), base HT, taux (en %) et montant de TVA. */
final readonly class VatBreakdown
{
    public function __construct(
        public string $code,
        public float $baseHt,
        public float $rate,
        public float $vat,
    ) {}
}
