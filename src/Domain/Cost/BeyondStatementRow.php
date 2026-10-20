<?php

declare(strict_types=1);

namespace Casamiza\Domain\Cost;

/** Une ligne de la liste annuelle : la période, et le relevé s'il a été traité. */
final readonly class BeyondStatementRow
{
    public function __construct(
        public BeyondPeriod $period,
        /** La période est terminée : le relevé peut être importé */
        public bool $closed,
        public ?BeyondStatement $statement,
    ) {}
}
