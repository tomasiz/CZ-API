<?php

declare(strict_types=1);

namespace Casamiza\Shared\Exception;

/** Règle métier qui refuse durablement cette opération (422), par opposition à ConflictException (état courant, 409). */
final class UnprocessableException extends ApiException
{
    public function __construct(string $code = 'unprocessable')
    {
        parent::__construct($code, 422);
    }
}
