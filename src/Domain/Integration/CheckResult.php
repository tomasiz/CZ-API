<?php

declare(strict_types=1);

namespace Casamiza\Domain\Integration;

/**
 * Issue d'un contrôle de connexion. `token` est le jeton à conserver en cache (null = aucun, ou rejeté par le tiers).
 */
final readonly class CheckResult
{
    private function __construct(
        public ?CheckFailure $failure,
        public ?AccessToken $token,
    ) {}

    public static function connected(?AccessToken $token = null): self
    {
        return new self(null, $token);
    }

    public static function failed(CheckFailure $failure, ?AccessToken $token = null): self
    {
        return new self($failure, $token);
    }

    public function isConnected(): bool
    {
        return $this->failure === null;
    }
}
