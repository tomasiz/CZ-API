<?php

declare(strict_types=1);

namespace Casamiza\Shared\Exception;

final class NotFoundException extends ApiException
{
    public function __construct(string $code = 'resource_not_found')
    {
        parent::__construct($code, 404);
    }
}
