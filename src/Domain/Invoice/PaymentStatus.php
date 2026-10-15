<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice;

/** Paiement d'une facture. « En retard » n'est pas stocké : c'est une facture non payée dont l'échéance est dépassée. */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Paid = 'paid';
}
