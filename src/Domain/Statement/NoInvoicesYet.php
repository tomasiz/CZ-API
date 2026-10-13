<?php

declare(strict_types=1);

namespace Casamiza\Domain\Statement;

/** Tant que la table des factures n'existe pas, aucun mois n'est facturé. Remplacée par la lecture des factures (étape D). */
final class NoInvoicesYet implements InvoicedMonthsInterface
{
    public function isInvoiced(string $ownerId, string $month): bool
    {
        return false;
    }
}
