<?php

declare(strict_types=1);

namespace Casamiza\Domain\Auth;

use DateTimeImmutable;

/** Recherché par hash (secret de 256 bits) avant que le tenant ne soit connu : volontairement non borné par tenant. */
interface RefreshTokenRepositoryInterface
{
    public function add(RefreshToken $token): void;

    public function findByHash(string $hash): ?RefreshToken;

    /** Marque le jeton comme utilisé, de façon atomique. @return bool faux s'il l'était déjà (ou révoqué) */
    public function markUsed(string $id, DateTimeImmutable $at): bool;

    public function revokeFamily(string $familyId, DateTimeImmutable $at): void;
}
