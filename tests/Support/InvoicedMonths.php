<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Statement\InvoicedMonthsInterface;

/** Mois déclarés facturés par le test (la table des factures n'existe pas encore). */
final class InvoicedMonths implements InvoicedMonthsInterface
{
    /** @var array<string, true> clé « propriétaire|mois » */
    private array $invoiced = [];

    public function invoice(string $ownerId, string $month): void
    {
        $this->invoiced[$ownerId . '|' . $month] = true;
    }

    public function isInvoiced(string $ownerId, string $month): bool
    {
        return isset($this->invoiced[$ownerId . '|' . $month]);
    }
}
