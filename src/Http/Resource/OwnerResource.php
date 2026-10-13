<?php

declare(strict_types=1);

namespace Casamiza\Http\Resource;

use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Property\Property;

/** Représentation JSON d'un propriétaire et de ses logements. */
final class OwnerResource
{
    /**
     * @param list<Property> $properties
     * @return array<string, mixed>
     */
    public static function toArray(Owner $owner, array $properties): array
    {
        return [
            'id' => $owner->id,
            'guestyId' => $owner->guestyId,
            'name' => $owner->name,
            'email' => $owner->email,
            'phone' => $owner->phone,
            'address' => $owner->address,
            'type' => $owner->type->value,
            'companyName' => $owner->companyName,
            'siret' => $owner->siret,
            'commission' => $owner->commission,
            // Faux pour un propriétaire désactivé (ou disparu) dans Guesty.
            'active' => !$owner->isArchived(),
            'properties' => array_map(
                static fn(Property $p): array => ['id' => $p->id, 'name' => $p->name, 'address' => $p->address, 'cleaningFee' => $p->cleaningFee, 'linenFee' => $p->linenFee],
                $properties,
            ),
        ];
    }
}
