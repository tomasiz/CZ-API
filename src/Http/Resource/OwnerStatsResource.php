<?php

declare(strict_types=1);

namespace Casamiza\Http\Resource;

use Casamiza\Domain\Dashboard\DashboardProperty;
use Casamiza\Domain\OwnerStats\MonthStats;
use Casamiza\Domain\OwnerStats\OwnerStats;
use Casamiza\Domain\OwnerStats\PlatformMonth;

/** Représentation JSON des statistiques d'un propriétaire : montants en euros (calculés en centimes entiers). */
final class OwnerStatsResource
{
    /** @return array{properties: list<array<string, mixed>>, records: list<array<string, mixed>>, platforms: list<array<string, mixed>>} */
    public static function toArray(OwnerStats $stats): array
    {
        return [
            'properties' => array_map(static fn(DashboardProperty $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'active' => $p->active,
            ], $stats->properties),
            'records' => array_map(static fn(MonthStats $r): array => [
                'propertyId' => $r->propertyId,
                'month' => $r->month,
                'reservations' => $r->reservations,
                'nights' => $r->nights,
                'ownerNights' => $r->ownerNights,
                'totalPaid' => $r->payoutCents / 100,
                'platformFees' => $r->platformFeeCents / 100,
                'touristTax' => $r->touristTaxCents / 100,
                'cleaning' => $r->cleaningCents / 100,
                'concierge' => $r->conciergeCents / 100,
                'ownerNet' => $r->ownerNetCents() / 100,
            ], $stats->records),
            'platforms' => array_map(static fn(PlatformMonth $p): array => [
                'propertyId' => $p->propertyId,
                'month' => $p->month,
                'platform' => $p->platform,
                'reservations' => $p->reservations,
                'nights' => $p->nights,
                'totalPaid' => $p->payoutCents / 100,
            ], $stats->platforms),
        ];
    }
}
