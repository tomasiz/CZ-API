<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Persistence;

use Casamiza\Domain\Invoice\DraftLine;
use Casamiza\Domain\Invoice\DraftReservation;
use Casamiza\Domain\Invoice\Invoice;
use Casamiza\Domain\Invoice\InvoiceRepositoryInterface;
use Casamiza\Domain\Invoice\InvoiceSource;
use Casamiza\Domain\Invoice\PaymentMethod;
use Casamiza\Domain\Invoice\PaymentStatus;
use Casamiza\Domain\Invoice\StoredInvoiceFile;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use UnexpectedValueException;

/** Factures, leurs lignes, l'activité de leurs réservations et la séquence de numéros du tenant. */
final class PdoInvoiceRepository extends BaseRepository implements InvoiceRepositoryInterface
{
    /** Tout sauf l'instantané JSON, volumineux et inutile dans une liste. */
    private const LIST_COLUMNS = 'id, number, owner_id, month, period_start, period_end, invoice_date, due_date, amount_ht, vat, amount_ttc, payment_status, payment_method, payment_date, cancelled_at, note, pdf_path, recap_pdf_path, pdf_sha256, pennylane_id, source, legacy_id, created_at';

    protected function table(): string
    {
        return 'invoices';
    }

    public function nextNumber(): int
    {
        $pdo = $this->pdo();
        $tenant = ['tenant' => $this->tenant->id()];

        $pdo->prepare('INSERT IGNORE INTO tenant_invoice_sequences (tenant_id) VALUES (:tenant)')->execute($tenant);
        $select = $pdo->prepare('SELECT next_number FROM tenant_invoice_sequences WHERE tenant_id = :tenant FOR UPDATE');
        $select->execute($tenant);
        $number = $select->fetchColumn();
        if (!is_numeric($number)) {
            throw new RuntimeException('Invoice sequence not found.');
        }
        $pdo->prepare('UPDATE tenant_invoice_sequences SET next_number = next_number + 1 WHERE tenant_id = :tenant')->execute($tenant);

        return (int) $number;
    }

    public function add(Invoice $invoice, array $lines, array $reservations): void
    {
        $this->insertRow([
            'id' => $invoice->id,
            'number' => $invoice->number,
            'owner_id' => $invoice->ownerId,
            'month' => $invoice->month,
            'period_start' => $invoice->periodStart->format('Y-m-d'),
            'period_end' => $invoice->periodEnd->format('Y-m-d'),
            'invoice_date' => $invoice->invoiceDate->format('Y-m-d'),
            'due_date' => $invoice->dueDate->format('Y-m-d'),
            'amount_ht' => $invoice->amountHt,
            'vat' => $invoice->vat,
            'amount_ttc' => $invoice->amountTtc,
            'payment_status' => $invoice->paymentStatus->value,
            'payment_method' => $invoice->paymentMethod?->value,
            'payment_date' => $invoice->paymentDate?->format('Y-m-d'),
            'cancelled_at' => $invoice->cancelledAt === null ? null : DbDate::toDb($invoice->cancelledAt),
            'note' => $invoice->note,
            'snapshot' => $invoice->snapshot === null ? null : json_encode($invoice->snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            'pdf_path' => $invoice->pdfPath,
            'recap_pdf_path' => $invoice->recapPdfPath,
            'pdf_sha256' => $invoice->pdfSha256,
            'source' => $invoice->source->value,
            'legacy_id' => $invoice->legacyId,
        ] + ($invoice->createdAt === null ? [] : ['created_at' => DbDate::toDb($invoice->createdAt)]));

        foreach ($lines as $line) {
            $this->insertChild('invoice_lines', $this->lineRow($invoice, $line));
        }
        foreach ($reservations as $reservation) {
            $this->insertChild('invoice_reservations', $this->reservationRow($invoice, $reservation));
        }
    }

    public function find(string $id): ?Invoice
    {
        $row = $this->selectOne('id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function updatePayment(Invoice $invoice): void
    {
        $this->updateRow($invoice->id, [
            'payment_status' => $invoice->paymentStatus->value,
            'payment_method' => $invoice->paymentMethod?->value,
            'payment_date' => $invoice->paymentDate?->format('Y-m-d'),
        ]);
    }

    public function attachPdf(string $id, bool $recap, StoredInvoiceFile $file): bool
    {
        $statement = $this->pdo()->prepare($recap
            ? 'UPDATE invoices SET recap_pdf_path = :path WHERE tenant_id = :tenant AND id = :id AND recap_pdf_path IS NULL'
            : 'UPDATE invoices SET pdf_path = :path, pdf_sha256 = :sha256 WHERE tenant_id = :tenant AND id = :id AND pdf_path IS NULL');
        $statement->execute(['path' => $file->path, 'tenant' => $this->tenant->id(), 'id' => $id] + ($recap ? [] : ['sha256' => $file->sha256]));

        return $statement->rowCount() === 1;
    }

    public function all(): array
    {
        return array_map($this->hydrate(...), $this->select(columns: self::LIST_COLUMNS, suffix: 'ORDER BY number DESC'));
    }

    public function propertyNames(): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT DISTINCT ir.invoice_id, p.name FROM invoice_reservations ir
             JOIN properties p ON p.tenant_id = ir.tenant_id AND p.id = ir.property_id
             WHERE ir.tenant_id = :tenant ORDER BY p.name',
        );
        $statement->execute(['tenant' => $this->tenant->id()]);

        $names = [];
        foreach ($statement->fetchAll() as $row) {
            $names[(string) $row['invoice_id']][] = (string) $row['name'];
        }

        return $names;
    }

    public function active(?string $ownerId, ?string $month): array
    {
        $where = ['cancelled_at IS NULL'];
        $params = [];
        if ($ownerId !== null) {
            $where[] = 'owner_id = :owner';
            $params['owner'] = $ownerId;
        }
        if ($month !== null) {
            $where[] = 'month = :month';
            $params['month'] = $month;
        }

        return array_map($this->hydrate(...), $this->select(columns: self::LIST_COLUMNS, where: implode(' AND ', $where), params: $params, suffix: 'ORDER BY number'));
    }

    public function hasActive(string $ownerId, string $month): bool
    {
        return $this->selectOne('owner_id = :owner AND month = :month AND cancelled_at IS NULL', ['owner' => $ownerId, 'month' => $month], 'id') !== null;
    }

    public function legacyIds(): array
    {
        $ids = [];
        foreach ($this->select(columns: 'legacy_id', where: 'legacy_id IS NOT NULL') as $row) {
            $ids[(int) $row['legacy_id']] = true;
        }

        return $ids;
    }

    public function hasCasamizaInvoices(): bool
    {
        return $this->selectOne("source = 'casamiza'", [], 'id') !== null;
    }

    public function ensureNextNumberAtLeast(int $number): void
    {
        $this->pdo()
            ->prepare('INSERT INTO tenant_invoice_sequences (tenant_id, next_number) VALUES (:tenant, :number) ON DUPLICATE KEY UPDATE next_number = GREATEST(next_number, VALUES(next_number))')
            ->execute(['tenant' => $this->tenant->id(), 'number' => $number]);
    }

    /** @return array<string, mixed> */
    private function lineRow(Invoice $invoice, DraftLine $line): array
    {
        return [
            'id' => self::uuid(),
            'invoice_id' => $invoice->id,
            'position' => $line->position,
            'month' => $invoice->month,
            'kind' => $line->kind->value,
            'category' => $line->category->value,
            'property_id' => $line->propertyId,
            'reservation_id' => $line->reservationId,
            'extra_id' => $line->extraId,
            'label' => $line->label,
            'price_ttc' => $line->priceTtc,
            'vat_rate' => $line->vatRate,
            'price_ht' => $line->priceHt,
        ];
    }

    /** @return array<string, mixed> */
    private function reservationRow(Invoice $invoice, DraftReservation $reservation): array
    {
        return [
            'id' => self::uuid(),
            'invoice_id' => $invoice->id,
            'month' => $invoice->month,
            'owner_id' => $invoice->ownerId,
            'property_id' => $reservation->propertyId,
            'reservation_id' => $reservation->reservationId,
            'platform' => $reservation->platform->value,
            'check_in' => DbDate::toDb($reservation->checkIn),
            'check_out' => DbDate::toDb($reservation->checkOut),
            'nights' => $reservation->nights,
            'total_payout' => $reservation->totalPayout,
            'channel_commission' => $reservation->channelCommission,
            'payment_fee' => $reservation->paymentFee,
            'tourist_tax' => $reservation->touristTax,
            'cleaning_fee' => $reservation->cleaningFee,
            'net_payout' => $reservation->netPayout,
            'concierge_fee' => $reservation->conciergeFee,
            'net_owner_revenue' => $reservation->netOwnerRevenue,
        ];
    }

    /**
     * Insère une ligne dans une table enfant de la facture (nom de table écrit par le développeur), bornée au tenant courant.
     *
     * @param 'invoice_lines'|'invoice_reservations' $table
     * @param array<string, mixed> $row
     */
    private function insertChild(string $table, array $row): void
    {
        $row['tenant_id'] = $this->tenant->id();
        $columns = array_keys($row);
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $columns), implode(', ', array_map(static fn(string $c): string => ':' . $c, $columns)));
        $this->pdo()->prepare($sql)->execute($row);
    }

    private static function uuid(): string
    {
        return \Ramsey\Uuid\Uuid::uuid7()->toString();
    }

    /** @param array<array-key, mixed> $row */
    private function hydrate(array $row): Invoice
    {
        $id = (string) $row['id'];
        if ($id === '') {
            throw new UnexpectedValueException('Facture sans identifiant.');
        }
        $snapshot = isset($row['snapshot']) && is_string($row['snapshot']) ? json_decode($row['snapshot'], true, flags: JSON_THROW_ON_ERROR) : null;
        $utc = new DateTimeZone('UTC');
        $day = static fn(mixed $value): DateTimeImmutable => new DateTimeImmutable((string) $value, $utc);

        return new Invoice(
            id: $id,
            number: (int) $row['number'],
            ownerId: (string) $row['owner_id'],
            month: (string) $row['month'],
            periodStart: $day($row['period_start']),
            periodEnd: $day($row['period_end']),
            invoiceDate: $day($row['invoice_date']),
            dueDate: $day($row['due_date']),
            amountHt: (float) $row['amount_ht'],
            vat: (float) $row['vat'],
            amountTtc: (float) $row['amount_ttc'],
            paymentStatus: PaymentStatus::from((string) $row['payment_status']),
            paymentMethod: isset($row['payment_method']) ? PaymentMethod::from((string) $row['payment_method']) : null,
            paymentDate: isset($row['payment_date']) ? $day($row['payment_date']) : null,
            cancelledAt: DbDate::fromDbOrNull($row['cancelled_at'] ?? null),
            note: isset($row['note']) ? (string) $row['note'] : null,
            snapshot: is_array($snapshot) ? $snapshot : null,
            source: InvoiceSource::from((string) $row['source']),
            legacyId: isset($row['legacy_id']) ? (int) $row['legacy_id'] : null,
            createdAt: DbDate::fromDbOrNull($row['created_at'] ?? null),
            pdfPath: isset($row['pdf_path']) ? (string) $row['pdf_path'] : null,
            recapPdfPath: isset($row['recap_pdf_path']) ? (string) $row['recap_pdf_path'] : null,
            pdfSha256: isset($row['pdf_sha256']) ? (string) $row['pdf_sha256'] : null,
            pennylaneId: isset($row['pennylane_id']) ? (string) $row['pennylane_id'] : null,
        );
    }
}
