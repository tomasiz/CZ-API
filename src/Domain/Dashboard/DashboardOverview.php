<?php

declare(strict_types=1);

namespace Casamiza\Domain\Dashboard;

final readonly class DashboardOverview
{
    /**
     * @param list<DashboardProperty> $properties tous les logements du tenant
     * @param list<PropertyMonth> $records
     * @param list<CostGap> $costGaps mois facturés dont les coûts logiciels sont incomplets, du plus ancien au plus récent
     */
    public function __construct(public array $properties, public array $records, public array $costGaps = []) {}
}
