<?php

declare(strict_types=1);

namespace Casamiza\Domain\Reservation;

use Casamiza\Domain\Guesty\GuestyReservation;
use DateTimeImmutable;

/**
 * Réservation confirmée de Guesty, rattachée au mois de son check-out.
 *
 * Deux jeux de montants : les valeurs **brutes** de Guesty (conservées pour pouvoir recalculer sans le rappeler) et les
 * montants **calculés** (corrections par plateforme, puis formule classique ou rétrocommission selon `platform`).
 * Les montants calculés sont recalculés à chaque synchronisation tant que le mois n'est pas facturé, puis figés par la
 * facture. Un séjour propriétaire (`Platform::Owner`) a tous ses montants calculés à 0.
 *
 * Les frais lus chez Stripe (`stripeFee`, `stripeError`) sont mémorisés pour ne pas rappeler Stripe à chaque synchronisation.
 */
final readonly class Reservation
{
    /**
     * @param non-empty-string $id
     * @param list<string> $integrationKeys clés de l'objet `integration` de Guesty
     * @param array<array-key, mixed> $payments `money.payments` de Guesty
     * @param array<array-key, mixed> $confirmationTaxes `atTimeOfConfirmation.taxes` de Guesty
     * @param list<ReservationFlag> $flags
     * @param ?float $stripeFee frais lus chez Stripe ; null tant qu'ils n'ont pas été lus
     * @param ?string $stripeError code de la dernière erreur de lecture Stripe ; la lecture est retentée tant qu'il est posé
     */
    public function __construct(
        public string $id,
        public string $guestyId,
        public ?string $ownerId,
        public string $propertyId,
        public Platform $platform,
        public string $source,
        public array $integrationKeys,
        public string $platformRef,
        public string $guest,
        public DateTimeImmutable $checkIn,
        public DateTimeImmutable $checkOut,
        public int $nights,
        public int $guests,
        // Montants bruts Guesty
        public float $hostPayout,
        public float $hostServiceFee,
        public float $totalPaymentFees,
        public float $totalTaxes,
        public float $fareCleaning,
        public float $totalFees,
        public float $transientOccupancyTax,
        public float $fareAccommodationAdjusted,
        public array $payments,
        public array $confirmationTaxes,
        // Montants calculés
        public float $totalPayout,
        public float $channelCommission,
        public float $paymentFee,
        public float $touristTax,
        public float $cleaningFee,
        public float $netPayout,
        public float $conciergeFee,
        public float $netOwnerRevenue,
        public ?DateTimeImmutable $archivedAt = null,
        public array $flags = [],
        public ?float $stripeFee = null,
        public ?string $stripeError = null,
    ) {}

    /**
     * Nouvelle réservation : montants bruts de Guesty, montants calculés à 0 (le calculateur les remplit).
     *
     * @param non-empty-string $id
     */
    public static function fromGuesty(string $id, GuestyReservation $guesty, ?string $ownerId, string $propertyId): self
    {
        return new self(
            id: $id,
            guestyId: $guesty->guestyId,
            ownerId: $ownerId,
            propertyId: $propertyId,
            platform: Platform::fromGuestySource($guesty->source),
            source: $guesty->source,
            integrationKeys: $guesty->integrationKeys,
            platformRef: $guesty->platformRef,
            guest: $guesty->guest,
            checkIn: $guesty->checkIn,
            checkOut: $guesty->checkOut,
            nights: $guesty->nights,
            guests: $guesty->guests,
            hostPayout: $guesty->hostPayout,
            hostServiceFee: $guesty->hostServiceFee,
            totalPaymentFees: $guesty->totalPaymentFees,
            totalTaxes: $guesty->totalTaxes,
            fareCleaning: $guesty->fareCleaning,
            totalFees: $guesty->totalFees,
            transientOccupancyTax: $guesty->transientOccupancyTax,
            fareAccommodationAdjusted: $guesty->fareAccommodationAdjusted,
            payments: $guesty->payments,
            confirmationTaxes: $guesty->confirmationTaxes,
            totalPayout: 0.0,
            channelCommission: 0.0,
            paymentFee: 0.0,
            touristTax: 0.0,
            cleaningFee: 0.0,
            netPayout: 0.0,
            conciergeFee: 0.0,
            netOwnerRevenue: 0.0,
        );
    }

    /**
     * Valeurs de Guesty réécrites ; montants calculés, signalements et frais Stripe conservés (sauf si les paiements ou les
     * frais de Guesty ont changé : la lecture Stripe est alors à refaire) ; rétablie si elle était archivée.
     */
    public function mergedWith(GuestyReservation $guesty, ?string $ownerId, string $propertyId): self
    {
        $fresh = self::fromGuesty($this->id, $guesty, $ownerId, $propertyId);
        $stripeChanged = $guesty->payments != $this->payments || $guesty->totalPaymentFees !== $this->totalPaymentFees;

        return $fresh->with([
            'totalPayout' => $this->totalPayout,
            'channelCommission' => $this->channelCommission,
            'paymentFee' => $this->paymentFee,
            'touristTax' => $this->touristTax,
            'cleaningFee' => $this->cleaningFee,
            'netPayout' => $this->netPayout,
            'conciergeFee' => $this->conciergeFee,
            'netOwnerRevenue' => $this->netOwnerRevenue,
            'flags' => $this->flags,
            'stripeFee' => $stripeChanged ? null : $this->stripeFee,
            'stripeError' => $stripeChanged ? null : $this->stripeError,
        ]);
    }

    /** Montants calculés et signalements remplacés par ceux du calculateur ; le reste est inchangé. */
    public function withCalculation(CalculatedAmounts $amounts): self
    {
        return $this->with([
            'totalPayout' => $amounts->totalPayout,
            'channelCommission' => $amounts->channelCommission,
            'paymentFee' => $amounts->paymentFee,
            'touristTax' => $amounts->touristTax,
            'cleaningFee' => $amounts->cleaningFee,
            'netPayout' => $amounts->netPayout,
            'conciergeFee' => $amounts->conciergeFee,
            'netOwnerRevenue' => $amounts->netOwnerRevenue,
            'flags' => $amounts->flags,
        ]);
    }

    /** Frais lus chez Stripe, mémorisés (avec le code de l'erreur de lecture éventuelle). */
    public function withStripeFees(StripeFees $fees): self
    {
        return $this->with(['stripeFee' => $fees->amount, 'stripeError' => $fees->errorCode]);
    }

    /** Frais Stripe déjà lus, à passer au calculateur ; null si rien n'a été lu. */
    public function storedStripeFees(): ?StripeFees
    {
        return $this->stripeFee === null ? null : new StripeFees($this->stripeFee, $this->stripeError);
    }

    public function archived(DateTimeImmutable $now): self
    {
        return $this->with(['archivedAt' => $this->archivedAt ?? $now]);
    }

    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    /**
     * Vrai si les deux réservations ne diffèrent que par la date d'archivage exacte (archivées des deux côtés) et l'ordre de
     * leurs signalements.
     */
    public function sameAs(self $other): bool
    {
        return $this->isArchived() === $other->isArchived()
            && $this->comparable() == $other->comparable();
    }

    private function comparable(): self
    {
        $flags = $this->flags;
        usort($flags, static fn(ReservationFlag $a, ReservationFlag $b): int => $a->field->value <=> $b->field->value);

        return $this->with(['archivedAt' => null, 'flags' => $flags]);
    }

    /** @param array<string, mixed> $changes propriétés à remplacer, par nom */
    private function with(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }
}
