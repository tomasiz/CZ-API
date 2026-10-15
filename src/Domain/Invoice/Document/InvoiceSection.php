<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice\Document;

/** Une rubrique de la facture (« Frais de conciergerie », « Frais de linge », « Frais de ménage »), imprimée même vide. */
final readonly class InvoiceSection
{
    /** @param list<InvoiceHeadingRow|InvoiceItemRow> $rows */
    public function __construct(
        public string $title,
        public array $rows,
    ) {}
}
