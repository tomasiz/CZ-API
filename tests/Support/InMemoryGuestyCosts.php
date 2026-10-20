<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Cost\GuestyCostInvoice;
use Casamiza\Domain\Cost\GuestyCostItem;
use Casamiza\Domain\Cost\GuestyCostRepositoryInterface;
use Casamiza\Shared\TenantContext;

/** Factures Guesty importées en mémoire, bornées au tenant courant comme en base. */
final class InMemoryGuestyCosts implements GuestyCostRepositoryInterface
{
    /** @var array<string, GuestyCostInvoice> clé « tenant|numéro » */
    public array $invoices = [];

    /** @var array<string, list<GuestyCostItem>> clé « tenant|identifiant de la facture » */
    public array $items = [];

    /** @var array<string, string> clé « tenant|clé du nom d'annonce » => identifiant du logement */
    public array $aliases = [];

    public function __construct(private readonly TenantContext $context) {}

    public function findInvoice(string $number): ?GuestyCostInvoice
    {
        return $this->invoices[$this->context->id() . '|' . $number] ?? null;
    }

    public function invoices(): array
    {
        $prefix = $this->context->id() . '|';
        $found = array_values(array_filter($this->invoices, static fn(string $key): bool => str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY));
        usort($found, static fn(GuestyCostInvoice $a, GuestyCostInvoice $b): int => [$b->invoiceDate, $b->number] <=> [$a->invoiceDate, $a->number]);

        return $found;
    }

    // La période et le nombre d'annonces sont ceux fournis à l'enregistrement (en base, ils se calculent sur les lignes).
    public function save(GuestyCostInvoice $invoice, array $items): void
    {
        $this->invoices[$this->context->id() . '|' . $invoice->number] = $invoice;
        $this->items[$this->context->id() . '|' . $invoice->id] = $items;
    }

    public function deleteInvoice(string $invoiceId): void
    {
        $prefix = $this->context->id() . '|';
        unset($this->items[$prefix . $invoiceId]);
        foreach ($this->invoices as $key => $invoice) {
            if (str_starts_with($key, $prefix) && $invoice->id === $invoiceId) {
                unset($this->invoices[$key]);
            }
        }

        // Même règle que la base : une ligne écartée dont plus aucune ligne ne compte est rétablie, la plus ancienne facture d'abord.
        $numbers = [];
        foreach ($this->invoices as $key => $invoice) {
            if (str_starts_with($key, $prefix)) {
                $numbers[$invoice->id] = $invoice->number;
            }
        }
        asort($numbers);
        $counted = $this->countedLines(null);
        foreach (array_keys($numbers) as $id) {
            $items = $this->items[$prefix . $id] ?? [];
            $changed = false;
            foreach ($items as $i => $item) {
                $key = $item->line->listingKey() . '|' . $item->line->from->format('Y-m-d');
                if (!$item->counted && !isset($counted[$key])) {
                    $items[$i] = new GuestyCostItem($item->id, $item->invoiceId, $item->line, $item->propertyId, $item->amountCents, true);
                    $counted[$key] = true;
                    $changed = true;
                }
            }
            if ($changed) {
                $this->items[$prefix . $id] = $items;
                foreach ($this->invoices as $invoiceKey => $invoice) {
                    if ($invoice->id === $id) {
                        $total = array_sum(array_map(static fn(GuestyCostItem $x): int => $x->counted ? $x->amountCents : 0, $items));
                        $this->invoices[$invoiceKey] = new GuestyCostInvoice($invoice->id, $invoice->number, $invoice->sourceFile, $invoice->filePath, $invoice->fileSha256, $invoice->invoiceDate, $invoice->rate, $invoice->linesCount, $invoice->amountUsdCents, $total, $invoice->processedAt, $invoice->periodStart, $invoice->periodEnd, $invoice->listingsCount);
                    }
                }
            }
        }
    }

    public function items(string $invoiceId): array
    {
        return $this->items[$this->context->id() . '|' . $invoiceId] ?? [];
    }

    public function countedLines(?string $exceptInvoiceId): array
    {
        $prefix = $this->context->id() . '|';
        $found = [];
        foreach ($this->items as $key => $items) {
            if (!str_starts_with($key, $prefix) || $key === $prefix . $exceptInvoiceId) {
                continue;
            }
            foreach ($items as $item) {
                if ($item->counted) {
                    $found[$item->line->listingKey() . '|' . $item->line->from->format('Y-m-d')] = true;
                }
            }
        }

        return $found;
    }

    public function aliases(): array
    {
        $prefix = $this->context->id() . '|';
        $found = [];
        foreach ($this->aliases as $key => $propertyId) {
            if (str_starts_with($key, $prefix)) {
                $found[substr($key, strlen($prefix))] = $propertyId;
            }
        }

        return $found;
    }

    public function saveAlias(string $matchKey, string $propertyId): void
    {
        $this->aliases[$this->context->id() . '|' . $matchKey] = $propertyId;
    }
}
