<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Dashboard\DashboardProperty;
use Casamiza\Domain\Invoice\Invoice;
use Casamiza\Domain\Invoice\InvoiceLineKind;
use Casamiza\Domain\OwnerStats\OwnerNights;
use Casamiza\Domain\OwnerStats\OwnerStatsRepositoryInterface;
use Casamiza\Domain\OwnerStats\StatsActivity;
use Casamiza\Domain\OwnerStats\StatsBilled;
use Casamiza\Domain\OwnerStats\StatsPoste;
use Casamiza\Domain\Reservation\Platform;
use Casamiza\Domain\Statement\BillingMonth;
use Casamiza\Shared\TenantContext;

/** Lit les factures en mémoire comme le fait `PdoOwnerStatsRepository` : tenant courant, propriétaire donné, factures non annulées, centimes entiers. */
final class InMemoryOwnerStats implements OwnerStatsRepositoryInterface
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly InMemoryInvoices $invoices,
        private readonly InMemoryProperties $properties,
        private readonly InMemoryReservations $reservations,
        private readonly InMemoryOwners $owners,
    ) {}

    public function properties(string $ownerId): array
    {
        $billed = [];
        foreach ($this->activeInvoices($ownerId) as $key => $invoice) {
            foreach ($this->invoices->reservations[$key] ?? [] as $reservation) {
                $billed[$reservation->propertyId] = true;
            }
        }
        $ownerNames = [];
        foreach ($this->owners->all() as $owner) {
            $ownerNames[$owner->id] = $owner->name;
        }
        $properties = array_filter($this->properties->all(), static fn($p): bool => $p->ownerId === $ownerId || isset($billed[$p->id]));

        return array_values(array_map(static fn($p): DashboardProperty => new DashboardProperty(
            $p->id,
            $p->name,
            $p->ownerId,
            $p->ownerId === null ? '' : ($ownerNames[$p->ownerId] ?? ''),
            $p->archivedAt === null,
        ), $properties));
    }

    public function activity(string $ownerId): array
    {
        $grouped = [];
        foreach ($this->activeInvoices($ownerId) as $key => $invoice) {
            foreach ($this->invoices->reservations[$key] ?? [] as $r) {
                $id = $invoice->id . '|' . $r->propertyId . '|' . $r->platform->value;
                $c = $grouped[$id] ?? [$invoice->id, $r->propertyId, $invoice->month, $r->platform->value, 0, 0, 0, 0, 0];
                $grouped[$id] = [
                    $c[0], $c[1], $c[2], $c[3], $c[4] + 1, $c[5] + $r->nights,
                    $c[6] + (int) round($r->totalPayout * 100),
                    $c[7] + (int) round(($r->channelCommission + $r->paymentFee) * 100),
                    $c[8] + (int) round($r->touristTax * 100),
                ];
            }
        }

        return array_values(array_map(static fn(array $g): StatsActivity => new StatsActivity(...$g), $grouped));
    }

    public function billed(string $ownerId): array
    {
        $grouped = [];
        foreach ($this->activeInvoices($ownerId) as $key => $invoice) {
            foreach ($this->invoices->lines[$key] ?? [] as $line) {
                if ($line->kind === InvoiceLineKind::PlatformCommission) {
                    continue;
                }
                $poste = StatsPoste::of($line->category);
                $id = $invoice->id . '|' . ($line->propertyId ?? '') . '|' . $poste->value;
                $c = $grouped[$id] ?? [$invoice->id, $line->propertyId, $invoice->month, $poste, 0];
                $grouped[$id] = [$c[0], $c[1], $c[2], $c[3], $c[4] + (int) round($line->priceTtc * 100)];
            }
        }

        return array_values(array_map(static fn(array $g): StatsBilled => new StatsBilled(...$g), $grouped));
    }

    public function ownerNights(string $ownerId): array
    {
        $prefix = $this->context->id() . '|';
        $stays = [];
        foreach ($this->reservations->rows as $key => $reservation) {
            if (str_starts_with($key, $prefix) && $reservation->ownerId === $ownerId && $reservation->platform === Platform::Owner && $reservation->archivedAt === null) {
                $stays[] = new OwnerNights($reservation->propertyId, BillingMonth::of($reservation->checkOut), $reservation->nights);
            }
        }

        return $stays;
    }

    /** @return array<string, Invoice> clé « tenant|id » */
    private function activeInvoices(string $ownerId): array
    {
        $prefix = $this->context->id() . '|';

        return array_filter(
            $this->invoices->rows,
            static fn(Invoice $invoice, string $key): bool => str_starts_with($key, $prefix) && $invoice->cancelledAt === null && $invoice->ownerId === $ownerId,
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
