<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Pdf;

use RuntimeException;
use tFPDF;

/**
 * Dossier de polices de tFPDF. La bibliothèque y lit les polices de base (`helvetica*.php`) et y écrit, au premier usage de
 * chaque police TrueType, ses fichiers de métriques ; ce dossier doit donc être inscriptible, ce que ni `vendor/` ni le code
 * déployé ne sont. Il est reconstitué dans le dossier de cache à partir de la bibliothèque et des polices du projet
 * (`resources/pdf/fonts`).
 */
final readonly class PdfFontDirectory
{
    /** Polices de base lues par `SetFont('Arial', …)` (Arial = Helvetica pour tFPDF). */
    private const CORE_FONTS = ['helvetica.php', 'helveticab.php', 'helveticai.php', 'helveticabi.php'];

    public function __construct(
        private string $fontsResources,
        private string $cacheRoot,
    ) {}

    /** @return non-empty-string chemin se terminant par `/` */
    public function path(): string
    {
        $directory = $this->cacheRoot . '/pdf-fonts';
        $library = dirname((string) new \ReflectionClass(tFPDF::class)->getFileName()) . '/font';

        foreach (self::CORE_FONTS as $file) {
            $this->copy($library . '/' . $file, $directory . '/' . $file);
        }
        foreach (glob($this->fontsResources . '/*.ttf') ?: [] as $file) {
            $this->copy($file, $directory . '/unifont/' . basename($file));
        }

        return $directory . '/';
    }

    /** Copie le fichier s'il manque ou si sa taille diffère ; écrit sous un nom temporaire puis renomme (jamais à moitié écrit). */
    private function copy(string $source, string $target): void
    {
        if (is_file($target) && filesize($target) === filesize($source)) {
            return;
        }
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the PDF font directory.');
        }
        $temporary = $target . '.' . getmypid() . '.tmp';
        if (!copy($source, $temporary) || !rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot copy a PDF font.');
        }
    }
}
