<?php

declare(strict_types=1);

namespace Casamiza\Domain\OwnerStats;

use Casamiza\Domain\Dashboard\DashboardProperty;

final readonly class OwnerStats
{
    /**
     * @param list<DashboardProperty> $properties
     * @param list<MonthStats> $records
     * @param list<PlatformMonth> $platforms
     */
    public function __construct(public array $properties, public array $records, public array $platforms) {}
}
