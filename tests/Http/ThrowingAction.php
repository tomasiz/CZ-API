<?php

declare(strict_types=1);

namespace Casamiza\Tests\Http;

use Throwable;

/** Route de test qui lève l'exception donnée (un objet invocable, car Slim ne peut pas lier une closure statique). */
final readonly class ThrowingAction
{
    public function __construct(private Throwable $exception) {}

    public function __invoke(): never
    {
        throw $this->exception;
    }
}
