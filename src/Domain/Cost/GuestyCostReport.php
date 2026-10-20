<?php

declare(strict_types=1);

namespace Casamiza\Domain\Cost;

use DateTimeImmutable;

/** Bilan d'un import de facture Guesty (ou de sa simulation), et détail d'une facture importée. */
final readonly class GuestyCostReport
{
    /** @param list<GuestyCostRow> $rows */
    public function __construct(
        public bool $applied,
        public string $number,
        public DateTimeImmutable $invoiceDate,
        public string $rate,
        public int $linesRead,
        public int $amountUsdCents,
        /** Lignes comptées seulement, en euros */
        public int $amountCents,
        /** La facture était déjà importée : ses lignes sont remplacées */
        public bool $replaced,
        public array $rows,
    ) {}

    /** @return list<GuestyCostRow> lignes qu'aucun logement ne porte */
    public function unmatched(): array
    {
        return array_values(array_filter($this->rows, static fn(GuestyCostRow $r): bool => $r->item->propertyId === null && $r->item->counted));
    }

    /** @return list<GuestyCostRow> lignes déjà portées par une autre facture */
    public function duplicates(): array
    {
        return array_values(array_filter($this->rows, static fn(GuestyCostRow $r): bool => !$r->item->counted));
    }
}
