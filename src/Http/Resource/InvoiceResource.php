<?php

declare(strict_types=1);

namespace Casamiza\Http\Resource;

use Casamiza\Domain\Invoice\Invoice;
use Casamiza\Domain\Invoice\InvoiceRow;
use DateTimeImmutable;

final class InvoiceResource
{
    /**
     * Une facture de la liste : la facture, plus `ownerName` et `properties` (logements facturés).
     *
     * @return array<string, mixed>
     */
    public static function row(InvoiceRow $row, DateTimeImmutable $now): array
    {
        return self::toArray($row->invoice, $now) + ['ownerName' => $row->ownerName, 'properties' => $row->propertyNames];
    }

    /**
     * `status` : `cancelled`, `paid`, `overdue` (non payée après l'échéance) ou `unpaid`. L'instantané JSON n'est jamais inclus
     * (route dédiée).
     *
     * @return array<string, mixed>
     */
    public static function toArray(Invoice $invoice, DateTimeImmutable $now): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'displayNumber' => $invoice->displayNumber(),
            'ownerId' => $invoice->ownerId,
            'month' => $invoice->month,
            'periodStart' => $invoice->periodStart->format('Y-m-d'),
            'periodEnd' => $invoice->periodEnd->format('Y-m-d'),
            'invoiceDate' => $invoice->invoiceDate->format('Y-m-d'),
            'dueDate' => $invoice->dueDate->format('Y-m-d'),
            'amountHt' => $invoice->amountHt,
            'vat' => $invoice->vat,
            'amountTtc' => $invoice->amountTtc,
            'status' => match (true) {
                $invoice->isCancelled() => 'cancelled',
                $invoice->isOverdue($now) => 'overdue',
                default => $invoice->paymentStatus->value,
            },
            'paymentMethod' => $invoice->paymentMethod?->value,
            'paymentDate' => $invoice->paymentDate?->format('Y-m-d'),
            'cancelledAt' => $invoice->cancelledAt?->format(DATE_ATOM),
            'note' => $invoice->note,
            'source' => $invoice->source->value,
            'pennylaneId' => $invoice->pennylaneId,
            'hasPdf' => $invoice->pdfPath !== null,
            'hasRecap' => $invoice->recapPdfPath !== null,
            'createdAt' => $invoice->createdAt?->format(DATE_ATOM),
        ];
    }
}
