<?php

declare(strict_types=1);

namespace Casamiza\Domain\Cost;

use DateTimeImmutable;

/** Relevés Beyond traités du tenant courant. */
interface BeyondStatementRepositoryInterface
{
    public function find(BeyondPeriod $period): ?BeyondStatement;

    /**
     * Les relevés dont la période commence dans `[$from, $to[`.
     *
     * @return list<BeyondStatement>
     */
    public function startingBetween(DateTimeImmutable $from, DateTimeImmutable $to): array;

    /** Insère le relevé, ou réécrit celui qui porte le même identifiant. */
    public function save(BeyondStatement $statement): void;
}
