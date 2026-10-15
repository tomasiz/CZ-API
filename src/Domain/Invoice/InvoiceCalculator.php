<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice;

use Casamiza\Domain\Extra\Extra;
use Casamiza\Domain\Extra\ExtraType;
use Casamiza\Domain\Reservation\Platform;
use Casamiza\Shared\Rounding;
use LogicException;

/**
 * Calcule les lignes et les totaux d'une facture (FACTURIZA.md §5.2, §5.3), sans aucun accès aux données.
 *
 * Le TTC de chaque ligne est la donnée. Le total se calcule **par taux de TVA, en une fois** : HT = Σ TTC / (1 + taux)
 * arrondi au centime (ce que fait Facturiza : mêmes totaux), TVA = Σ TTC − HT. Le HT de chaque ligne est ensuite réparti
 * pour que leur somme retombe exactement sur ce HT (plus fort reste, égalités départagées par l'ordre des lignes) : tout
 * ce qui est imprimé s'additionne. Tout le calcul se fait en centimes entiers, sans flottant.
 *
 * Lignes, dans l'ordre : pour chaque logement, chaque réservation donne ses frais de conciergerie, la commission
 * refacturée d'Abritel s'il y a lieu, puis le linge et le ménage du logement ; viennent ensuite les prestations
 * (conciergerie, linge, ménage, dans l'ordre de saisie). Les frais de linge et de ménage sont facturés pour chaque
 * réservation, mais un logement qui n'en a pas (0 €) ne produit aucune ligne.
 */
final class InvoiceCalculator
{
    /** TVA des frais de conciergerie, de linge et de ménage (celle de Facturiza : 20 % sur tout). */
    public const RESERVATION_VAT_RATE = 20.0;

    /**
     * @param list<PropertyBilling> $properties
     * @param list<Extra> $extras
     */
    public function build(array $properties, array $extras): InvoiceDraft
    {
        $lines = [];
        $activity = [];
        $add = static function (InvoiceLineKind $kind, ExtraType $category, ?string $propertyId, ?string $reservationId, ?string $extraId, string $label, float $ttc, float $rate) use (&$lines): void {
            $lines[] = new DraftLine(count($lines) + 1, $kind, $category, $propertyId, $reservationId, $extraId, $label, Rounding::cents($ttc), $rate);
        };

        foreach ($properties as $billing) {
            $propertyId = $billing->property->id;
            foreach ($billing->reservations as $reservation) {
                $activity[] = DraftReservation::from($reservation);
                $add(InvoiceLineKind::Reservation, ExtraType::Concierge, $propertyId, $reservation->id, null, '', $reservation->conciergeFee, self::RESERVATION_VAT_RATE);
                if ($reservation->platform === Platform::Abritel) {
                    $add(InvoiceLineKind::PlatformCommission, ExtraType::Concierge, $propertyId, $reservation->id, null, '', $reservation->channelCommission, self::RESERVATION_VAT_RATE);
                }
                if ($billing->property->linenFee !== 0.0) {
                    $add(InvoiceLineKind::Reservation, ExtraType::Linen, $propertyId, $reservation->id, null, '', $billing->property->linenFee, self::RESERVATION_VAT_RATE);
                }
                if ($billing->property->cleaningFee !== 0.0) {
                    $add(InvoiceLineKind::Reservation, ExtraType::Cleaning, $propertyId, $reservation->id, null, '', $billing->property->cleaningFee, self::RESERVATION_VAT_RATE);
                }
            }
        }
        foreach ([ExtraType::Concierge, ExtraType::Linen, ExtraType::Cleaning] as $type) {
            foreach ($extras as $extra) {
                if ($extra->type === $type) {
                    $add(InvoiceLineKind::Extra, $type, null, null, $extra->id, $extra->description, $extra->priceTtc, $extra->vatRate);
                }
            }
        }

        [$lines, $totals] = $this->allocate($lines);

        return new InvoiceDraft($lines, $activity, $totals);
    }

    /**
     * @param list<DraftLine> $lines
     * @return array{list<DraftLine>, InvoiceTotals}
     */
    private function allocate(array $lines): array
    {
        $groups = [];
        foreach ($lines as $index => $line) {
            $groups[(int) round($line->vatRate * 100)][] = $index;
        }

        $htCents = [];
        $totalHt = 0;
        $totalTtc = 0;
        foreach ($groups as $rate => $indexes) {
            $denominator = 10_000 + $rate;
            $floors = [];
            $remainders = [];
            $numerators = 0;
            foreach ($indexes as $index) {
                $ttc = self::cents($lines[$index]->priceTtc);
                $numerators += $ttc * 10_000;
                $floors[$index] = self::floorDiv($ttc * 10_000, $denominator);
                $remainders[$index] = $ttc * 10_000 - $floors[$index] * $denominator;
                $totalTtc += $ttc;
            }
            $groupHt = self::roundDiv($numerators, $denominator);
            $missing = $groupHt - array_sum($floors);
            $count = count($indexes);
            if ($missing < 0 || $missing > $count) {
                throw new LogicException('Cannot spread the cents of the excl. tax total over the lines.');
            }

            // Les lignes au plus fort reste reçoivent le centime manquant ; à égalité, la première dans l'ordre de la facture.
            $order = $indexes;
            usort($order, static fn(int $a, int $b): int => [$remainders[$b], $a] <=> [$remainders[$a], $b]);
            foreach ($order as $rank => $index) {
                $htCents[$index] = $floors[$index] + intdiv($missing, $count) + ($rank < $missing % $count ? 1 : 0);
            }
            $totalHt += $groupHt;
        }

        $allocated = [];
        foreach ($lines as $index => $line) {
            $allocated[] = $line->withPriceHt($htCents[$index] / 100);
        }

        return [$allocated, new InvoiceTotals($totalHt / 100, ($totalTtc - $totalHt) / 100, $totalTtc / 100)];
    }

    private static function cents(float $euros): int
    {
        return (int) round(Rounding::cents($euros) * 100);
    }

    /** Division entière arrondie vers le bas, y compris pour un numérateur négatif (avoir). */
    private static function floorDiv(int $numerator, int $denominator): int
    {
        $quotient = intdiv($numerator, $denominator);

        return $numerator % $denominator < 0 ? $quotient - 1 : $quotient;
    }

    /** Division arrondie au plus proche, la moitié s'éloignant de zéro (comme `round()`). */
    private static function roundDiv(int $numerator, int $denominator): int
    {
        $quotient = intdiv(2 * abs($numerator) + $denominator, 2 * $denominator);

        return $numerator < 0 ? -$quotient : $quotient;
    }
}
