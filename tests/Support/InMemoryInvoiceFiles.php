<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Invoice\InvoiceFileStorageInterface;
use Casamiza\Domain\Invoice\StoredInvoiceFile;
use RuntimeException;

/** Stockage de PDF en mémoire : garde le contenu copié, sans toucher au disque. */
final class InMemoryInvoiceFiles implements InvoiceFileStorageInterface
{
    /** @var array<string, string> contenu par chemin relatif */
    public array $files = [];

    /** Si renseigné, la copie de ce n-ième fichier (à partir de 1) échoue : simule une écriture interrompue. */
    public ?int $failAt = null;

    private int $count = 0;

    public function store(string $sourcePath, string $year): StoredInvoiceFile
    {
        $this->count++;
        if ($this->failAt === $this->count) {
            throw new RuntimeException('Disk full.');
        }
        $content = (string) file_get_contents($sourcePath);
        $path = sprintf('invoices/test/%s/file-%d.pdf', $year, $this->count);
        $this->files[$path] = $content;

        return new StoredInvoiceFile($path, hash('sha256', $content));
    }

    public function discard(string $path): void
    {
        unset($this->files[$path]);
    }
}
