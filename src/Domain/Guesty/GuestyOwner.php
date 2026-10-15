<?php

declare(strict_types=1);

namespace Casamiza\Domain\Guesty;

use Casamiza\Domain\Owner\OwnerType;

/** Propriétaire tel que Guesty le décrit (lecture seule). Nom, prénom et nom de famille sont sans préfixe « P - ». */
final readonly class GuestyOwner
{
    /**
     * @param bool $active champ `active` de Guesty (faux : portail propriétaire non utilisé, d'après les données lues)
     * @param bool $hasActiveListing au moins un des logements du propriétaire est actif dans Guesty
     */
    public function __construct(
        public string $guestyId,
        public string $name,
        public string $firstName,
        public string $lastName,
        public string $email,
        public string $phone,
        public string $address,
        public OwnerType $type,
        public ?float $commission,
        public bool $active,
        public bool $hasActiveListing = false,
    ) {}

    /** Réellement hors service : marqué inactif par Guesty et sans aucun logement actif. */
    public function isDormant(): bool
    {
        return !$this->active && !$this->hasActiveListing;
    }
}
