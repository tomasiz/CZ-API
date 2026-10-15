<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Dashboard\BilledAmount;
use Casamiza\Domain\Dashboard\DashboardProperty;
use Casamiza\Domain\Dashboard\DashboardRepositoryInterface;
use Casamiza\Domain\Dashboard\InvoiceActivity;
use Casamiza\Domain\Invoice\Invoice;
use Casamiza\Shared\TenantContext;

/** Lit les factures en mémoire comme le fait `PdoDashboardRepository` : tenant courant, factures non annulées, centimes entiers. */
final class InMemoryDashboard implements DashboardRepositoryInterface
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly InMemoryInvoices $invoices,
        private readonly InMemoryProperties $properties,
        private readonly InMemoryOwners $owners,
    ) {}

    public function activity(): array
    {
        $grouped = [];
        foreach ($this->activeInvoices() as $key => $invoice) {
            foreach ($this->invoices->reservations[$key] ?? [] as $reservation) {
                $id = $invoice->id . '|' . $reservation->propertyId;
                $current = $grouped[$id] ?? [$invoice->id, $reservation->propertyId, $invoice->month, 0, 0, 0];
                $grouped[$id] = [$current[0], $current[1], $current[2], $current[3] + 1, $current[4] + $reservation->nights, $current[5] + (int) round($reservation->totalPayout * 100)];
            }
        }

        return array_values(array_map(static fn(array $g): InvoiceActivity => new InvoiceActivity(...$g), $grouped));
    }

    public function billed(): array
    {
        $grouped = [];
        foreach ($this->activeInvoices() as $key => $invoice) {
            foreach ($this->invoices->lines[$key] ?? [] as $line) {
                $id = $invoice->id . '|' . ($line->propertyId ?? '') . '|' . $line->category->value;
                $current = $grouped[$id] ?? [$invoice->id, $invoice->ownerId, $line->propertyId, $invoice->month, $line->category, 0, 0];
                $grouped[$id] = [$current[0], $current[1], $current[2], $current[3], $current[4], $current[5] + (int) round($line->priceTtc * 100), $current[6] + (int) round($line->priceHt * 100)];
            }
        }

        return array_values(array_map(static fn(array $g): BilledAmount => new BilledAmount(...$g), $grouped));
    }

    public function properties(): array
    {
        $ownerNames = [];
        foreach ($this->owners->all() as $owner) {
            $ownerNames[$owner->id] = $owner->name;
        }

        return array_map(static fn($p): DashboardProperty => new DashboardProperty(
            $p->id,
            $p->name,
            $p->ownerId,
            $p->ownerId === null ? '' : ($ownerNames[$p->ownerId] ?? ''),
            $p->archivedAt === null,
        ), $this->properties->all());
    }

    /** @return array<string, Invoice> clé « tenant|id » */
    private function activeInvoices(): array
    {
        $prefix = $this->context->id() . '|';

        return array_filter(
            $this->invoices->rows,
            static fn(Invoice $invoice, string $key): bool => str_starts_with($key, $prefix) && $invoice->cancelledAt === null,
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
