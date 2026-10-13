<?php

declare(strict_types=1);

namespace Casamiza\Domain\Statement;

/**
 * Statut de facturation d'un propriétaire pour un mois. Calculé, jamais saisi (sauf « rien à facturer »).
 * Les statuts `invoiced`, `paid` et `overdue` s'ajouteront avec les factures.
 */
enum BillingStatus: string
{
    case ToInvoice = 'to-invoice';
    case NothingToInvoice = 'nothing-to-invoice';
}
