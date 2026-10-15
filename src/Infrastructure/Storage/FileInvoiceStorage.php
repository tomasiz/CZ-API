<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Storage;

use Casamiza\Domain\Invoice\InvoiceFileStorageInterface;
use Casamiza\Domain\Invoice\StoredInvoiceFile;
use Casamiza\Shared\TenantContext;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * PDF de factures sur disque : `<racine>/invoices/<tenant>/<année>/<uuid>.pdf`, hors du dossier public. Le nom ne contient ni
 * numéro ni nom de famille. Un fichier est écrit sous un nom temporaire puis renommé, il n'apparaît donc jamais à moitié écrit.
 */
final readonly class FileInvoiceStorage implements InvoiceFileStorageInterface
{
    public function __construct(
        private string $root,
        private TenantContext $tenant,
    ) {}

    public function store(string $sourcePath, string $year): StoredInvoiceFile
    {
        if (preg_match('/^\d{4}$/', $year) !== 1) {
            throw new RuntimeException('Invalid year.');
        }
        $sha256 = hash_file('sha256', $sourcePath);
        if ($sha256 === false) {
            throw new RuntimeException('Cannot read the source file.');
        }

        $relative = sprintf('invoices/%s/%s/%s.pdf', $this->tenant->id(), $year, Uuid::uuid7()->toString());
        $target = $this->root . '/' . $relative;
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the storage directory.');
        }

        $temporary = $target . '.tmp';
        if (!copy($sourcePath, $temporary) || !rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot store the file.');
        }
        if (hash_file('sha256', $target) !== $sha256) {
            @unlink($target);
            throw new RuntimeException('The stored file differs from its source.');
        }

        return new StoredInvoiceFile($relative, $sha256);
    }

    public function contents(string $path): ?string
    {
        // Le chemin vient de la base, mais on le borne quand même : uniquement le dossier du tenant courant.
        if (preg_match('#^invoices/' . preg_quote($this->tenant->id(), '#') . '/\d{4}/[\w-]+\.pdf$#', $path) !== 1) {
            return null;
        }
        $file = $this->root . '/' . $path;
        $contents = is_file($file) ? file_get_contents($file) : false;

        return $contents === false ? null : $contents;
    }

    public function discard(string $path): void
    {
        if (preg_match('#^invoices/[\w-]+/\d{4}/[\w-]+\.pdf$#', $path) === 1) {
            @unlink($this->root . '/' . $path);
        }
    }
}
