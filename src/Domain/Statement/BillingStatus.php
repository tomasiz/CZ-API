<?php

declare(strict_types=1);

namespace Casamiza\Domain\Statement;

/**
 * Statut de facturation d'un propriétaire pour un mois. Calculé, jamais saisi (sauf « rien à facturer »), par ordre de priorité :
 * une facture non annulée du mois (`paid`, `overdue` si non payée après son échéance, sinon `invoiced`), puis « rien à facturer »,
 * sinon `to-invoice`.
 */
enum BillingStatus: string
{
    case ToInvoice = 'to-invoice';
    case NothingToInvoice = 'nothing-to-invoice';
    case Invoiced = 'invoiced';
    case Paid = 'paid';
    case Overdue = 'overdue';
}
