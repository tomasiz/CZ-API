<?php

declare(strict_types=1);

namespace Casamiza\Domain\Cost;

/** Une ligne de facture Guesty : le mois entier d'une annonce, ou la part d'un mois (annonce ajoutée en cours de mois). */
enum GuestyCostKind: string
{
    case Monthly = 'monthly';
    case Prorata = 'prorata';
}
