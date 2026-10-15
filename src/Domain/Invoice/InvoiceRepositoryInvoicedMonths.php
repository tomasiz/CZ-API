<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice;

use Casamiza\Domain\Statement\InvoicedMonthsInterface;

/** Un mois est facturé dès qu'une facture non annulée existe pour le propriétaire. */
final readonly class InvoiceRepositoryInvoicedMonths implements InvoicedMonthsInterface
{
    public function __construct(private InvoiceRepositoryInterface $invoices) {}

    public function isInvoiced(string $ownerId, string $month): bool
    {
        return $this->invoices->hasActive($ownerId, $month);
    }
}
