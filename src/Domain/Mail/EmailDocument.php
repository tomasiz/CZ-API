<?php

declare(strict_types=1);

namespace Casamiza\Domain\Mail;

/** Document joignable à l'e-mail d'un mois : la facture, le récapitulatif propriétaire. */
enum EmailDocument: string
{
    case Invoice = 'invoice';
    case Recap = 'recap';
}
