<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Shared\AfterResponse;

/** Exécute tout de suite le traitement « après la réponse » : un test voit son effet sans attendre la fin du script. */
final class ImmediateAfterResponse implements AfterResponse
{
    /** @var int traitements demandés */
    public int $runs = 0;

    public function run(callable $work): void
    {
        $this->runs++;
        $work();
    }
}
