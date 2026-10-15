<?php

declare(strict_types=1);

namespace Casamiza\Http\Resource;

use Casamiza\Domain\Guesty\SyncCounts;
use Casamiza\Domain\Guesty\SyncReport;
use Casamiza\Domain\Reservation\ReservationSyncReport;
use DateTimeInterface;

/** Représentation JSON du bilan d'une synchronisation. */
final class SyncReportResource
{
    /** @return array<string, mixed> */
    public static function toArray(SyncReport $report): array
    {
        return [
            'owners' => self::counts($report->owners),
            'properties' => self::counts($report->properties),
            'syncedAt' => $report->syncedAt->format(DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public static function reservations(ReservationSyncReport $report): array
    {
        return [
            'reservations' => self::counts($report->reservations),
            'skipped' => $report->skipped,
            'syncedAt' => $report->syncedAt->format(DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, int> */
    private static function counts(SyncCounts $counts): array
    {
        return ['created' => $counts->created, 'updated' => $counts->updated, 'archived' => $counts->archived, 'unchanged' => $counts->unchanged];
    }
}
