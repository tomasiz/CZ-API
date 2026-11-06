<?php

declare(strict_types=1);

namespace Casamiza\Domain\Dashboard;

/** Un mois facturé dont les coûts logiciels sont incomplets : ce qui n'a pas été importé. */
final readonly class CostGap
{
    public function __construct(
        /** AAAA-MM */
        public string $month,
        /** Aucune facture Guesty n'apporte de coût à ce mois */
        public bool $guesty,
        /** Un des deux relevés Beyond qui couvrent ce mois n'est pas traité */
        public bool $beyond,
    ) {}
}
