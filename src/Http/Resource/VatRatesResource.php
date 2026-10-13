<?php

declare(strict_types=1);

namespace Casamiza\Http\Resource;

use Casamiza\Domain\Extra\VatRates;

/** Taux de TVA (en %) proposés pour chaque type de prestation. */
final class VatRatesResource
{
    /** @return array{concierge: float, linen: float, cleaning: float} */
    public static function toArray(VatRates $rates): array
    {
        return ['concierge' => $rates->concierge, 'linen' => $rates->linen, 'cleaning' => $rates->cleaning];
    }
}
