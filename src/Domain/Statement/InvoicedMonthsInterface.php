<?php

declare(strict_types=1);

namespace Casamiza\Domain\Statement;

/** Une facture existe-t-elle pour ce propriétaire et ce mois ? Si oui, plus rien ne se modifie pour ce mois-là. */
interface InvoicedMonthsInterface
{
    public function isInvoiced(string $ownerId, string $month): bool;
}
