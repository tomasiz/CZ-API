<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Cost\GuestyCostFileStorageInterface;
use Casamiza\Domain\Cost\StoredBeyondFile;
use Casamiza\Shared\TenantContext;

/** Fichiers de factures Guesty en mémoire (le chemin porte le tenant, comme sur disque). */
final class InMemoryGuestyCostFiles implements GuestyCostFileStorageInterface
{
    /** @var array<string, string> chemin => contenu */
    public array $files = [];

    private int $counter = 0;

    public function __construct(private readonly TenantContext $context) {}

    public function store(string $bytes, string $year): StoredBeyondFile
    {
        $path = sprintf('guesty/%s/%s/file-%d.xlsx', $this->context->id(), $year, ++$this->counter);
        $this->files[$path] = $bytes;

        return new StoredBeyondFile($path, hash('sha256', $bytes));
    }

    public function contents(string $path): ?string
    {
        return str_starts_with($path, 'guesty/' . $this->context->id() . '/') ? ($this->files[$path] ?? null) : null;
    }

    public function discard(string $path): void
    {
        if (str_starts_with($path, 'guesty/' . $this->context->id() . '/')) {
            unset($this->files[$path]);
        }
    }
}
