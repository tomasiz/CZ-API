<?php

declare(strict_types=1);

namespace Casamiza\Domain\Dashboard;

/** Coût de l'abonnement Guesty (en centimes d'euro) d'un logement pour un mois ; logement nul si aucun ne porte le nom de l'annonce. */
final readonly class GuestyCost
{
    public function __construct(
        public ?string $propertyId,
        /** AAAA-MM du début de la période facturée */
        public string $month,
        public int $cents,
    ) {}
}
