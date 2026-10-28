<?php

declare(strict_types=1);

namespace Casamiza\Domain\Reservation;

use Casamiza\Shared\Rounding;

/**
 * Montants d'une réservation à partir des valeurs brutes de Guesty. Service pur (aucune I/O) : les frais Stripe, que seul
 * un appel réseau peut donner, lui sont passés.
 *
 * Reprend les règles de Facturiza (FACTURIZA.md §4.5 à §4.8), dans le même ordre et avec les mêmes arrondis (`round`, demi
 * supérieur). Les deux seules différences voulues : les frais de conciergerie ne sont **jamais négatifs**, et les deux
 * exceptions codées en dur de Facturiza ne sont pas reprises.
 *
 * Un signalement par champ : le dernier posé l'emporte (comme dans Facturiza).
 */
final readonly class ReservationCalculator
{
    /** Commission Abritel retenue quand Guesty n'en fournit pas. */
    private const ABRITEL_COMMISSION_RATE = 0.18;

    /** Frais de paiement Booking.com estimés sur le total payé, quand aucun paiement n'est exploitable. */
    private const BOOKING_PAYMENT_FEE_RATE = 0.014;

    /** Écart (part du total) au-delà duquel la taxe déduite des paiements Booking.com est jugée incohérente. */
    private const BOOKING_TAX_GAP_LIMIT = 0.10;

    /** Taxe de séjour (part du total) au-delà de laquelle elle est jugée incohérente. */
    private const TAX_LIMIT = 0.07;

    /**
     * @param float $commission commission de conciergerie du propriétaire, en % (à défaut, celle de l'entreprise)
     * @param float $propertyCleaningFee coût de ménage du logement : sert uniquement à signaler un ménage manquant
     * @param ?StripeFees $stripe frais lus chez Stripe, null si aucune lecture n'a été faite
     * @param float $propertyLinenFee coût de linge du logement : le ménage facturé au voyageur doit valoir ménage + linge
     */
    public function calculate(Reservation $reservation, float $commission, float $propertyCleaningFee, ?StripeFees $stripe = null, float $propertyLinenFee = 0.0): CalculatedAmounts
    {
        if ($reservation->platform === Platform::Owner) {
            return CalculatedAmounts::zero();
        }

        $platform = $reservation->platform;
        /** @var array<string, FlagReason> $flags */
        $flags = [];

        $cleaningFee = $reservation->fareCleaning;
        if ($cleaningFee == 0) {
            $cleaningFee = $reservation->totalFees;
        }
        $totalPayout = $reservation->hostPayout;
        $channelCommission = $reservation->hostServiceFee;
        $paymentFee = $reservation->totalPaymentFees;
        $tax = $reservation->totalTaxes;

        if ($channelCommission == 0 && $platform === Platform::Abritel) {
            $channelCommission = Rounding::cents(self::ABRITEL_COMMISSION_RATE * ($totalPayout - $tax));
        }
        if ($channelCommission == 0 && $platform !== Platform::Direct && $platform !== Platform::Website) {
            $flags[FinancialField::ChannelCommission->value] = FlagReason::EmptyOrZeroFromGuesty;
        }

        if ($paymentFee == 0) {
            // Aucun frais chez Guesty : peut-être un paiement Stripe.
            $paymentFee = 0.0;
            if ($stripe !== null) {
                $paymentFee = $stripe->amount;
                if ($stripe->errorCode !== null) {
                    $flags[FinancialField::PaymentFee->value] = FlagReason::StripeError;
                }
            }
        }

        if ($platform === Platform::Booking) {
            // Booking.com : à 0, la taxe a peut-être été encaissée avant, et `hostPayout` est alors trop bas.
            if ($tax == 0) {
                $paid = self::totalPaid($reservation->payments);
                if ($paid > $totalPayout) {
                    $cityTax = $paid - $totalPayout;
                    if ($cityTax >= self::BOOKING_TAX_GAP_LIMIT * $totalPayout) {
                        // Méthode peu fiable : taxe recalculée.
                        [$cityTax, $reason] = self::computedCityTax($reservation);
                        $flags[FinancialField::TouristTax->value] = $reason;
                        $totalPayout += $cityTax;
                    } else {
                        $totalPayout = $paid;
                    }
                    $tax = $cityTax;
                } elseif ($paid == 0) {
                    [$cityTax, $reason] = self::computedCityTax($reservation);
                    $flags[FinancialField::TouristTax->value] = $reason;
                    $totalPayout += $cityTax;
                    $tax = $cityTax;
                    if ($paymentFee == 0) {
                        $paymentFee = Rounding::cents(self::BOOKING_PAYMENT_FEE_RATE * $totalPayout);
                        $flags[FinancialField::PaymentFee->value] = FlagReason::PaymentFeeComputed1_4Percent;
                    }
                }
            } elseif ($tax > 0) {
                $flags[FinancialField::TouristTax->value] = FlagReason::PossibleVat;
            }
        }

        if ($platform === Platform::Airbnb && $tax == 0) {
            // Airbnb : la taxe est dans `transientOccupancyTax` et n'est pas dans `hostPayout`.
            $cityTax = $reservation->transientOccupancyTax;
            if ($cityTax >= ($reservation->hostPayout + $reservation->hostServiceFee) * self::TAX_LIMIT) {
                [$cityTax, $reason] = self::computedCityTax($reservation);
                $flags[FinancialField::TouristTax->value] = $reason;
            }
            $totalPayout = $reservation->hostPayout + $reservation->hostServiceFee + $cityTax;
            $tax = $cityTax;
            // Les frais de paiement sont inclus dans la commission Airbnb.
            $paymentFee = 0.0;
        }

        if ($cleaningFee == 0 && $propertyCleaningFee != 0) {
            $flags[FinancialField::CleaningFee->value] = FlagReason::EmptyOrZeroFromGuesty;
        } elseif (Rounding::cents($cleaningFee) !== Rounding::cents($propertyCleaningFee + $propertyLinenFee)) {
            $flags[FinancialField::CleaningFee->value] = FlagReason::CleaningFeeMismatch;
        }

        if ($platform->isRetrocommission()) {
            $netPayout = Rounding::cents($totalPayout - $paymentFee);
            $conciergeFee = Rounding::cents($commission / 100 * ($netPayout - $cleaningFee - $channelCommission - $tax));
            $conciergeFee = max(0.0, $conciergeFee);
            $netOwnerRevenue = Rounding::cents($netPayout - $cleaningFee - $conciergeFee - $channelCommission - $tax);
        } else {
            $netPayout = Rounding::cents($totalPayout - $channelCommission - $paymentFee - $tax);
            $conciergeFee = Rounding::cents($commission / 100 * ($netPayout - $cleaningFee));
            $conciergeFee = max(0.0, $conciergeFee);
            $netOwnerRevenue = Rounding::cents($netPayout - $cleaningFee - $conciergeFee);
        }

        if ($conciergeFee == 0 && $commission != 0) {
            $flags[FinancialField::ConciergeFee->value] = FlagReason::ConciergeFeeZero;
        }
        if ($paymentFee == 0 && $platform !== Platform::Website && $platform !== Platform::Direct && $platform !== Platform::Airbnb) {
            $flags[FinancialField::PaymentFee->value] = FlagReason::EmptyOrZeroFromGuesty;
        }
        if ($tax == 0 || $tax >= $totalPayout * self::TAX_LIMIT) {
            $flags[FinancialField::TouristTax->value] = FlagReason::EmptyOrZeroFromGuesty;
        }
        if ($totalPayout == 0) {
            $flags[FinancialField::TotalPayout->value] = FlagReason::EmptyOrZeroFromGuesty;
        }

        $flagList = [];
        foreach ($flags as $field => $reason) {
            $flagList[] = new ReservationFlag(FinancialField::from($field), $reason);
        }

        // Arrondis à la fin seulement (les calculs ci-dessus utilisent les valeurs exactes, comme Facturiza) : ce sont les
        // montants stockés en DECIMAL(12,2), qui doivent se relire à l'identique.
        return new CalculatedAmounts(
            Rounding::cents($totalPayout),
            Rounding::cents($channelCommission),
            Rounding::cents($paymentFee),
            Rounding::cents($tax),
            Rounding::cents($cleaningFee),
            $netPayout,
            $conciergeFee,
            $netOwnerRevenue,
            $flagList,
        );
    }

    /**
     * Paiements Stripe réussis du séjour (code de confirmation `pi_…`) : leurs frais se lisent chez Stripe.
     *
     * @return list<string>
     */
    public static function paymentIntentIds(Reservation $reservation): array
    {
        $ids = [];
        foreach ($reservation->payments as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            $code = $payment['confirmationCode'] ?? null;
            if (($payment['status'] ?? null) === 'SUCCEEDED' && is_string($code) && str_contains($code, 'pi_')) {
                $ids[] = $code;
            }
        }

        return $ids;
    }

    /**
     * Somme des paiements du séjour, hors échoués ou annulés.
     *
     * @param array<array-key, mixed> $payments
     */
    private static function totalPaid(array $payments): float
    {
        $total = 0.0;
        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            $status = $payment['status'] ?? null;
            if ($status !== 'FAILED' && $status !== 'CANCELLED' && is_numeric($payment['amount'] ?? null)) {
                $total += (float) $payment['amount'];
            }
        }

        return $total;
    }

    /**
     * Taxe de séjour recalculée d'après la première taxe `CITY_TAX` de la réservation à sa confirmation : par voyageur et
     * par nuit si elle est fixe, sinon en pourcentage de la base d'hébergement. Sans taxe de séjour : 0.
     *
     * @return array{float, FlagReason}
     */
    private static function computedCityTax(Reservation $reservation): array
    {
        foreach ($reservation->confirmationTaxes as $tax) {
            if (!is_array($tax) || ($tax['type'] ?? null) !== 'CITY_TAX') {
                continue;
            }
            $amount = is_numeric($tax['amount'] ?? null) ? (float) $tax['amount'] : 0.0;
            if (($tax['units'] ?? null) === 'FIXED' && ($tax['quantifier'] ?? null) === 'PER_GUEST_PER_NIGHT') {
                return [$amount * $reservation->guests * $reservation->nights, FlagReason::TaxComputedPerGuestNight];
            }

            return [Rounding::cents($reservation->fareAccommodationAdjusted * $amount / 100), FlagReason::TaxComputedPercent];
        }

        return [0.0, FlagReason::EmptyOrZeroFromGuesty];
    }
}
