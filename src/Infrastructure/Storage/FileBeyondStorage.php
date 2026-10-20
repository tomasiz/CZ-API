<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Storage;

use Casamiza\Domain\Cost\BeyondFileStorageInterface;
use Casamiza\Domain\Cost\BeyondPeriod;
use Casamiza\Domain\Cost\StoredBeyondFile;
use Casamiza\Shared\TenantContext;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * CSV de relevés Beyond sur disque : `<racine>/beyond/<tenant>/<année>/<uuid>.csv`, hors du dossier public. Un fichier est écrit
 * sous un nom temporaire puis renommé, il n'apparaît donc jamais à moitié écrit.
 */
final readonly class FileBeyondStorage implements BeyondFileStorageInterface
{
    public function __construct(
        private string $root,
        private TenantContext $tenant,
    ) {}

    public function store(string $csv, BeyondPeriod $period): StoredBeyondFile
    {
        $relative = sprintf('beyond/%s/%s/%s.csv', $this->tenant->id(), $period->start->format('Y'), Uuid::uuid7()->toString());
        $target = $this->root . '/' . $relative;
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the storage directory.');
        }

        $temporary = $target . '.tmp';
        if (file_put_contents($temporary, $csv) === false || !rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot store the file.');
        }

        return new StoredBeyondFile($relative, hash('sha256', $csv));
    }

    public function contents(string $path): ?string
    {
        // Le chemin vient de la base, mais on le borne quand même : uniquement le dossier du tenant courant.
        if (preg_match('#^beyond/' . preg_quote($this->tenant->id(), '#') . '/\d{4}/[\w-]+\.csv$#', $path) !== 1) {
            return null;
        }
        $file = $this->root . '/' . $path;
        $contents = is_file($file) ? file_get_contents($file) : false;

        return $contents === false ? null : $contents;
    }

    public function discard(string $path): void
    {
        if (preg_match('#^beyond/' . preg_quote($this->tenant->id(), '#') . '/\d{4}/[\w-]+\.csv$#', $path) === 1) {
            @unlink($this->root . '/' . $path);
        }
    }
}
