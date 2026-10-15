<?php

declare(strict_types=1);

namespace Casamiza\Domain\Dashboard;

final readonly class DashboardOverview
{
    /**
     * @param list<DashboardProperty> $properties tous les logements du tenant
     * @param list<PropertyMonth> $records
     */
    public function __construct(public array $properties, public array $records) {}
}
