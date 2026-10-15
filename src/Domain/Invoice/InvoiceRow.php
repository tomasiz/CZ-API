<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice;

/** Une facture de la liste, avec le nom de son propriétaire et les logements facturés. */
final readonly class InvoiceRow
{
    /** @param list<string> $propertyNames */
    public function __construct(
        public Invoice $invoice,
        public string $ownerName,
        public array $propertyNames,
    ) {}
}
