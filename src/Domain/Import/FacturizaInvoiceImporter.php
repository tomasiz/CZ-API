<?php

declare(strict_types=1);

namespace Casamiza\Domain\Import;

use Casamiza\Domain\Extra\Extra;
use Casamiza\Domain\Extra\ExtraRepositoryInterface;
use Casamiza\Domain\Extra\ExtraType;
use Casamiza\Domain\Invoice\DraftLine;
use Casamiza\Domain\Invoice\DraftReservation;
use Casamiza\Domain\Invoice\Invoice;
use Casamiza\Domain\Invoice\InvoiceFileStorageInterface;
use Casamiza\Domain\Invoice\InvoiceLineKind;
use Casamiza\Domain\Invoice\InvoiceRepositoryInterface;
use Casamiza\Domain\Invoice\InvoiceSource;
use Casamiza\Domain\Invoice\PaymentMethod;
use Casamiza\Domain\Invoice\PaymentStatus;
use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerRepositoryInterface;
use Casamiza\Domain\Property\Property;
use Casamiza\Domain\Property\PropertyRepositoryInterface;
use Casamiza\Domain\Reservation\Platform;
use Casamiza\Domain\Reservation\ReservationRepositoryInterface;
use Casamiza\Domain\Statement\Statement;
use Casamiza\Domain\Statement\StatementRepositoryInterface;
use Casamiza\Shared\Rounding;
use Casamiza\Shared\Transaction;
use DateTimeImmutable;
use DateTimeZone;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Reprend l'historique de Facturiza (FACTURIZA.md §11.D) : factures (numéro, dates, totaux, paiement, annulation, PDF, récap lié
 * et JSON tel quel), prestations (`paid_tasks` actives) et « pas de facture ce mois-ci ». Les lignes et l'activité des
 * réservations sont reconstruites depuis le JSON, avec les montants **tels qu'imprimés**. Les pré-factures et récaps non liés
 * à une facture sont ignorés.
 *
 * Règles : le plan est **entièrement construit et vérifié avant d'écrire quoi que ce soit** ; la moindre vérification en échec
 * (propriétaire ou logement introuvable, PDF absent, numéros non contigus, HT + TVA ≠ TTC, deux factures actives pour un
 * même propriétaire et mois, tenant ayant déjà émis des factures) interrompt tout, même avec `$apply`. Rien n'est jamais
 * envoyé à Pennylane (`pennylane_*` reste nul). Relancer l'import ne duplique rien (`legacy_id`). Les fichiers copiés sont
 * supprimés si l'écriture échoue.
 */
final readonly class FacturizaInvoiceImporter
{
    private const MAX_MISMATCH = 0.005;

    public function __construct(
        private InvoiceRepositoryInterface $invoices,
        private OwnerRepositoryInterface $owners,
        private PropertyRepositoryInterface $properties,
        private ReservationRepositoryInterface $reservations,
        private ExtraRepositoryInterface $extras,
        private StatementRepositoryInterface $statements,
        private InvoiceFileStorageInterface $files,
        private Transaction $transaction,
    ) {}

    /**
     * @param list<array<string, string|int|float|null>> $legacyInvoices table `invoices`
     * @param list<array<string, string|int|float|null>> $legacyDocuments table `documents`
     * @param list<array<string, string|int|float|null>> $legacyTasks table `paid_tasks`
     * @param list<array<string, string|int|float|null>> $legacyNoInvoice table `no_invoice_this_month`
     * @param string $filesDirectory dossier des PDF de Facturiza (`files/2/`)
     */
    public function import(array $legacyInvoices, array $legacyDocuments, array $legacyTasks, array $legacyNoInvoice, string $filesDirectory, bool $apply): InvoiceImportReport
    {
        return $this->transaction->run(function () use ($legacyInvoices, $legacyDocuments, $legacyTasks, $legacyNoInvoice, $filesDirectory, $apply): InvoiceImportReport {
            $errors = [];
            $warnings = [];
            if ($this->invoices->hasCasamizaInvoices()) {
                $errors[] = 'Le tenant a déjà émis des factures dans Casamiza : l\'historique doit être repris avant (les numéros se chevaucheraient).';
            }

            $ownerIds = [];
            foreach ($this->owners->all() as $owner) {
                $ownerIds[$owner->guestyId] = $owner;
            }
            $propertyIds = [];
            foreach ($this->properties->all() as $property) {
                $propertyIds[$property->guestyId] = $property;
            }
            $documents = [];
            foreach ($legacyDocuments as $document) {
                $documents[self::int($document['id'] ?? null)] = $document;
            }

            // Prestations d'abord : les lignes `extra` des factures s'y rattachent.
            $knownTasks = $this->extras->legacyIds();
            $plannedExtras = [];
            $extrasAlready = 0;
            foreach ($legacyTasks as $task) {
                $legacyId = self::int($task['id'] ?? null);
                if (self::int($task['removed'] ?? 0) === 1) {
                    continue;
                }
                if (isset($knownTasks[$legacyId])) {
                    $extrasAlready++;
                    continue;
                }
                $owner = $ownerIds[self::string($task['owner_id'] ?? null)] ?? null;
                if ($owner === null) {
                    $warnings[] = sprintf('Prestation %d ignorée : propriétaire Guesty %s introuvable.', $legacyId, self::string($task['owner_id'] ?? null));
                    continue;
                }
                $plannedExtras[] = [$legacyId, $this->extra($task, $owner, $warnings)];
            }

            $numbers = [];
            foreach ($legacyInvoices as $row) {
                $numbers[] = self::int($row['id'] ?? null);
            }
            sort($numbers);
            foreach ($numbers as $index => $number) {
                if ($number !== $index + 1) {
                    $errors[] = sprintf('Numéros de factures non contigus : %d attendu, %d trouvé.', $index + 1, $number);
                    break;
                }
            }

            $knownInvoices = $this->invoices->legacyIds();
            /** @var list<PlannedInvoice> $planned */
            $planned = [];
            $already = 0;
            $active = [];
            foreach ($legacyInvoices as $row) {
                $legacyId = self::int($row['id'] ?? null);
                if (isset($knownInvoices[$legacyId])) {
                    $already++;
                    continue;
                }
                $plan = $this->plan($row, $ownerIds, $propertyIds, $documents, $plannedExtras, $filesDirectory, $errors, $warnings);
                if ($plan === null) {
                    continue;
                }
                $planned[] = $plan;
                if (!$plan->invoice->isCancelled()) {
                    $key = $plan->invoice->ownerId . '|' . $plan->invoice->month;
                    if (isset($active[$key])) {
                        $errors[] = sprintf('Factures %d et %d : deux factures non annulées pour le même propriétaire et le même mois (%s).', $active[$key], $legacyId, $plan->invoice->month);
                    }
                    $active[$key] = $legacyId;
                }
            }

            $unlinked = 0;
            $unbalanced = [];
            foreach ($planned as $plan) {
                $unlinked += count(array_filter($plan->reservations, static fn(DraftReservation $r): bool => $r->reservationId === null));
                if ($plan->lines !== []) {
                    $sum = array_sum(array_map(static fn(DraftLine $l): float => $l->priceTtc, $plan->lines));
                    if (abs($sum - $plan->invoice->amountTtc) > 0.02) {
                        $unbalanced[] = $plan->invoice->number;
                    }
                }
            }
            if ($unlinked > 0) {
                $warnings[] = sprintf('%d réservation(s) facturée(s) n\'existent plus dans Casamiza (logements inactifs) : leur activité est reprise du JSON, sans lien vers `reservations`.', $unlinked);
            }
            if ($unbalanced !== []) {
                $warnings[] = sprintf('Σ TTC des lignes ≠ TTC de la facture (plus de 2 centimes) : factures %s.', implode(', ', $unbalanced));
            }

            $statementsToSave = $this->statementsToSave($legacyNoInvoice, $ownerIds, $active, $warnings);

            $lines = array_sum(array_map(static fn(PlannedInvoice $p): int => count($p->lines), $planned));
            $reservations = array_sum(array_map(static fn(PlannedInvoice $p): int => count($p->reservations), $planned));
            $files = array_sum(array_map(static fn(PlannedInvoice $p): int => count($p->files), $planned));
            $next = ($numbers === [] ? 0 : max($numbers)) + 1;
            $report = static fn(bool $applied): InvoiceImportReport => new InvoiceImportReport(
                $applied,
                count($planned),
                $already,
                $lines,
                $reservations,
                $files,
                count($plannedExtras),
                $extrasAlready,
                count($statementsToSave),
                $next,
                $errors,
                $warnings,
            );
            if (!$apply || $errors !== []) {
                return $report(false);
            }

            $copied = [];
            try {
                foreach ($plannedExtras as [$legacyId, $extra]) {
                    $this->extras->addLegacy($extra, $legacyId);
                }
                foreach ($planned as $plan) {
                    $invoice = $plan->invoice;
                    $stored = [];
                    foreach ($plan->files as $kind => [$source, $year]) {
                        $file = $this->files->store($source, $year);
                        $copied[] = $file->path;
                        $stored[$kind] = $file;
                    }
                    $invoice = new Invoice(...[
                        ...get_object_vars($invoice),
                        'pdfPath' => ($stored['invoice'] ?? null)?->path,
                        'pdfSha256' => ($stored['invoice'] ?? null)?->sha256,
                        'recapPdfPath' => ($stored['recap'] ?? null)?->path,
                    ]);
                    $this->invoices->add($invoice, $plan->lines, $plan->reservations);
                }
                foreach ($statementsToSave as $statement) {
                    $this->statements->save($statement);
                }
                $this->invoices->ensureNextNumberAtLeast($next);
            } catch (Throwable $e) {
                foreach ($copied as $path) {
                    $this->files->discard($path);
                }
                throw $e;
            }

            return $report(true);
        });
    }

    /**
     * @param array<string, string|int|float|null> $row
     * @param array<string, Owner> $owners
     * @param array<string, Property> $properties
     * @param array<int, array<string, string|int|float|null>> $documents
     * @param list<array{int, Extra}> $extras
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function plan(array $row, array $owners, array $properties, array $documents, array $extras, string $filesDirectory, array &$errors, array &$warnings): ?PlannedInvoice
    {
        $number = self::int($row['id'] ?? null);
        $before = count($errors);
        $owner = $owners[self::string($row['guesty_owner_id'] ?? null)] ?? null;
        if ($owner === null) {
            $errors[] = sprintf('Facture %d : propriétaire Guesty %s introuvable dans Casamiza (importer d\'abord les propriétaires).', $number, self::string($row['guesty_owner_id'] ?? null));

            return null;
        }

        $ht = self::float($row['total_ht'] ?? null);
        $vat = self::float($row['total_vat'] ?? null);
        $ttc = self::float($row['total_ttc'] ?? null);
        // Facturiza stocke ses totaux en flottants non arrondis : HT + TVA tombe sur une demi-centime près (ex. TVA 240,695).
        if (abs($ht + $vat - $ttc) > 0.0101) {
            $errors[] = sprintf('Facture %d : HT %s + TVA %s ≠ TTC %s.', $number, $ht, $vat, $ttc);
        }

        $rawJson = trim(self::string($row['json_invoice'] ?? null));
        $snapshot = null;
        if ($rawJson !== '') {
            $decoded = json_decode($rawJson, true);
            if (is_array($decoded) && isset($decoded['facture_totaux'], $decoded['facture_information'], $decoded['facturation_par_listing'])) {
                /** @var array<string, mixed> $decoded */
                $snapshot = $decoded;
            } else {
                $errors[] = sprintf('Facture %d : JSON illisible.', $number);
            }
        } elseif ($number > 6) {
            $warnings[] = sprintf('Facture %d : pas de JSON (ni lignes, ni activité des réservations).', $number);
        }

        // Montants tels qu'imprimés : ceux du JSON, à défaut les totaux de la table arrondis au centime.
        $printed = is_array($snapshot['facture_totaux'] ?? null) ? $snapshot['facture_totaux'] : [];
        $amountHt = isset($printed['total_ht']) ? self::float($printed['total_ht']) : Rounding::cents($ht);
        $amountTtc = isset($printed['total_ttc']) ? self::float($printed['total_ttc']) : Rounding::cents($ttc);
        $amountVat = isset($printed['total_tva']) ? self::float($printed['total_tva']) : Rounding::cents($vat);
        if (abs($amountHt + $amountVat - $amountTtc) > 0.0101) {
            $errors[] = sprintf('Facture %d : HT %s + TVA %s ≠ TTC %s (montants imprimés).', $number, $amountHt, $amountVat, $amountTtc);
        }

        $created = self::paris(self::string($row['datetime_created'] ?? null));
        $information = is_array($snapshot['facture_information'] ?? null) ? $snapshot['facture_information'] : [];
        $invoiceDate = self::day(self::string($information['date_facture'] ?? substr(self::string($row['datetime_created'] ?? null), 0, 10)));
        $paid = self::int($row['paid'] ?? 0) === 1;
        $methodValue = self::string($row['paiement_type'] ?? null);
        $method = $paid ? PaymentMethod::tryFrom($methodValue) : null;
        if ($paid && $method === null) {
            $errors[] = sprintf('Facture %d : mode de paiement « %s » inconnu.', $number, $methodValue);
        }
        $paymentDate = self::string($row['date_paid'] ?? null);
        $paidOn = $paid && $paymentDate !== '' && !str_starts_with($paymentDate, '0000') ? self::day($paymentDate) : null;
        if ($paid && $paidOn === null) {
            $warnings[] = sprintf('Facture %d : payée sans date de paiement.', $number);
        }
        $cancelled = self::int($row['cancelled'] ?? 0) === 1;
        if ($cancelled) {
            $warnings[] = sprintf('Facture %d : annulée ; Facturiza ne conserve pas la date d\'annulation, la date de création est utilisée.', $number);
        }
        $note = is_string($information['note'] ?? null) ? trim($information['note']) : '';

        $files = [];
        foreach (['invoice' => 'document_id', 'recap' => 'recap_document_id'] as $kind => $column) {
            $documentId = self::int($row[$column] ?? 0);
            if ($documentId === 0) {
                continue;
            }
            $filename = self::string(($documents[$documentId] ?? [])['filename'] ?? null);
            $path = $filesDirectory . '/' . $filename;
            if ($filename === '' || !is_file($path) || !is_readable($path)) {
                $errors[] = sprintf('Facture %d : PDF %s absent (document %d, « %s »).', $number, $kind === 'invoice' ? 'de la facture' : 'du récap', $documentId, $filename);
                continue;
            }
            $files[$kind] = [$path, $created->format('Y')];
        }

        $id = Uuid::uuid7()->toString();
        $lines = [];
        $activity = [];
        if ($snapshot !== null) {
            [$lines, $activity] = $this->content($number, $snapshot, $owner, $properties, $extras, $errors, $warnings);
        }
        if (count($errors) > $before) {
            return null;
        }

        $month = self::string($row['month'] ?? null);
        $invoice = new Invoice(
            id: $id,
            number: $number,
            ownerId: $owner->id,
            month: $month,
            periodStart: self::day(self::string($row['start'] ?? null)),
            periodEnd: self::day(self::string($row['end'] ?? null)),
            invoiceDate: $invoiceDate,
            dueDate: $invoiceDate->modify('+1 month'),
            amountHt: $amountHt,
            vat: $amountVat,
            amountTtc: $amountTtc,
            paymentStatus: $paid ? PaymentStatus::Paid : PaymentStatus::Unpaid,
            paymentMethod: $method,
            paymentDate: $paidOn,
            cancelledAt: $cancelled ? $created : null,
            note: $note === '' ? null : $note,
            snapshot: $snapshot,
            source: InvoiceSource::FacturizaImport,
            legacyId: $number,
            createdAt: $created,
        );

        return new PlannedInvoice($invoice, $lines, $activity, $files);
    }

    /**
     * Lignes facturées et activité des réservations d'après le JSON de Facturiza (même ordre que le calcul d'une facture).
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, Property> $properties
     * @param list<array{int, Extra}> $extras
     * @param list<string> $errors
     * @param list<string> $warnings
     * @return array{list<DraftLine>, list<DraftReservation>}
     */
    private function content(int $number, array $snapshot, Owner $owner, array $properties, array $extras, array &$errors, array &$warnings): array
    {
        $lines = [];
        $activity = [];
        $add = static function (InvoiceLineKind $kind, ExtraType $category, ?string $propertyId, ?string $reservationId, ?string $extraId, string $label, mixed $amounts) use (&$lines): void {
            $amounts = is_array($amounts) ? $amounts : [];
            $lines[] = new DraftLine(count($lines) + 1, $kind, $category, $propertyId, $reservationId, $extraId, $label, self::float($amounts['Montant_TTC'] ?? 0), self::float($amounts['TVA'] ?? 20), self::float($amounts['Montant_HT'] ?? 0));
        };

        foreach (self::list($snapshot['facturation_par_listing'] ?? null) as $listing) {
            $listingGuestyId = self::string($listing['listing_guesty_id'] ?? null);
            $property = $properties[$listingGuestyId] ?? null;
            foreach (self::list($listing['listing_reservations'] ?? null) as $entry) {
                if ($property === null) {
                    $errors[] = sprintf('Facture %d : logement Guesty %s introuvable dans Casamiza (importer d\'abord les logements).', $number, $listingGuestyId);
                    break 2;
                }
                $details = self::map($entry['reservation_details'] ?? null);
                $billing = self::map($entry['facturation_details'] ?? null);
                $guestyReservationId = self::string($details['guesty_res_id'] ?? null);
                $reservation = $guestyReservationId === '' ? null : $this->reservations->findByGuestyId($guestyReservationId);
                $reservationId = $reservation?->id;

                try {
                    $checkIn = new DateTimeImmutable(self::string($details['checkin'] ?? null));
                    $checkOut = new DateTimeImmutable(self::string($details['checkout'] ?? null));
                } catch (Throwable) {
                    $errors[] = sprintf('Facture %d : dates de séjour illisibles (réservation %s).', $number, $guestyReservationId);
                    break 2;
                }
                $activity[] = new DraftReservation(
                    $reservationId,
                    $property->id,
                    self::platform(self::string($details['channel'] ?? null)),
                    $checkIn,
                    $checkOut,
                    self::int($details['nights_count'] ?? 0),
                    self::float($details['total_payout'] ?? 0),
                    self::float($details['channel_comission'] ?? 0),
                    self::float($details['paiement_fee'] ?? 0),
                    self::float($details['taxes'] ?? 0),
                    self::float($details['cleaning_fee'] ?? 0),
                    self::float($details['net_payout'] ?? 0),
                    self::float($details['conciergerie_fee'] ?? 0),
                    self::float($details['net_owner_revenue'] ?? 0),
                );

                $link = $reservationId;
                $add(InvoiceLineKind::Reservation, ExtraType::Concierge, $property->id, $link, null, '', $billing['frais_conciergerie'] ?? null);
                $commission = self::map($billing['frais_comission_plateforme'] ?? null);
                if ($commission !== []) {
                    $add(InvoiceLineKind::PlatformCommission, ExtraType::Concierge, $property->id, $link, null, '', $commission);
                }
                foreach (['frais_linge' => ExtraType::Linen, 'frais_menage' => ExtraType::Cleaning] as $key => $category) {
                    $amounts = self::map($billing[$key] ?? null);
                    if (self::float($amounts['Montant_TTC'] ?? 0) !== 0.0) {
                        $add(InvoiceLineKind::Reservation, $category, $property->id, $link, null, '', $amounts);
                    }
                }
            }
        }

        $unused = [];
        foreach ($extras as [, $extra]) {
            $unused[$extra->id] = $extra;
        }
        $period = substr(self::string(self::map($snapshot['facture_information'] ?? null)['periode_start'] ?? null), 0, 7);
        foreach (['conciergerie' => ExtraType::Concierge, 'linge' => ExtraType::Linen, 'menage' => ExtraType::Cleaning] as $key => $category) {
            foreach (self::list(self::map($snapshot['facturation_supplementaire'] ?? null)[$key] ?? null) as $entry) {
                $description = self::string($entry['description'] ?? null);
                $ttc = self::float($entry['Montant_TTC'] ?? 0);
                $matched = null;
                foreach ($unused as $extra) {
                    if ($extra->ownerId === $owner->id && $extra->month === $period && $extra->type === $category && $extra->description === $description && abs($extra->priceTtc - $ttc) < self::MAX_MISMATCH) {
                        $matched = $extra->id;
                        unset($unused[$extra->id]);
                        break;
                    }
                }
                if ($matched === null) {
                    $warnings[] = sprintf('Facture %d : prestation « %s » absente des prestations reprises (ligne sans lien).', $number, $description);
                }
                $add(InvoiceLineKind::Extra, $category, null, null, $matched, $description, $entry);
            }
        }

        return [$lines, $activity];
    }

    /**
     * @param array<string, string|int|float|null> $task
     * @param list<string> $warnings
     */
    private function extra(array $task, Owner $owner, array &$warnings): Extra
    {
        $ttc = self::float($task['price_ttc'] ?? 0);
        $ht = self::float($task['price_ht'] ?? 0);
        $rate = 20.0;
        if (abs($ttc - $ht) < self::MAX_MISMATCH) {
            $rate = $ttc === 0.0 ? 20.0 : 0.0;
        } elseif ($ht !== 0.0) {
            $ratio = ($ttc / $ht - 1) * 100;
            $rate = 20.0;
            foreach ([20.0, 10.0, 5.5] as $candidate) {
                if (abs($ratio - $candidate) < 0.2) {
                    $rate = $candidate;
                }
            }
            if (abs($ratio - $rate) >= 0.2) {
                $warnings[] = sprintf('Prestation %d : taux de TVA déduit de %s / %s (%.2f %%) non standard, 20 %% retenu.', self::int($task['id'] ?? null), $ttc, $ht, $ratio);
            }
        }
        $type = match (self::string($task['type'] ?? null)) {
            'conciergerie' => ExtraType::Concierge,
            'linen' => ExtraType::Linen,
            default => ExtraType::Cleaning,
        };

        return Extra::priced(
            Uuid::uuid7()->toString(),
            $owner->id,
            substr(self::string($task['date_performed'] ?? null), 0, 7),
            $type,
            mb_substr(self::string($task['text'] ?? null), 0, 500),
            Rounding::cents($ttc),
            $rate,
        );
    }

    /**
     * @param list<array<string, string|int|float|null>> $rows
     * @param array<string, Owner> $owners
     * @param array<string, int> $activeInvoices clés « propriétaire|mois » des factures non annulées reprises
     * @param list<string> $warnings
     * @return list<Statement>
     */
    private function statementsToSave(array $rows, array $owners, array $activeInvoices, array &$warnings): array
    {
        $statements = [];
        foreach ($rows as $row) {
            if (self::int($row['active'] ?? 1) !== 1) {
                continue;
            }
            $owner = $owners[self::string($row['guesty_owner_id'] ?? null)] ?? null;
            $month = self::string($row['month'] ?? null);
            if ($owner === null) {
                $warnings[] = sprintf('« Pas de facture » %s ignoré : propriétaire Guesty %s introuvable.', $month, self::string($row['guesty_owner_id'] ?? null));
                continue;
            }
            if (isset($activeInvoices[$owner->id . '|' . $month]) || $this->invoices->hasActive($owner->id, $month)) {
                $warnings[] = sprintf('« Pas de facture » %s ignoré pour %s : une facture existe pour ce mois.', $month, $owner->name);
                continue;
            }
            $existing = $this->statements->find($owner->id, $month);
            if ($existing?->nothingToInvoice === true) {
                continue;
            }
            $statements[] = ($existing ?? new Statement(Uuid::uuid7()->toString(), $owner->id, $month, false))->withNothingToInvoice(true);
        }

        return $statements;
    }

    private static function platform(string $channel): Platform
    {
        return match (true) {
            str_starts_with($channel, 'Booking') => Platform::Booking,
            $channel === 'AirBnB' => Platform::Airbnb,
            $channel === 'Abritel' => Platform::Abritel,
            $channel === 'Site internet' => Platform::Website,
            $channel === 'Manuel' => Platform::Manual,
            default => Platform::Direct,
        };
    }

    /** Date et heure de Facturiza (heure de Paris) en UTC. */
    private static function paris(string $value): DateTimeImmutable
    {
        return (new DateTimeImmutable($value, new DateTimeZone('Europe/Paris')))->setTimezone(new DateTimeZone('UTC'));
    }

    /** Un jour `AAAA-MM-JJ`, à minuit UTC. */
    private static function day(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable(substr($value, 0, 10), new DateTimeZone('UTC'));
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function float(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? (string) $value : '';
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        /** @var array<string, mixed> */
        return is_array($value) ? $value : [];
    }

    /** @return list<array<string, mixed>> */
    private static function list(mixed $value): array
    {
        $items = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $items[] = $item;
            }
        }

        return $items;
    }
}
