<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Shared\Transaction;

final class ImmediateTransaction implements Transaction
{
    public function run(callable $work): mixed
    {
        return $work();
    }
}
