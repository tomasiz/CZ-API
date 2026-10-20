<?php

declare(strict_types=1);

namespace Casamiza\Domain\Cost;

/** Une ligne de facture avec le nom du logement qui la porte (nul si aucun). */
final readonly class GuestyCostRow
{
    public function __construct(
        public GuestyCostItem $item,
        public ?string $propertyName,
    ) {}
}
