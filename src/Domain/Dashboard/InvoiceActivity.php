<?php

declare(strict_types=1);

namespace Casamiza\Domain\Dashboard;

/** Activité figée des réservations d'une facture pour un logement (centimes entiers). */
final readonly class InvoiceActivity
{
    public function __construct(
        public string $invoiceId,
        public string $propertyId,
        public string $month,
        public int $reservations,
        public int $nights,
        public int $payoutCents,
        public int $ownerNetCents,
    ) {}
}
