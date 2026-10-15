<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice;

/** Résultat du calcul d'une facture, avant enregistrement : lignes facturées, activité des réservations et totaux. */
final readonly class InvoiceDraft
{
    /**
     * @param list<DraftLine> $lines
     * @param list<DraftReservation> $reservations
     */
    public function __construct(
        public array $lines,
        public array $reservations,
        public InvoiceTotals $totals,
    ) {}
}
