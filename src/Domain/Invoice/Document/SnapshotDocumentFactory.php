<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice\Document;

use Casamiza\Shared\Rounding;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Compose les documents imprimés (facture et récapitulatif) à partir de l'**instantané légal** d'une facture
 * (FACTURIZA.md §5.6), donc aussi bien pour une facture émise dans Casamiza que pour une facture reprise de Facturiza :
 * même structure. Rien n'est recalculé, sauf ce que Facturiza calculait déjà à l'impression (prix unitaires du linge et
 * du ménage regroupés par logement). Dates et heures s'impriment à l'heure de Paris. Le préfixe « P - » des noms de
 * propriétaires de Guesty n'est jamais imprimé.
 */
final class SnapshotDocumentFactory
{
    private const DISPLAY_TIMEZONE = 'Europe/Paris';
    private const RETROCOMMISSION_CHANNELS = ['Abritel', 'Site internet', 'Manuel'];
    private const PLATFORM_COMMISSION_LABEL = 'Commission Abritel (18%)';
    private const DEFAULT_VAT_RATE = 20.0;

    /**
     * @param array<string, mixed> $snapshot
     * @throws InvalidArgumentException si l'instantané n'a pas la structure attendue
     */
    public function invoice(array $snapshot, DateTimeImmutable $dueDate): InvoiceDocument
    {
        $information = self::map($snapshot, 'facture_information');
        $totals = self::map($snapshot, 'facture_totaux');

        $concierge = [];
        $linen = [];
        $cleaning = [];
        $listings = self::list($snapshot, 'facturation_par_listing');
        $showNames = count($listings) > 1;
        foreach ($listings as $listing) {
            $nickname = self::string($listing, 'listing_nickname');
            $reservations = self::list($listing, 'listing_reservations');
            if ($showNames && $reservations !== []) {
                $concierge[] = new InvoiceHeadingRow($nickname);
            }

            foreach ($reservations as $reservation) {
                $billing = self::map($reservation, 'facturation_details');
                $details = self::map($reservation, 'reservation_details');
                $fee = self::map($billing, 'frais_conciergerie');
                $concierge[] = new InvoiceItemRow(
                    'Du ' . self::day($details, 'checkin') . ' au ' . self::day($details, 'checkout'),
                    1,
                    self::float($fee, 'Montant_HT'),
                    self::float($fee, 'TVA'),
                    self::float($fee, 'Montant_TTC'),
                    self::float($fee, 'Montant_TTC'),
                );
                $commission = self::map($billing, 'frais_comission_plateforme');
                if ($commission !== []) {
                    $concierge[] = new InvoiceItemRow(
                        self::PLATFORM_COMMISSION_LABEL,
                        1,
                        self::float($commission, 'Montant_HT'),
                        self::float($commission, 'TVA'),
                        self::float($commission, 'Montant_TTC'),
                        self::float($commission, 'Montant_TTC'),
                    );
                }
            }

            // Linge et ménage se facturent par logement : le coût du logement (identique pour toutes ses réservations) × le nombre de séjours.
            if ($reservations !== []) {
                $unitCosts = self::map($reservations[0], 'facturation_details');
                $linen[] = self::perProperty($nickname . ' - Prestation de linge', count($reservations), self::map($unitCosts, 'frais_linge'));
                $cleaning[] = self::perProperty($nickname . ' - Prestation de ménage', count($reservations), self::map($unitCosts, 'frais_menage'));
            }
        }

        $extras = self::map($snapshot, 'facturation_supplementaire');
        $conciergeExtras = self::list($extras, 'conciergerie');
        if ($conciergeExtras !== []) {
            $concierge[] = new InvoiceHeadingRow('Prestations supplémentaires');
        }
        foreach ($conciergeExtras as $extra) {
            $concierge[] = self::extra($extra);
        }
        foreach (self::list($extras, 'linge') as $extra) {
            $linen[] = self::extra($extra);
        }
        foreach (self::list($extras, 'menage') as $extra) {
            $cleaning[] = self::extra($extra);
        }

        $totalHt = self::float($totals, 'total_ht');
        $totalVat = self::float($totals, 'total_tva');
        $client = self::map($information, 'client');

        return new InvoiceDocument(
            number: self::string($information, 'numero_facture'),
            date: self::date($information, 'date_facture'),
            dueDate: $dueDate,
            periodStart: self::date($information, 'periode_start'),
            periodEnd: self::date($information, 'periode_end'),
            note: self::string($information, 'note'),
            recipient: self::recipient($client),
            sections: [
                new InvoiceSection('Frais de conciergerie', $concierge),
                new InvoiceSection('Frais de linge', $linen),
                new InvoiceSection('Frais de ménage', $cleaning),
            ],
            vat: $this->vatBreakdown([...$concierge, ...$linen, ...$cleaning], $totalHt, $totalVat),
            totalHt: $totalHt,
            totalVat: $totalVat,
            totalTtc: self::float($totals, 'total_ttc'),
        );
    }

    /**
     * @param array<string, mixed> $snapshot
     * @throws InvalidArgumentException si l'instantané n'a pas la structure attendue
     */
    public function recap(array $snapshot): RecapDocument
    {
        $information = self::map($snapshot, 'facture_information');

        $cards = [];
        foreach (self::list($snapshot, 'facturation_par_listing') as $listing) {
            $nickname = self::string($listing, 'listing_nickname');
            foreach (self::list($listing, 'listing_reservations') as $reservation) {
                $details = self::map($reservation, 'reservation_details');
                $channel = self::string($details, 'channel');
                $guests = (int) self::float($details, 'guests_count');
                $nights = (int) self::float($details, 'nights_count');
                $cards[] = new RecapCard(
                    title: $nickname . ' - Réservation du ' . self::day($details, 'checkin') . ' au ' . self::day($details, 'checkout'),
                    subtitle: 'Réf. ' . self::string($details, 'ref_id') . ' ' . $channel . ' - ' . self::string($details, 'guest_fullname')
                        . ' - ' . $guests . ($guests === 1 ? ' voyageur' : ' voyageurs') . ' - ' . $nights . ($nights === 1 ? ' nuit' : ' nuits'),
                    retrocommission: in_array($channel, self::RETROCOMMISSION_CHANNELS, true),
                    totalPaid: self::float($details, 'total_payout'),
                    channelCommission: self::float($details, 'channel_comission'),
                    paymentFee: self::float($details, 'paiement_fee'),
                    touristTax: self::float($details, 'taxes'),
                    netPayout: self::float($details, 'net_payout'),
                    cleaningFee: self::float($details, 'cleaning_fee'),
                    conciergeFee: self::float($details, 'conciergerie_fee'),
                    netOwnerRevenue: self::float($details, 'net_owner_revenue'),
                );
            }
        }

        return new RecapDocument(
            periodStart: self::date($information, 'periode_start'),
            periodEnd: self::date($information, 'periode_end'),
            recipient: self::recipient(self::map($information, 'client')),
            cards: $cards,
        );
    }

    /**
     * Détail de la TVA : une ligne par taux. Avec un seul taux (le cas de toute facture de Facturiza), les totaux légaux
     * sont repris tels quels. Avec plusieurs, le TTC de chaque taux (la donnée) donne son HT, et l'écart d'arrondi éventuel
     * par rapport aux totaux légaux va au taux le plus important.
     *
     * @param list<InvoiceHeadingRow|InvoiceItemRow> $rows
     * @return list<VatBreakdown>
     */
    private function vatBreakdown(array $rows, float $totalHt, float $totalVat): array
    {
        $ttcByRate = [];
        foreach ($rows as $row) {
            if ($row instanceof InvoiceItemRow) {
                $key = (string) $row->vatRate;
                $ttcByRate[$key] = [$row->vatRate, ($ttcByRate[$key][1] ?? 0) + (int) round(Rounding::cents($row->totalTtc) * 100)];
            }
        }
        if (count($ttcByRate) <= 1) {
            return [new VatBreakdown(self::vatCode(array_values($ttcByRate)[0][0] ?? self::DEFAULT_VAT_RATE), $totalHt, array_values($ttcByRate)[0][0] ?? self::DEFAULT_VAT_RATE, $totalVat)];
        }

        uasort($ttcByRate, static fn(array $a, array $b): int => $b[1] <=> $a[1]);
        $breakdown = [];
        $baseCents = (int) round($totalHt * 100);
        $vatCents = (int) round($totalVat * 100);
        $largest = true;
        $remainingBase = $baseCents;
        $remainingVat = $vatCents;
        $entries = array_values($ttcByRate);
        $lines = [];
        foreach ($entries as [$rate, $ttcCents]) {
            $ht = (int) round($ttcCents / (1 + $rate / 100));
            $lines[] = [$rate, $ht, $ttcCents - $ht];
            $remainingBase -= $ht;
            $remainingVat -= $ttcCents - $ht;
        }
        foreach ($lines as [$rate, $ht, $vat]) {
            if ($largest) {
                $ht += $remainingBase;
                $vat += $remainingVat;
                $largest = false;
            }
            $breakdown[] = new VatBreakdown(self::vatCode($rate), $ht / 100, $rate, $vat / 100);
        }

        return $breakdown;
    }

    private static function vatCode(float $rate): string
    {
        return match (true) {
            $rate === 20.0 => 'Normale',
            $rate === 0.0 => 'Exonérée',
            default => 'Taux ' . $rate . ' %',
        };
    }

    /**
     * Ligne d'un coût par logement (linge, ménage), quantité = nombre de réservations. Le prix unitaire HT se déduit du TTC
     * comme Facturiza le faisait à l'impression.
     *
     * @param array<string, mixed> $unit `frais_linge` / `frais_menage` d'une réservation
     */
    private static function perProperty(string $label, int $quantity, array $unit): InvoiceItemRow
    {
        $rate = self::float($unit, 'TVA');
        $unitTtc = self::float($unit, 'Montant_TTC');

        return new InvoiceItemRow($label, $quantity, Rounding::cents($unitTtc / (1 + $rate / 100)), $rate, $unitTtc, $unitTtc * $quantity);
    }

    /** @param array<string, mixed> $extra */
    private static function extra(array $extra): InvoiceItemRow
    {
        return new InvoiceItemRow(
            self::string($extra, 'description'),
            1,
            self::float($extra, 'Montant_HT'),
            self::float($extra, 'TVA'),
            self::float($extra, 'Montant_TTC'),
            self::float($extra, 'Montant_TTC'),
        );
    }

    /** @param array<string, mixed> $client */
    private static function recipient(array $client): DocumentRecipient
    {
        return new DocumentRecipient(
            preg_replace('/^P - /', '', self::string($client, 'client_name')) ?? '',
            self::string($client, 'client_legal_name'),
            self::string($client, 'client_siret'),
            self::string($client, 'client_address'),
        );
    }

    /**
     * Jour `jj/mm/aaaa` d'un instant ISO 8601, à l'heure de Paris.
     *
     * @param array<string, mixed> $source
     */
    private static function day(array $source, string $key): string
    {
        return new DateTimeImmutable(self::string($source, $key))->setTimezone(new DateTimeZone(self::DISPLAY_TIMEZONE))->format('d/m/Y');
    }

    /** @param array<string, mixed> $source */
    private static function date(array $source, string $key): DateTimeImmutable
    {
        return new DateTimeImmutable(self::string($source, $key), new DateTimeZone('UTC'));
    }

    /**
     * @param array<array-key, mixed> $source
     * @return array<string, mixed>
     */
    private static function map(array $source, string $key): array
    {
        $value = $source[$key] ?? [];
        if (!is_array($value)) {
            throw new InvalidArgumentException('Expected an object at ' . $key . '.');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $source
     * @return list<array<string, mixed>>
     */
    private static function list(array $source, string $key): array
    {
        $items = [];
        foreach (self::map($source, $key) as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Expected a list of objects at ' . $key . '.');
            }
            $items[] = $item;
        }

        return $items;
    }

    /** @param array<array-key, mixed> $source */
    private static function string(array $source, string $key): string
    {
        $value = $source[$key] ?? '';
        if (is_string($value)) {
            return $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : '';
    }

    /** @param array<array-key, mixed> $source */
    private static function float(array $source, string $key): float
    {
        $value = $source[$key] ?? 0;

        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (float) $value : 0.0;
    }
}
