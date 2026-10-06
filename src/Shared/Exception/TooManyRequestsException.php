<?php

declare(strict_types=1);

namespace Casamiza\Shared\Exception;

final class TooManyRequestsException extends ApiException
{
    public function __construct(string $code = 'too_many_requests')
    {
        parent::__construct($code, 429);
    }
}
