<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice;

use Casamiza\Domain\Extra\ExtraType;
use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Reservation\Platform;
use Casamiza\Domain\Reservation\Reservation;

/**
 * Instantané légal d'une facture (FACTURIZA.md §5.6) : la même structure que le JSON de Facturiza, pour rester compatible
 * avec l'export comptable. Les montants sont ceux **calculés** par `InvoiceCalculator` (HT réparti compris), jamais recalculés.
 * Par rapport à Facturiza : pas de « P - » devant le nom, et les signalements d'une réservation (`flags`) portent le nom du
 * champ et le code du motif de Casamiza.
 */
final class InvoiceSnapshotBuilder
{
    private const DATE = 'Y-m-d';
    private const INSTANT = 'Y-m-d\TH:i:s.v\Z';

    /**
     * @param list<PropertyBilling> $properties
     * @return array<string, mixed>
     */
    public function build(Invoice $invoice, Owner $owner, array $properties, InvoiceDraft $draft): array
    {
        $byReservation = [];
        $extras = ['concierge' => [], 'linen' => [], 'cleaning' => []];
        $categories = [];
        foreach ($draft->lines as $line) {
            $categories[$line->category->value][] = $line;
            if ($line->kind === InvoiceLineKind::Extra) {
                $extras[$line->category->value][] = [
                    'description' => $line->label,
                    'Montant_HT' => $line->priceHt,
                    'TVA' => $line->vatRate,
                    'Montant_TTC' => $line->priceTtc,
                ];
            } elseif ($line->reservationId !== null) {
                $byReservation[$line->reservationId][] = $line;
            }
        }

        $listings = [];
        foreach ($properties as $billing) {
            $reservations = [];
            foreach ($billing->reservations as $reservation) {
                $reservations[] = [
                    'reservation_details' => $this->details($reservation, $billing->property->guestyId),
                    'facturation_details' => $this->billing($byReservation[$reservation->id] ?? []),
                ];
            }
            $listings[] = [
                'listing_nickname' => $billing->property->name,
                'listing_guesty_id' => $billing->property->guestyId,
                'listing_reservations' => $reservations,
            ];
        }

        return [
            'facture_information' => [
                'numero_facture' => $invoice->displayNumber(),
                'devise' => 'EUR',
                'date_facture' => $invoice->invoiceDate->format(self::DATE),
                'periode_start' => $invoice->periodStart->format(self::DATE),
                'periode_end' => $invoice->periodEnd->format(self::DATE),
                'note' => $invoice->note ?? '',
                'client' => [
                    'client_name' => $owner->name,
                    'client_siret' => $owner->siret === '' ? null : $owner->siret,
                    'client_legal_name' => $owner->companyName === '' ? null : $owner->companyName,
                    'client_address' => $owner->address,
                ],
            ],
            'facturation_par_listing' => $listings,
            'facturation_supplementaire' => ['conciergerie' => $extras['concierge'], 'linge' => $extras['linen'], 'menage' => $extras['cleaning']],
            'totaux_par_categorie' => [
                'total_conciergerie' => $this->total($categories['concierge'] ?? []),
                'total_linge' => $this->total($categories['linen'] ?? []),
                'total_menage' => $this->total($categories['cleaning'] ?? []),
            ],
            'facture_totaux' => ['total_ht' => $draft->totals->ht, 'total_tva' => $draft->totals->vat, 'total_ttc' => $draft->totals->ttc],
        ];
    }

    /** @return array<string, mixed> */
    private function details(Reservation $reservation, string $listingGuestyId): array
    {
        $flags = [];
        foreach ($reservation->flags as $flag) {
            $flags[$flag->field->value] = $flag->reason->value;
        }
        $details = [
            'guesty_res_id' => $reservation->guestyId,
            'guesty_listing_id' => $listingGuestyId,
            'checkin' => $reservation->checkIn->format(self::INSTANT),
            'checkout' => $reservation->checkOut->format(self::INSTANT),
            'guest_fullname' => $reservation->guest,
            'channel' => self::channel($reservation->platform),
            'ref_id' => $reservation->platformRef === '' ? null : $reservation->platformRef,
            'guests_count' => $reservation->guests,
            'nights_count' => $reservation->nights,
            'total_payout' => $reservation->totalPayout,
            'channel_comission' => $reservation->channelCommission,
            'paiement_fee' => $reservation->paymentFee,
            'taxes' => $reservation->touristTax,
            'cleaning_fee' => $reservation->cleaningFee,
            'net_payout' => $reservation->netPayout,
            'conciergerie_fee' => $reservation->conciergeFee,
            'net_owner_revenue' => $reservation->netOwnerRevenue,
        ];
        if ($flags !== []) {
            $details['flags'] = $flags;
        }

        return $details;
    }

    /**
     * Frais de la réservation, tels que facturés ; une rubrique sans ligne (linge ou ménage à 0 €) vaut 0.
     *
     * @param list<DraftLine> $lines
     * @return array<string, mixed>
     */
    private function billing(array $lines): array
    {
        $zero = ['Montant_HT' => 0.0, 'TVA' => InvoiceCalculator::RESERVATION_VAT_RATE, 'Montant_TTC' => 0.0];
        $billing = ['frais_conciergerie' => $zero, 'frais_linge' => $zero, 'frais_menage' => $zero, 'frais_comission_plateforme' => []];
        foreach ($lines as $line) {
            $amounts = ['Montant_HT' => $line->priceHt, 'TVA' => $line->vatRate, 'Montant_TTC' => $line->priceTtc];
            if ($line->kind === InvoiceLineKind::PlatformCommission) {
                $billing['frais_comission_plateforme'] = $amounts;
            } else {
                $billing[match ($line->category) {
                    ExtraType::Concierge => 'frais_conciergerie',
                    ExtraType::Linen => 'frais_linge',
                    ExtraType::Cleaning => 'frais_menage',
                }] = $amounts;
            }
        }

        return $billing;
    }

    /**
     * Total d'une catégorie ; la TVA n'est indiquée que si toutes ses lignes ont le même taux.
     *
     * @param list<DraftLine> $lines
     * @return array{Montant_HT: float, TVA: float|null, Montant_TTC: float}
     */
    private function total(array $lines): array
    {
        $ht = 0;
        $ttc = 0;
        $rates = [];
        foreach ($lines as $line) {
            $ht += (int) round($line->priceHt * 100);
            $ttc += (int) round($line->priceTtc * 100);
            $rates[(string) $line->vatRate] = $line->vatRate;
        }

        return [
            'Montant_HT' => $ht / 100,
            'TVA' => count($rates) === 1 ? reset($rates) : ($rates === [] ? InvoiceCalculator::RESERVATION_VAT_RATE : null),
            'Montant_TTC' => $ttc / 100,
        ];
    }

    /** Libellés de plateforme de Facturiza (conservés dans le JSON pour l'export comptable). */
    private static function channel(Platform $platform): string
    {
        return match ($platform) {
            Platform::Booking => 'Booking.com',
            Platform::Airbnb => 'AirBnB',
            Platform::Abritel => 'Abritel',
            Platform::Website => 'Site internet',
            Platform::Manual => 'Manuel',
            Platform::Direct, Platform::Owner => 'En direct',
        };
    }
}
