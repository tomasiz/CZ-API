<?php

declare(strict_types=1);

namespace Casamiza\Domain\Auth;

use DateTimeImmutable;

/** Enregistrement d'un refresh token (seul le hash SHA-256 est stocké). */
final readonly class RefreshToken
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $userId,
        /** Toute la chaîne de rotations d'une même connexion partage cet identifiant. */
        public string $familyId,
        public string $tokenHash,
        public DateTimeImmutable $expiresAt,
        /** Fin absolue de la session (plafond), jamais prolongée par les rotations. */
        public DateTimeImmutable $sessionExpiresAt,
        public ?DateTimeImmutable $usedAt = null,
        public ?DateTimeImmutable $revokedAt = null,
    ) {}
}
