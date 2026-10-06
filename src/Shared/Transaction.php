<?php

declare(strict_types=1);

namespace Casamiza\Shared;

/** Exécute un traitement dans une transaction : validé s'il se termine, annulé s'il lève une exception. */
interface Transaction
{
    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function run(callable $work): mixed;
}
