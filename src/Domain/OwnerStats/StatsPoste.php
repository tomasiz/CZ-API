<?php

declare(strict_types=1);

namespace Casamiza\Domain\OwnerStats;

use Casamiza\Domain\Extra\ExtraType;

/** Postes facturés au propriétaire que montrent les statistiques : le linge compte dans le ménage. */
enum StatsPoste: string
{
    case Concierge = 'concierge';
    case Cleaning = 'cleaning';

    public static function of(ExtraType $category): self
    {
        return match ($category) {
            ExtraType::Concierge => self::Concierge,
            ExtraType::Linen, ExtraType::Cleaning => self::Cleaning,
        };
    }
}
