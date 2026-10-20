<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Cost\BeyondFileStorageInterface;
use Casamiza\Domain\Cost\BeyondPeriod;
use Casamiza\Domain\Cost\StoredBeyondFile;
use Casamiza\Shared\TenantContext;

/** CSV de relevés Beyond en mémoire (le chemin porte le tenant, comme sur disque). */
final class InMemoryBeyondFiles implements BeyondFileStorageInterface
{
    /** @var array<string, string> chemin => contenu */
    public array $files = [];

    private int $counter = 0;

    public function __construct(private readonly TenantContext $context) {}

    public function store(string $csv, BeyondPeriod $period): StoredBeyondFile
    {
        $path = sprintf('beyond/%s/%s/file-%d.csv', $this->context->id(), $period->start->format('Y'), ++$this->counter);
        $this->files[$path] = $csv;

        return new StoredBeyondFile($path, hash('sha256', $csv));
    }

    public function contents(string $path): ?string
    {
        return str_starts_with($path, 'beyond/' . $this->context->id() . '/') ? ($this->files[$path] ?? null) : null;
    }

    public function discard(string $path): void
    {
        if (str_starts_with($path, 'beyond/' . $this->context->id() . '/')) {
            unset($this->files[$path]);
        }
    }
}
