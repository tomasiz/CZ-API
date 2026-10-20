<?php

declare(strict_types=1);

namespace Casamiza\Http\Resource;

use Casamiza\Domain\Cost\BeyondFeeLine;
use Casamiza\Domain\Cost\BeyondImportReport;
use Casamiza\Domain\Cost\BeyondReservationFee;
use Casamiza\Domain\Cost\BeyondStatementRow;
use DateTimeInterface;

final class BeyondFeeResource
{
    /** @return array<string, mixed> */
    public static function row(BeyondStatementRow $row): array
    {
        $statement = $row->statement;

        return [
            'start' => $row->period->start->format('Y-m-d'),
            'end' => $row->period->end->format('Y-m-d'),
            'closed' => $row->closed,
            'processed' => $statement !== null,
            'sourceFile' => $statement?->sourceFile,
            'linesCount' => $statement?->linesCount,
            'reservationsCount' => $statement?->reservationsCount,
            'amount' => $statement === null ? null : $statement->amountCents / 100,
            'processedAt' => $statement?->processedAt->format(DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public static function report(BeyondImportReport $report): array
    {
        return [
            'applied' => $report->applied,
            'start' => $report->period->start->format('Y-m-d'),
            'end' => $report->period->end->format('Y-m-d'),
            'linesRead' => $report->linesRead,
            'linesNew' => $report->linesNew,
            'linesKnown' => $report->linesKnown,
            'linesIgnored' => $report->linesIgnored,
            'reservationsFetched' => $report->reservationsFetched,
            'reservationsCount' => count($report->reservations),
            'amount' => $report->amountCents / 100,
            'warnings' => $report->warnings,
            'reservations' => array_map(self::reservation(...), $report->reservations),
            'unmatchedWithoutProperty' => $report->unmatchedWithoutProperty,
            'unmatched' => array_map(static fn(BeyondFeeLine $l): array => [
                'listingTitle' => $l->listingTitle,
                'checkIn' => $l->checkIn->format('Y-m-d'),
                'kind' => $l->kind->value,
                'billedOn' => $l->billedOn()->format('Y-m-d'),
                'amount' => $l->amountCents / 100,
            ], $report->unmatched),
        ];
    }

    /** @return array<string, mixed> */
    private static function reservation(BeyondReservationFee $fee): array
    {
        return [
            'guestyId' => $fee->guestyId,
            'listingTitle' => $fee->listingTitle,
            'guest' => $fee->guest,
            'checkIn' => $fee->checkIn->format(DateTimeInterface::ATOM),
            'checkOut' => $fee->checkOut->format(DateTimeInterface::ATOM),
            'fetched' => $fee->fetched,
            'amount' => $fee->totalCents / 100,
            'lines' => array_map(self::line(...), $fee->lines),
        ];
    }

    /** @return array<string, mixed> */
    private static function line(BeyondFeeLine $line): array
    {
        return [
            'kind' => $line->kind->value,
            'billedOn' => $line->billedOn()->format('Y-m-d'),
            'amount' => $line->amountCents / 100,
        ];
    }
}
