<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice;

/** Totaux d'une facture, en euros au centime : HT + TVA = TTC. */
final readonly class InvoiceTotals
{
    public function __construct(
        public float $ht,
        public float $vat,
        public float $ttc,
    ) {}
}
