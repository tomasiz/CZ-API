<?php

declare(strict_types=1);

namespace Casamiza\Http\Resource;

use Casamiza\Domain\Extra\VatRates;
use Casamiza\Domain\Property\Property;

/** Représentation JSON d'un logement. */
final class PropertyResource
{
    /** @return array<string, mixed> */
    public static function toArray(Property $property, ?string $ownerName, bool $ownerActive, VatRates $vatRates = new VatRates()): array
    {
        return [
            'id' => $property->id,
            'guestyId' => $property->guestyId,
            'ownerId' => $property->ownerId,
            'ownerName' => $ownerName,
            'ownerActive' => $ownerActive,
            // Vrai quand un administrateur a choisi le propriétaire : la synchronisation Guesty n'y touche plus.
            'ownerManual' => $property->ownerManual,
            'name' => $property->name,
            'address' => $property->address,
            'cleaningFee' => $property->cleaningFee,
            'linenFee' => $property->linenFee,
            // Les coûts sont saisis TTC dans Guesty ; ces taux (réglages de l'entreprise) servent à afficher leur HT.
            'vatRateCleaning' => $vatRates->cleaning,
            'vatRateLinen' => $vatRates->linen,
            // Faux pour un logement désactivé (ou disparu) dans Guesty.
            'active' => !$property->isArchived(),
        ];
    }
}
