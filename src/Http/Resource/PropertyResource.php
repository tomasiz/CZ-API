<?php

declare(strict_types=1);

namespace Casamiza\Http\Resource;

use Casamiza\Domain\Property\Property;

/** Représentation JSON d'un logement. */
final class PropertyResource
{
    /** @return array<string, mixed> */
    public static function toArray(Property $property, ?string $ownerName, bool $ownerActive): array
    {
        return [
            'id' => $property->id,
            'guestyId' => $property->guestyId,
            'ownerId' => $property->ownerId,
            'ownerName' => $ownerName,
            'ownerActive' => $ownerActive,
            'name' => $property->name,
            'address' => $property->address,
            'cleaningFee' => $property->cleaningFee,
            'linenFee' => $property->linenFee,
            // Faux pour un logement désactivé (ou disparu) dans Guesty.
            'active' => !$property->isArchived(),
        ];
    }
}
