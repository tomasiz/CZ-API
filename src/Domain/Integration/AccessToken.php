<?php

declare(strict_types=1);

namespace Casamiza\Domain\Integration;

use DateTimeImmutable;

/** Jeton d'accès délivré par un tiers (OAuth2), réutilisé jusqu'à son expiration. */
final readonly class AccessToken
{
    public function __construct(
        public string $value,
        public DateTimeImmutable $expiresAt,
    ) {}

    /** Valable encore au moins `$margin` secondes (on renouvelle un peu avant l'expiration). */
    public function isValidAt(DateTimeImmutable $now, int $margin = 300): bool
    {
        return $this->expiresAt->getTimestamp() - $margin > $now->getTimestamp();
    }
}
