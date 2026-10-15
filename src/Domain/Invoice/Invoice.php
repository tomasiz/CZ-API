<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice;

use DateTimeImmutable;

/**
 * Une facture émise : en-tête, totaux et instantané légal. Ses lignes (`DraftLine`) et l'activité de ses réservations
 * (`DraftReservation`) sont enregistrées avec elle et ne se modifient plus. Les dates de période, de facture et
 * d'échéance sont des jours (à minuit UTC).
 */
final readonly class Invoice
{
    /** @param array<string, mixed>|null $snapshot structure de FACTURIZA.md §5.6 ; null pour les factures 1 à 6 de Facturiza */
    public function __construct(
        public string $id,
        public int $number,
        public string $ownerId,
        public string $month,
        public DateTimeImmutable $periodStart,
        public DateTimeImmutable $periodEnd,
        public DateTimeImmutable $invoiceDate,
        public DateTimeImmutable $dueDate,
        public float $amountHt,
        public float $vat,
        public float $amountTtc,
        public PaymentStatus $paymentStatus = PaymentStatus::Unpaid,
        public ?PaymentMethod $paymentMethod = null,
        public ?DateTimeImmutable $paymentDate = null,
        public ?DateTimeImmutable $cancelledAt = null,
        public ?string $note = null,
        public ?array $snapshot = null,
        public InvoiceSource $source = InvoiceSource::Casamiza,
        public ?int $legacyId = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?string $pdfPath = null,
        public ?string $recapPdfPath = null,
        public ?string $pdfSha256 = null,
    ) {}

    /** @param array<string, mixed> $snapshot */
    public function withSnapshot(array $snapshot): self
    {
        return new self(...[...get_object_vars($this), 'snapshot' => $snapshot]);
    }

    /** Facture payée (mode et jour du paiement) ou remise à « non payée » (les deux effacés). */
    public function withPayment(PaymentStatus $status, ?PaymentMethod $method, ?DateTimeImmutable $date): self
    {
        return new self(...[...get_object_vars($this), 'paymentStatus' => $status, 'paymentMethod' => $method, 'paymentDate' => $date]);
    }

    /** Numéro imprimé : `FZ` et 7 chiffres (la numérotation de Facturiza continue). */
    public static function formatNumber(int $number): string
    {
        return sprintf('FZ%07d', $number);
    }

    public function displayNumber(): string
    {
        return self::formatNumber($this->number);
    }

    public function isCancelled(): bool
    {
        return $this->cancelledAt !== null;
    }

    /** Non payée, non annulée et échue à `$today`. */
    public function isOverdue(DateTimeImmutable $today): bool
    {
        return $this->paymentStatus === PaymentStatus::Unpaid && !$this->isCancelled() && $this->dueDate < $today->setTime(0, 0);
    }
}
