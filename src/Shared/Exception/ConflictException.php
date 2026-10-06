<?php

declare(strict_types=1);

namespace Casamiza\Shared\Exception;

final class ConflictException extends ApiException
{
    public function __construct(string $code = 'conflict')
    {
        parent::__construct($code, 409);
    }
}
