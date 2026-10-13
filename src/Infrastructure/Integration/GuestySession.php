<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Integration;

/** Jeton d'accès utilisé pendant une lecture de Guesty ; il n'est renouvelé qu'une fois (sur un 401), pas davantage. */
final class GuestySession
{
    public bool $renewed = false;

    public function __construct(public string $token) {}
}
