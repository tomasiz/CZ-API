<?php

declare(strict_types=1);

namespace Casamiza\Http\Resource;

use Casamiza\Domain\Cost\GuestyCostInvoice;
use Casamiza\Domain\Cost\GuestyCostReport;
use Casamiza\Domain\Cost\GuestyCostRow;
use DateTimeInterface;

final class GuestyCostResource
{
    /** @return array<string, mixed> */
    public static function invoice(GuestyCostInvoice $invoice): array
    {
        return [
            'number' => $invoice->number,
            'sourceFile' => $invoice->sourceFile,
            'invoiceDate' => $invoice->invoiceDate->format('Y-m-d'),
            'rate' => $invoice->rate,
            'periodStart' => $invoice->periodStart->format('Y-m-d'),
            'periodEnd' => $invoice->periodEnd->format('Y-m-d'),
            'listingsCount' => $invoice->listingsCount,
            'linesCount' => $invoice->linesCount,
            'amountUsd' => $invoice->amountUsdCents / 100,
            'amount' => $invoice->amountCents / 100,
            'processedAt' => $invoice->processedAt->format(DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public static function report(GuestyCostReport $report): array
    {
        return [
            'applied' => $report->applied,
            'number' => $report->number,
            'invoiceDate' => $report->invoiceDate->format('Y-m-d'),
            'rate' => $report->rate,
            'linesRead' => $report->linesRead,
            'amountUsd' => $report->amountUsdCents / 100,
            'amount' => $report->amountCents / 100,
            'replaced' => $report->replaced,
            'unmatched' => count($report->unmatched()),
            'duplicates' => count($report->duplicates()),
            'items' => array_map(self::row(...), $report->rows),
        ];
    }

    /** @return array<string, mixed> */
    private static function row(GuestyCostRow $row): array
    {
        $line = $row->item->line;

        return [
            'listing' => $line->nickname,
            'property' => $row->propertyName,
            'item' => $line->item,
            'kind' => $line->kind()->value,
            'from' => $line->from->format('Y-m-d'),
            'to' => $line->to->format('Y-m-d'),
            'month' => $line->month(),
            'amountUsd' => $line->usdCents / 100,
            'amount' => $row->item->amountCents / 100,
            'counted' => $row->item->counted,
        ];
    }
}
