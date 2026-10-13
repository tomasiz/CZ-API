<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Company\LogoStorageInterface;

/** Logos en mémoire. */
final class InMemoryLogoStorage implements LogoStorageInterface
{
    /** @var array<string, string> contenu par chemin */
    public array $files = [];

    private int $counter = 0;

    public function put(string $tenantId, string $png): string
    {
        $path = 'logos/' . $tenantId . '-' . ++$this->counter . '.png';
        $this->files[$path] = $png;

        return $path;
    }

    public function read(string $path): ?string
    {
        return $this->files[$path] ?? null;
    }

    public function delete(string $path): void
    {
        unset($this->files[$path]);
    }
}
