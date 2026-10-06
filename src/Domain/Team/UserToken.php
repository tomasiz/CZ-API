<?php

declare(strict_types=1);

namespace Casamiza\Domain\Team;

use DateTimeImmutable;

/** Jeton à usage unique envoyé à un utilisateur (seul son hash SHA-256 est stocké). */
final readonly class UserToken
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $userId,
        public TokenPurpose $purpose,
        public string $tokenHash,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $usedAt = null,
    ) {}
}
