<?php

declare(strict_types=1);

namespace Casamiza\Domain\Cost;

use DateTimeImmutable;

/** Facture Guesty importée : le fichier conservé, le cours fixé à l'import et les totaux (centimes). */
final readonly class GuestyCostInvoice
{
    /** @param non-empty-string $id */
    public function __construct(
        public string $id,
        /** « INV00814774 » */
        public string $number,
        public string $sourceFile,
        /** Chemin relatif dans `storage/` du fichier du dernier traitement */
        public string $filePath,
        public string $fileSha256,
        public DateTimeImmutable $invoiceDate,
        /** Euros pour un dollar, six décimales (cours de la BCE) */
        public string $rate,
        public int $linesCount,
        /** Total facturé par Guesty, toutes lignes du fichier */
        public int $amountUsdCents,
        /** Ce que la facture apporte aux coûts : lignes comptées seulement, converties en euros */
        public int $amountCents,
        public DateTimeImmutable $processedAt,
        /** Début de la plus ancienne ligne et fin de la plus récente (jours) */
        public DateTimeImmutable $periodStart,
        public DateTimeImmutable $periodEnd,
        /** Annonces distinctes facturées */
        public int $listingsCount,
    ) {}
}
