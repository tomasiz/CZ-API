<?php

declare(strict_types=1);

namespace Casamiza\Http\Resource;

use Casamiza\Domain\Extra\Extra;
use Casamiza\Domain\Extra\VatRates;

final class ExtraResource
{
    /** @return array<string, mixed> `priceHt` est le prix HT exact stocké (6 décimales) */
    public static function toArray(Extra $e): array
    {
        return [
            'id' => $e->id,
            'ownerId' => $e->ownerId,
            'month' => $e->month,
            'type' => $e->type->value,
            'description' => $e->description,
            'priceTtc' => $e->priceTtc,
            'vatRate' => $e->vatRate,
            'priceHt' => $e->priceHt,
        ];
    }

    /**
     * @param list<Extra> $extras
     * @return array{items: list<array<string, mixed>>, defaultVatRates: array{concierge: float, linen: float, cleaning: float}}
     */
    public static function sheet(array $extras, VatRates $defaults): array
    {
        return [
            'items' => array_map(self::toArray(...), $extras),
            'defaultVatRates' => VatRatesResource::toArray($defaults),
        ];
    }
}
