<?php

declare(strict_types=1);

namespace Casamiza\Domain\Integration;

use Casamiza\Shared\Exception\ApiException;
use Casamiza\Shared\Exception\ServiceUnavailableException;
use Casamiza\Shared\Exception\TooManyRequestsException;
use Casamiza\Shared\Exception\UnprocessableException;

/** Raison pour laquelle un contrôle de connexion échoue. La valeur est le code d'erreur de l'API (traduit par le front). */
enum CheckFailure: string
{
    case InvalidCredentials = 'integration_invalid_credentials';
    case Forbidden = 'integration_forbidden';
    case RateLimited = 'integration_rate_limited';
    case Unreachable = 'integration_unreachable';

    public function toException(): ApiException
    {
        return match ($this) {
            self::InvalidCredentials, self::Forbidden => new UnprocessableException($this->value),
            self::RateLimited => new TooManyRequestsException($this->value),
            self::Unreachable => new ServiceUnavailableException($this->value),
        };
    }
}
