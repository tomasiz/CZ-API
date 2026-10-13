<?php

declare(strict_types=1);

namespace Casamiza\Domain\Company;

/** Stockage des logos (hors webroot). Un chemin est une référence opaque : seule l'implémentation sait le lire. */
interface LogoStorageInterface
{
    /** @return non-empty-string chemin du nouveau fichier (jamais celui d'un fichier existant) */
    public function put(string $tenantId, string $png): string;

    public function read(string $path): ?string;

    public function delete(string $path): void;
}
