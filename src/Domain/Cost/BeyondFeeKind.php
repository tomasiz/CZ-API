<?php

declare(strict_types=1);

namespace Casamiza\Domain\Cost;

/**
 * Nature d'une ligne du relevé Beyond : la commission d'une réservation, son remboursement à l'annulation (montant négatif) ou
 * l'ajustement d'une réservation modifiée (montant positif ou négatif).
 */
enum BeyondFeeKind: string
{
    case Reservation = 'reservation';
    case Cancellation = 'cancellation';
    case Update = 'update';
}
