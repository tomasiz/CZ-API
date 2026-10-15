<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice\Document;

use DateTimeImmutable;

/**
 * Tout ce qu'imprime la facture PDF, sans accès à la base. Les dates sont des jours ; `total*` sont les totaux légaux de la
 * facture (HT + TVA = TTC), jamais recalculés à l'impression.
 */
final readonly class InvoiceDocument
{
    /**
     * @param list<InvoiceSection> $sections
     * @param list<VatBreakdown> $vat
     */
    public function __construct(
        public string $number,
        public DateTimeImmutable $date,
        public DateTimeImmutable $dueDate,
        public DateTimeImmutable $periodStart,
        public DateTimeImmutable $periodEnd,
        public string $note,
        public DocumentRecipient $recipient,
        public array $sections,
        public array $vat,
        public float $totalHt,
        public float $totalVat,
        public float $totalTtc,
    ) {}
}
