<?php

declare(strict_types=1);

namespace Casamiza\Domain\Team;

use DateTimeImmutable;

/** Recherché par hash (secret de 256 bits) avant que le tenant ne soit connu : volontairement non borné par tenant. */
interface UserTokenRepositoryInterface
{
    public function add(UserToken $token): void;

    public function findByHash(string $hash): ?UserToken;

    /** Consomme le jeton, de façon atomique. @return bool faux s'il l'était déjà */
    public function markUsed(string $id, DateTimeImmutable $at): bool;
}
