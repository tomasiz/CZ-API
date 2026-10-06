<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Security;

final readonly class IssuedAccessToken
{
    public function __construct(public string $token, public int $expiresIn) {}
}
