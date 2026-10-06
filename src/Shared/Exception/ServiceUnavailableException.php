<?php

declare(strict_types=1);

namespace Casamiza\Shared\Exception;

final class ServiceUnavailableException extends ApiException
{
    public function __construct(string $code = 'service_unavailable')
    {
        parent::__construct($code, 503);
    }
}
