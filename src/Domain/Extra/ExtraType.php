<?php

declare(strict_types=1);

namespace Casamiza\Domain\Extra;

/** Catégorie d'une prestation supplémentaire : celles des totaux de la facture (`conciergerie`, `linge`, `ménage`). */
enum ExtraType: string
{
    case Concierge = 'concierge';
    case Linen = 'linen';
    case Cleaning = 'cleaning';
}
