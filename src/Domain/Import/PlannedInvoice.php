<?php

declare(strict_types=1);

namespace Casamiza\Domain\Import;

use Casamiza\Domain\Invoice\DraftLine;
use Casamiza\Domain\Invoice\DraftReservation;
use Casamiza\Domain\Invoice\Invoice;

/** Une facture de Facturiza prête à être écrite : en-tête, lignes, activité des réservations et PDF à copier. */
final readonly class PlannedInvoice
{
    /**
     * @param list<DraftLine> $lines
     * @param list<DraftReservation> $reservations
     * @param array<string, array{string, string}> $files `invoice` et/ou `recap` : [chemin source, année de création]
     */
    public function __construct(
        public Invoice $invoice,
        public array $lines,
        public array $reservations,
        public array $files,
    ) {}
}
