<?php

declare(strict_types=1);

namespace Casamiza\Shared\Exception;

final class UnauthorizedException extends ApiException
{
    public function __construct(string $code = 'unauthenticated')
    {
        parent::__construct($code, 401);
    }
}
