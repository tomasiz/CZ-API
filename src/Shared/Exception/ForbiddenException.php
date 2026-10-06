<?php

declare(strict_types=1);

namespace Casamiza\Shared\Exception;

final class ForbiddenException extends ApiException
{
    public function __construct(string $code = 'forbidden')
    {
        parent::__construct($code, 403);
    }
}
