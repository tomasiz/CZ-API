<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice\Document;

/** Ligne de titre d'une rubrique (nom d'un logement, « Prestations supplémentaires »). */
final readonly class InvoiceHeadingRow
{
    public function __construct(public string $text) {}
}
