<?php

declare(strict_types=1);

namespace Casamiza\Http\Resource;

use Casamiza\Domain\Dashboard\DashboardOverview;
use Casamiza\Domain\Dashboard\DashboardProperty;
use Casamiza\Domain\Dashboard\PropertyMonth;

/** Représentation JSON du tableau de bord : montants en euros (calculés en centimes entiers). */
final class DashboardResource
{
    /** @return array{properties: list<array<string, mixed>>, records: list<array<string, mixed>>} */
    public static function toArray(DashboardOverview $overview): array
    {
        return [
            'properties' => array_map(static fn(DashboardProperty $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'ownerId' => $p->ownerId,
                'ownerName' => $p->ownerName,
                'active' => $p->active,
            ], $overview->properties),
            'records' => array_map(static fn(PropertyMonth $r): array => [
                'propertyId' => $r->propertyId,
                'month' => $r->month,
                'reservations' => $r->reservations,
                'nights' => $r->nights,
                'payout' => $r->payoutCents / 100,
                'ownerNet' => $r->ownerNetCents / 100,
                'conciergeHt' => $r->conciergeHtCents / 100,
                'conciergeTtc' => $r->conciergeTtcCents / 100,
                'linenHt' => $r->linenHtCents / 100,
                'linenTtc' => $r->linenTtcCents / 100,
                'disbursementHt' => $r->disbursementHtCents / 100,
                'disbursementTtc' => $r->disbursementTtcCents / 100,
                'softwareCost' => $r->softwareCostCents / 100,
            ], $overview->records),
        ];
    }
}
