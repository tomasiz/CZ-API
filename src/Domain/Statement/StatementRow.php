<?php

declare(strict_types=1);

namespace Casamiza\Domain\Statement;

/** Statut de facturation d'un propriétaire pour un mois. */
final readonly class StatementRow
{
    public function __construct(
        public string $ownerId,
        public string $month,
        public BillingStatus $status,
    ) {}
}
