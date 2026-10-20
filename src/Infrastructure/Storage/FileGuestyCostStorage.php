<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Storage;

use Casamiza\Domain\Cost\GuestyCostFileStorageInterface;
use Casamiza\Domain\Cost\StoredBeyondFile;
use Casamiza\Shared\TenantContext;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Factures Guesty (xlsx) sur disque : `<racine>/guesty/<tenant>/<année>/<uuid>.xlsx`, hors du dossier public. Un fichier est écrit
 * sous un nom temporaire puis renommé, il n'apparaît donc jamais à moitié écrit.
 */
final readonly class FileGuestyCostStorage implements GuestyCostFileStorageInterface
{
    public function __construct(
        private string $root,
        private TenantContext $tenant,
    ) {}

    public function store(string $bytes, string $year): StoredBeyondFile
    {
        $relative = sprintf('guesty/%s/%s/%s.xlsx', $this->tenant->id(), preg_replace('/\D/', '', $year), Uuid::uuid7()->toString());
        $target = $this->root . '/' . $relative;
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the storage directory.');
        }

        $temporary = $target . '.tmp';
        if (file_put_contents($temporary, $bytes) === false || !rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot store the file.');
        }

        return new StoredBeyondFile($relative, hash('sha256', $bytes));
    }

    public function contents(string $path): ?string
    {
        if (!$this->isOwn($path)) {
            return null;
        }
        $file = $this->root . '/' . $path;
        $contents = is_file($file) ? file_get_contents($file) : false;

        return $contents === false ? null : $contents;
    }

    public function discard(string $path): void
    {
        if ($this->isOwn($path)) {
            @unlink($this->root . '/' . $path);
        }
    }

    /** Le chemin vient de la base, mais on le borne quand même : uniquement le dossier du tenant courant. */
    private function isOwn(string $path): bool
    {
        return preg_match('#^guesty/' . preg_quote($this->tenant->id(), '#') . '/\d{4}/[\w-]+\.xlsx$#', $path) === 1;
    }
}
