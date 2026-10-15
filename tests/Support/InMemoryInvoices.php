<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Invoice\DraftLine;
use Casamiza\Domain\Invoice\DraftReservation;
use Casamiza\Domain\Invoice\Invoice;
use Casamiza\Domain\Invoice\InvoiceRepositoryInterface;
use Casamiza\Domain\Invoice\InvoiceSource;
use Casamiza\Domain\Invoice\StoredInvoiceFile;
use Casamiza\Shared\TenantContext;

/** Factures en mémoire, bornées au tenant courant comme en base (la séquence de numéros aussi). */
final class InMemoryInvoices implements InvoiceRepositoryInterface
{
    /** @var array<string, Invoice> clé « tenant|id » */
    public array $rows = [];

    /** @var array<string, list<DraftLine>> lignes par clé de facture */
    public array $lines = [];

    /** @var array<string, list<DraftReservation>> activité des réservations par clé de facture */
    public array $reservations = [];

    /** @var array<string, int> prochain numéro par tenant */
    private array $sequence = [];

    public function __construct(
        private readonly TenantContext $context,
        private readonly ?InMemoryProperties $properties = null,
    ) {}

    public function propertyNames(): array
    {
        $names = [];
        $byId = [];
        foreach ($this->properties?->all() ?? [] as $property) {
            $byId[$property->id] = $property->name;
        }
        $prefix = $this->context->id() . '|';
        foreach ($this->reservations as $key => $activity) {
            if (!str_starts_with($key, $prefix)) {
                continue;
            }
            $list = array_unique(array_map(static fn(DraftReservation $r): string => $byId[$r->propertyId] ?? $r->propertyId, $activity));
            sort($list);
            $names[substr($key, strlen($prefix))] = $list;
        }

        return $names;
    }

    public function nextNumber(): int
    {
        $tenant = $this->context->id();
        $number = $this->sequence[$tenant] ?? 1;
        $this->sequence[$tenant] = $number + 1;

        return $number;
    }

    public function add(Invoice $invoice, array $lines, array $reservations): void
    {
        $key = $this->context->id() . '|' . $invoice->id;
        $this->rows[$key] = $invoice;
        $this->lines[$key] = $lines;
        $this->reservations[$key] = $reservations;
    }

    public function find(string $id): ?Invoice
    {
        return $this->rows[$this->context->id() . '|' . $id] ?? null;
    }

    public function updatePayment(Invoice $invoice): void
    {
        $key = $this->context->id() . '|' . $invoice->id;
        if (isset($this->rows[$key])) {
            $this->rows[$key] = $invoice;
        }
    }

    public function attachPdf(string $id, bool $recap, StoredInvoiceFile $file): bool
    {
        $key = $this->context->id() . '|' . $id;
        $invoice = $this->rows[$key] ?? null;
        if ($invoice === null || ($recap ? $invoice->recapPdfPath : $invoice->pdfPath) !== null) {
            return false;
        }
        $this->rows[$key] = new Invoice(...[...get_object_vars($invoice), ...($recap ? ['recapPdfPath' => $file->path] : ['pdfPath' => $file->path, 'pdfSha256' => $file->sha256])]);

        return true;
    }

    public function all(): array
    {
        $prefix = $this->context->id() . '|';
        $found = array_values(array_filter($this->rows, static fn(string $key): bool => str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY));
        usort($found, static fn(Invoice $a, Invoice $b): int => $b->number <=> $a->number);

        return $found;
    }

    public function legacyIds(): array
    {
        $ids = [];
        foreach ($this->all() as $invoice) {
            if ($invoice->legacyId !== null) {
                $ids[$invoice->legacyId] = true;
            }
        }

        return $ids;
    }

    public function hasCasamizaInvoices(): bool
    {
        foreach ($this->all() as $invoice) {
            if ($invoice->source === InvoiceSource::Casamiza) {
                return true;
            }
        }

        return false;
    }

    public function ensureNextNumberAtLeast(int $number): void
    {
        $tenant = $this->context->id();
        $this->sequence[$tenant] = max($this->sequence[$tenant] ?? 1, $number);
    }

    public function active(?string $ownerId, ?string $month): array
    {
        return array_values(array_filter(
            array_reverse($this->all()),
            static fn(Invoice $i): bool => !$i->isCancelled() && ($ownerId === null || $i->ownerId === $ownerId) && ($month === null || $i->month === $month),
        ));
    }

    public function hasActive(string $ownerId, string $month): bool
    {
        foreach ($this->all() as $invoice) {
            if ($invoice->ownerId === $ownerId && $invoice->month === $month && !$invoice->isCancelled()) {
                return true;
            }
        }

        return false;
    }
}
