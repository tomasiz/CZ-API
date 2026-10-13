<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Storage;

use Casamiza\Domain\Company\LogoStorageInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Logos sur disque, dans `storage/logos/` (hors webroot). Chaque envoi crée un **nouveau** fichier au nom aléatoire :
 * l'ancien n'est supprimé qu'une fois le nouveau enregistré en base, et un chemin ne peut pas être deviné.
 */
final readonly class FileLogoStorage implements LogoStorageInterface
{
    private const PATH_PATTERN = '/^logos\/[A-Za-z0-9-]+\.png$/';

    /** @param string $root dossier `storage/` */
    public function __construct(private string $root) {}

    public function put(string $tenantId, string $png): string
    {
        if (preg_match('/^[A-Za-z0-9-]+$/', $tenantId) !== 1) {
            throw new InvalidArgumentException('Identifiant de tenant invalide.');
        }
        $directory = $this->root . '/logos';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Impossible de créer le dossier des logos.');
        }

        $path = 'logos/' . $tenantId . '-' . bin2hex(random_bytes(8)) . '.png';
        $temporary = $this->root . '/' . $path . '.tmp';
        if (file_put_contents($temporary, $png) === false || !chmod($temporary, 0640) || !rename($temporary, $this->root . '/' . $path)) {
            @unlink($temporary);
            throw new RuntimeException("Impossible d'enregistrer le logo.");
        }

        return $path;
    }

    public function read(string $path): ?string
    {
        if (preg_match(self::PATH_PATTERN, $path) !== 1) {
            return null;
        }
        $content = @file_get_contents($this->root . '/' . $path);

        return $content === false ? null : $content;
    }

    public function delete(string $path): void
    {
        if (preg_match(self::PATH_PATTERN, $path) === 1) {
            @unlink($this->root . '/' . $path);
        }
    }
}
