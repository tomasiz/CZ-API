<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Invoice\Invoice;
use Casamiza\Domain\Invoice\InvoiceRepositoryInvoicedMonths;
use Casamiza\Domain\Statement\InvoicedMonthsInterface;
use Casamiza\Shared\TenantContext;
use DateTimeImmutable;

/** Déclare facturé un mois d'un tenant en y posant une facture minimale, que lit le vrai `InvoiceRepositoryInvoicedMonths`. */
final class InvoicedMonths implements InvoicedMonthsInterface
{
    private readonly InvoiceRepositoryInvoicedMonths $months;

    public function __construct(private readonly InMemoryInvoices $invoices, private readonly TenantContext $context)
    {
        $this->months = new InvoiceRepositoryInvoicedMonths($invoices);
    }

    public function invoice(string $tenantId, string $ownerId, string $month): void
    {
        $day = new DateTimeImmutable($month . '-01 UTC');
        $this->context->reset();
        $this->context->set($tenantId);
        $this->invoices->add(
            new Invoice('invoice-' . $ownerId . '-' . $month, $this->invoices->nextNumber(), $ownerId, $month, $day, $day->modify('last day of this month'), $day, $day->modify('+1 month'), 0.0, 0.0, 0.0),
            [],
            [],
        );
        $this->context->reset();
    }

    public function isInvoiced(string $ownerId, string $month): bool
    {
        return $this->months->isInvoiced($ownerId, $month);
    }
}
