<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Persistence;

use Casamiza\Domain\Cost\GuestyCostInvoice;
use Casamiza\Domain\Cost\GuestyCostItem;
use Casamiza\Domain\Cost\GuestyCostLine;
use Casamiza\Domain\Cost\GuestyCostRepositoryInterface;

/** Les trois tables des factures Guesty ; chaque requête est bornée au tenant courant. */
final class PdoGuestyCostRepository extends BaseRepository implements GuestyCostRepositoryInterface
{
    /** La facture et, calculés sur ses lignes, sa période et le nombre d'annonces facturées. */
    private const COLUMNS = 'guesty_cost_invoices.*,
        (SELECT MIN(x.period_start) FROM guesty_cost_items x WHERE x.tenant_id = guesty_cost_invoices.tenant_id AND x.invoice_id = guesty_cost_invoices.id) AS period_min,
        (SELECT MAX(x.period_end) FROM guesty_cost_items x WHERE x.tenant_id = guesty_cost_invoices.tenant_id AND x.invoice_id = guesty_cost_invoices.id) AS period_max,
        (SELECT COUNT(DISTINCT x.listing_key) FROM guesty_cost_items x WHERE x.tenant_id = guesty_cost_invoices.tenant_id AND x.invoice_id = guesty_cost_invoices.id) AS listings';

    protected function table(): string
    {
        return 'guesty_cost_invoices';
    }

    public function findInvoice(string $number): ?GuestyCostInvoice
    {
        $row = $this->selectOne('number = :number', ['number' => $number], self::COLUMNS);

        return $row === null ? null : $this->hydrateInvoice($row);
    }

    public function invoices(): array
    {
        return array_map($this->hydrateInvoice(...), $this->select(self::COLUMNS, '1 = 1', [], 'ORDER BY invoice_date DESC, number DESC'));
    }

    public function save(GuestyCostInvoice $invoice, array $items): void
    {
        $values = [
            'number' => $invoice->number,
            'source_file' => $invoice->sourceFile,
            'file_path' => $invoice->filePath,
            'file_sha256' => $invoice->fileSha256,
            'invoice_date' => $invoice->invoiceDate->format('Y-m-d'),
            'fx_rate' => $invoice->rate,
            'lines_count' => $invoice->linesCount,
            'amount_usd' => self::decimal($invoice->amountUsdCents),
            'amount' => self::decimal($invoice->amountCents),
            'processed_at' => DbDate::toDb($invoice->processedAt),
        ];
        if ($this->findRow($invoice->id) !== null) {
            $this->updateRow($invoice->id, $values);
        } else {
            $this->insertRow($values + ['id' => $invoice->id]);
        }

        $delete = $this->pdo()->prepare('DELETE FROM guesty_cost_items WHERE tenant_id = :tenant AND invoice_id = :invoice');
        $delete->execute(['tenant' => $this->tenant->id(), 'invoice' => $invoice->id]);

        $insert = $this->pdo()->prepare(
            'INSERT INTO guesty_cost_items (id, tenant_id, invoice_id, property_id, listing_nickname, listing_key, item, kind, period_start, period_end, cost_month, amount_usd, amount, counted)
             VALUES (:id, :tenant, :invoice, :property, :nickname, :key, :item, :kind, :start, :end, :month, :usd, :amount, :counted)',
        );
        foreach ($items as $item) {
            $line = $item->line;
            $insert->execute([
                'id' => $item->id,
                'tenant' => $this->tenant->id(),
                'invoice' => $invoice->id,
                'property' => $item->propertyId,
                'nickname' => $line->nickname,
                'key' => $line->listingKey(),
                'item' => $line->item,
                'kind' => $line->kind()->value,
                'start' => $line->from->format('Y-m-d'),
                'end' => $line->to->format('Y-m-d'),
                'month' => $line->month(),
                'usd' => self::decimal($line->usdCents),
                'amount' => self::decimal($item->amountCents),
                'counted' => $item->counted ? 1 : 0,
            ]);
        }
    }

    public function deleteInvoice(string $invoiceId): void
    {
        $tenant = $this->tenant->id();
        $this->pdo()->prepare('DELETE FROM guesty_cost_items WHERE tenant_id = :tenant AND invoice_id = :invoice')
            ->execute(['tenant' => $tenant, 'invoice' => $invoiceId]);
        $this->deleteRow($invoiceId);

        // Les lignes écartées, de la plus ancienne facture à la plus récente : la première d'un groupe sans ligne comptée est rétablie.
        $uncounted = $this->pdo()->prepare(
            'SELECT i.id, i.invoice_id, i.listing_key, i.period_start
             FROM guesty_cost_items i
             JOIN guesty_cost_invoices v ON v.tenant_id = i.tenant_id AND v.id = i.invoice_id
             WHERE i.tenant_id = :tenant AND i.counted = 0
             ORDER BY v.number, i.id',
        );
        $uncounted->execute(['tenant' => $tenant]);
        $hasCounted = $this->pdo()->prepare('SELECT 1 FROM guesty_cost_items WHERE tenant_id = :tenant AND listing_key = :key AND period_start = :start AND counted = 1 LIMIT 1');
        $restore = $this->pdo()->prepare('UPDATE guesty_cost_items SET counted = 1 WHERE tenant_id = :tenant AND id = :id');

        $seen = [];
        $touched = [];
        foreach ($uncounted->fetchAll() as $row) {
            $key = $row['listing_key'] . '|' . DbDate::fromDb($row['period_start'])->format('Y-m-d');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $hasCounted->execute(['tenant' => $tenant, 'key' => $row['listing_key'], 'start' => DbDate::fromDb($row['period_start'])->format('Y-m-d')]);
            if ($hasCounted->fetchColumn() === false) {
                $restore->execute(['tenant' => $tenant, 'id' => $row['id']]);
                $touched[(string) $row['invoice_id']] = true;
            }
        }

        $refresh = $this->pdo()->prepare(
            'UPDATE guesty_cost_invoices
             SET amount = COALESCE((SELECT SUM(x.amount) FROM guesty_cost_items x WHERE x.tenant_id = guesty_cost_invoices.tenant_id AND x.invoice_id = guesty_cost_invoices.id AND x.counted = 1), 0)
             WHERE tenant_id = :tenant AND id = :id',
        );
        foreach (array_keys($touched) as $id) {
            $refresh->execute(['tenant' => $tenant, 'id' => $id]);
        }
    }

    public function items(string $invoiceId): array
    {
        $statement = $this->pdo()->prepare('SELECT * FROM guesty_cost_items WHERE tenant_id = :tenant AND invoice_id = :invoice ORDER BY period_start, listing_nickname, id');
        $statement->execute(['tenant' => $this->tenant->id(), 'invoice' => $invoiceId]);

        $items = [];
        foreach ($statement->fetchAll() as $row) {
            $items[] = new GuestyCostItem(
                (string) $row['id'] ?: throw new \LogicException('Ligne de facture Guesty sans identifiant.'),
                (string) $row['invoice_id'],
                new GuestyCostLine(
                    0,
                    (string) $row['listing_nickname'],
                    (string) $row['item'],
                    DbDate::fromDb($row['period_start']),
                    DbDate::fromDb($row['period_end']),
                    (int) round(((float) $row['amount_usd']) * 100),
                ),
                $row['property_id'] === null ? null : (string) $row['property_id'],
                (int) round(((float) $row['amount']) * 100),
                (bool) $row['counted'],
            );
        }

        return $items;
    }

    public function countedLines(?string $exceptInvoiceId): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT listing_key, period_start FROM guesty_cost_items WHERE tenant_id = :tenant AND counted = 1 AND invoice_id <> :except',
        );
        $statement->execute(['tenant' => $this->tenant->id(), 'except' => $exceptInvoiceId ?? '']);

        $found = [];
        foreach ($statement->fetchAll() as $row) {
            $found[$row['listing_key'] . '|' . DbDate::fromDb($row['period_start'])->format('Y-m-d')] = true;
        }

        return $found;
    }

    public function aliases(): array
    {
        $statement = $this->pdo()->prepare('SELECT alias_key, property_id FROM guesty_listing_aliases WHERE tenant_id = :tenant');
        $statement->execute(['tenant' => $this->tenant->id()]);

        $aliases = [];
        foreach ($statement->fetchAll() as $row) {
            $aliases[(string) $row['alias_key']] = (string) $row['property_id'];
        }

        return $aliases;
    }

    public function saveAlias(string $matchKey, string $propertyId): void
    {
        $this->pdo()->prepare(
            'INSERT INTO guesty_listing_aliases (tenant_id, alias_key, property_id) VALUES (:tenant, :key, :property)
             ON DUPLICATE KEY UPDATE property_id = VALUES(property_id)',
        )->execute(['tenant' => $this->tenant->id(), 'key' => $matchKey, 'property' => $propertyId]);
    }

    /** @param array<array-key, mixed> $row */
    private function hydrateInvoice(array $row): GuestyCostInvoice
    {
        return new GuestyCostInvoice(
            (string) $row['id'] ?: throw new \LogicException('Facture Guesty sans identifiant.'),
            (string) $row['number'],
            (string) $row['source_file'],
            (string) $row['file_path'],
            (string) $row['file_sha256'],
            DbDate::fromDb($row['invoice_date']),
            number_format((float) $row['fx_rate'], 6, '.', ''),
            (int) $row['lines_count'],
            (int) round(((float) $row['amount_usd']) * 100),
            (int) round(((float) $row['amount']) * 100),
            DbDate::fromDb($row['processed_at']),
            DbDate::fromDb($row['period_min'] ?? $row['invoice_date']),
            DbDate::fromDb($row['period_max'] ?? $row['invoice_date']),
            (int) $row['listings'],
        );
    }

    /** Centimes signés en « -12.34 » (jamais de flottant). */
    private static function decimal(int $cents): string
    {
        return sprintf('%s%d.%02d', $cents < 0 ? '-' : '', intdiv(abs($cents), 100), abs($cents) % 100);
    }
}
