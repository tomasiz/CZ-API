<?php

declare(strict_types=1);

namespace Casamiza\Domain\Cost;

use DateTimeImmutable;

/** Relevé Beyond traité : sa période, le CSV conservé et le bilan du dernier traitement (centimes d'euro signés). */
final readonly class BeyondStatement
{
    /** @param non-empty-string $id */
    public function __construct(
        public string $id,
        public BeyondPeriod $period,
        public string $sourceFile,
        /** Chemin relatif dans `storage/` du CSV du dernier traitement */
        public string $filePath,
        public string $fileSha256,
        public int $linesCount,
        public int $reservationsCount,
        public int $amountCents,
        public DateTimeImmutable $processedAt,
    ) {}
}
