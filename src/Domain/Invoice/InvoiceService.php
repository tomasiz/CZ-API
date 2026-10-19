<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice;

use Casamiza\Domain\Audit\AuditAction;
use Casamiza\Domain\Audit\AuditLogInterface;
use Casamiza\Domain\Extra\ExtraRepositoryInterface;
use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerRepositoryInterface;
use Casamiza\Domain\Property\PropertyRepositoryInterface;
use Casamiza\Domain\Reservation\Platform;
use Casamiza\Domain\Reservation\Reservation;
use Casamiza\Domain\Reservation\ReservationRepositoryInterface;
use Casamiza\Domain\Statement\BillingMonth;
use Casamiza\Domain\Statement\StatementRepositoryInterface;
use Casamiza\Shared\Exception\ConflictException;
use Casamiza\Shared\Exception\NotFoundException;
use Casamiza\Shared\Exception\ServiceUnavailableException;
use Casamiza\Shared\Exception\UnprocessableException;
use Casamiza\Shared\Exception\ValidationException;
use Casamiza\Shared\Transaction;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Création et lecture des factures. Une facture se calcule **à partir de la base** (réservations synchronisées,
 * frais du logement, prestations du mois), puis tout est figé : lignes, activité des réservations, totaux et instantané.
 */
final readonly class InvoiceService
{
    private const BILLING_TIMEZONE = 'Europe/Paris';
    /** Numéro imprimé sur un aperçu (comme le « specimen » de Facturiza). */
    private const SPECIMEN_NUMBER = 'XXXXXXX';

    public function __construct(
        private InvoiceRepositoryInterface $invoices,
        private OwnerRepositoryInterface $owners,
        private PropertyRepositoryInterface $properties,
        private ReservationRepositoryInterface $reservations,
        private ExtraRepositoryInterface $extras,
        private StatementRepositoryInterface $statements,
        private InvoiceCalculator $calculator,
        private InvoiceSnapshotBuilder $snapshots,
        private InvoiceFileStorageInterface $files,
        private InvoiceDocumentService $documents,
        private Transaction $transaction,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private AuditLogInterface $audit,
    ) {}

    /**
     * Émet la facture d'un propriétaire pour un mois terminé, à la date du jour (heure de Paris), échéance un mois plus tard.
     *
     * @throws NotFoundException `owner_not_found`
     * @throws UnprocessableException `month_not_billable` (invalide, pas terminé ou antérieur à janvier 2025), `nothing_to_bill` (aucune réservation ni prestation)
     * @throws ConflictException `month_already_invoiced`, `month_marked_nothing_to_invoice`
     * @throws ServiceUnavailableException `invoice_pdf_failed` (la facture et son récapitulatif n'ont pas pu être fabriqués : rien n'est émis)
     */
    public function create(string $ownerId, string $month, ?string $note): Invoice
    {
        $now = $this->clock->now();
        $owner = $this->billableOwner($ownerId, $month, $now);

        $invoice = $this->transaction->run(function () use ($owner, $month, $note, $now): Invoice {
            // Le numéro d'abord : il verrouille la séquence du tenant, donc deux créations simultanées passent l'une après l'autre.
            $number = $this->invoices->nextNumber();
            $this->assertNotInvoiced($owner, $month);

            [$invoice, $draft, $billings] = $this->compose($owner, $month, $note, $number, $now);
            if ($draft->lines === []) {
                throw new UnprocessableException('nothing_to_bill');
            }
            $invoice = $invoice->withSnapshot($this->snapshots->build($invoice, $owner, $billings, $draft));
            $this->invoices->add($invoice, $draft->lines, $draft->reservations);

            // Une facture sans son PDF n'existe pas : si la fabrication échoue, tout est annulé, numéro compris (pas de trou).
            try {
                $invoice = $this->documents->ensure($invoice);
            } catch (Throwable $error) {
                $this->logger->error('Invoice documents could not be generated.', ['invoice' => $invoice->id, 'exception' => $error]);

                throw new ServiceUnavailableException('invoice_pdf_failed');
            }
            $this->audit->record(AuditAction::InvoiceCreated, 'invoice', $invoice->id, Invoice::formatNumber($invoice->number), [
                'ownerId' => $owner->id,
                'owner' => $owner->name,
                'month' => $month,
                'amountTtc' => $invoice->amountTtc,
            ]);

            return $invoice;
        });

        return $invoice;
    }

    /**
     * Aperçu de la facture que `create` émettrait **maintenant** : même calcul, mêmes refus, PDF « specimen » (filigrane, numéro
     * `XXXXXXX`). Rien n'est enregistré : ni facture, ni numéro, ni fichier.
     *
     * @throws NotFoundException `owner_not_found`
     * @throws UnprocessableException `month_not_billable`, `nothing_to_bill`
     * @throws ConflictException `month_already_invoiced`, `month_marked_nothing_to_invoice`
     */
    public function preview(string $ownerId, string $month, ?string $note): string
    {
        $now = $this->clock->now();
        $owner = $this->billableOwner($ownerId, $month, $now);
        $this->assertNotInvoiced($owner, $month);

        [$invoice, $draft, $billings] = $this->compose($owner, $month, $note, 0, $now);
        if ($draft->lines === []) {
            throw new UnprocessableException('nothing_to_bill');
        }
        $snapshot = $this->snapshots->build($invoice, $owner, $billings, $draft);
        $snapshot['facture_information']['numero_facture'] = self::SPECIMEN_NUMBER;

        return $this->documents->previewInvoice($snapshot, $invoice->dueDate);
    }

    /**
     * Récapitulatif propriétaire d'un mois **pas encore facturé** : un vrai PDF (sans filigrane), calculé sur les réservations
     * d'aujourd'hui et non conservé ; la facture émise fabriquera le sien, figé. Contrairement à la facture, il se génère pour
     * **n'importe quel mois** (en cours ou à venir compris : les réservations futures sont déjà importées), y compris un mois
     * marqué « pas de facture ce mois-ci ».
     *
     * @throws NotFoundException `owner_not_found`
     * @throws UnprocessableException `month_not_billable` (mois invalide ou antérieur à janvier 2025), `nothing_to_bill` (ni réservation ni prestation ce mois-là)
     * @throws ConflictException `month_already_invoiced`
     */
    public function draftRecap(string $ownerId, string $month): string
    {
        if (!BillingMonth::isValid($month)) {
            throw new UnprocessableException('month_not_billable');
        }
        $now = $this->clock->now();
        $owner = $this->owners->find($ownerId) ?? throw new NotFoundException('owner_not_found');
        // Un mois « pas de facture » garde son récapitulatif : seule la facture est exclue
        $this->assertNotInvoiced($owner, $month, true);

        [$invoice, $draft, $billings] = $this->compose($owner, $month, null, 0, $now);
        // Même règle que l'émission : un mois avec seulement des prestations se facture, donc son récapitulatif (sans carte) existe aussi.
        if ($draft->lines === []) {
            throw new UnprocessableException('nothing_to_bill');
        }
        $snapshot = $this->snapshots->build($invoice, $owner, $billings, $draft);

        return $this->documents->draftRecap($snapshot);
    }

    /**
     * Les factures (annulées comprises), de la plus récente à la plus ancienne, avec le propriétaire et les logements facturés ;
     * restreintes à un propriétaire et/ou à un mois si fournis.
     *
     * @return list<InvoiceRow>
     * @throws NotFoundException `owner_not_found`
     */
    public function list(?string $ownerId = null, ?string $month = null): array
    {
        $names = [];
        foreach ($this->owners->all() as $owner) {
            $names[$owner->id] = $owner->name;
        }
        if ($ownerId !== null && !isset($names[$ownerId])) {
            throw new NotFoundException('owner_not_found');
        }
        $properties = $this->invoices->propertyNames();

        $rows = [];
        foreach ($this->invoices->all() as $invoice) {
            if (($ownerId === null || $invoice->ownerId === $ownerId) && ($month === null || $invoice->month === $month)) {
                $rows[] = new InvoiceRow($invoice, $names[$invoice->ownerId] ?? '', $properties[$invoice->id] ?? []);
            }
        }

        return $rows;
    }

    /**
     * Le PDF de la facture (`$recap = false`) ou de son récapitulatif lié (`$recap = true`). Celui d'une facture émise dans Casamiza
     * dont la fabrication a échoué à l'émission est fabriqué ici, à la première demande.
     *
     * @throws NotFoundException `invoice_not_found`, `pdf_not_found` (aucun fichier pour cette facture, ou fichier absent du stockage)
     */
    public function pdf(string $id, bool $recap): string
    {
        $invoice = $this->find($id);
        $path = $recap ? $invoice->recapPdfPath : $invoice->pdfPath;
        // Les PDF repris de Facturiza restent tels quels : on ne fabrique jamais ceux qui manquent à une facture reprise.
        if ($path === null && $invoice->snapshot !== null && $invoice->source === InvoiceSource::Casamiza) {
            try {
                $invoice = $this->documents->ensure($invoice);
            } catch (Throwable $error) {
                $this->logger->error('Invoice documents could not be generated.', ['invoice' => $invoice->id, 'exception' => $error]);
                throw new NotFoundException('pdf_not_found');
            }
            $path = $recap ? $invoice->recapPdfPath : $invoice->pdfPath;
        }

        return ($path === null ? null : $this->files->contents($path)) ?? throw new NotFoundException('pdf_not_found');
    }

    /** La facture non annulée de ce propriétaire pour ce mois, s'il y en a une (sans son instantané). */
    public function activeFor(string $ownerId, string $month): ?Invoice
    {
        return $this->invoices->active($ownerId, $month)[0] ?? null;
    }

    /** @throws NotFoundException `invoice_not_found` */
    public function find(string $id): Invoice
    {
        return $this->invoices->find($id) ?? throw new NotFoundException('invoice_not_found');
    }

    /**
     * Enregistre le paiement d'une facture (`Paid` avec son mode et son jour, par défaut aujourd'hui) ou la remet à « non payée »
     * (mode et jour effacés). Le jour de paiement va du jour de la facture à aujourd'hui (heure de Paris).
     *
     * @throws NotFoundException `invoice_not_found`
     * @throws ConflictException `invoice_cancelled`
     * @throws ValidationException `method` (`required` si payée, `not_allowed` sinon), `date` (`not_allowed` si non payée, `out_of_range`)
     */
    public function setPayment(string $id, PaymentStatus $status, ?PaymentMethod $method, ?DateTimeImmutable $date): Invoice
    {
        $invoice = $this->find($id);
        if ($invoice->isCancelled()) {
            throw new ConflictException('invoice_cancelled');
        }

        $errors = [];
        if ($status === PaymentStatus::Paid) {
            $today = $this->today($this->clock->now());
            $date ??= $today;
            if ($method === null) {
                $errors['method'] = ['required'];
            }
            if ($date < $invoice->invoiceDate || $date > $today) {
                $errors['date'] = ['out_of_range'];
            }
        } else {
            if ($method !== null) {
                $errors['method'] = ['not_allowed'];
            }
            if ($date !== null) {
                $errors['date'] = ['not_allowed'];
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $updated = $status === PaymentStatus::Paid ? $invoice->withPayment($status, $method, $date) : $invoice->withPayment($status, null, null);
        $this->transaction->run(function () use ($updated, $invoice): void {
            $this->invoices->updatePayment($updated);
            $this->audit->record(AuditAction::InvoicePaymentUpdated, 'invoice', $updated->id, Invoice::formatNumber($updated->number), [
                'ownerId' => $updated->ownerId,
                'month' => $updated->month,
                'from' => $invoice->paymentStatus->value,
                'to' => $updated->paymentStatus->value,
                'method' => $updated->paymentMethod?->value,
            ]);
        });

        return $updated;
    }

    /**
     * Le propriétaire d'un mois facturable (terminé, à partir de janvier 2025).
     *
     * @throws NotFoundException `owner_not_found`
     * @throws UnprocessableException `month_not_billable`
     */
    private function billableOwner(string $ownerId, string $month, DateTimeImmutable $now): Owner
    {
        if (!in_array($month, BillingMonth::until($now), true)) {
            throw new UnprocessableException('month_not_billable');
        }

        return $this->owners->find($ownerId) ?? throw new NotFoundException('owner_not_found');
    }

    /**
     * @throws ConflictException `month_already_invoiced`, `month_marked_nothing_to_invoice`
     */
    private function assertNotInvoiced(Owner $owner, string $month, bool $allowNothingToInvoice = false): void
    {
        if ($this->invoices->hasActive($owner->id, $month)) {
            throw new ConflictException('month_already_invoiced');
        }
        if (!$allowNothingToInvoice && $this->statements->find($owner->id, $month)?->nothingToInvoice === true) {
            throw new ConflictException('month_marked_nothing_to_invoice');
        }
    }

    /**
     * Calcule la facture du mois depuis la base, sans rien écrire : l'en-tête (sans instantané), le brouillon calculé et les
     * réservations facturées par logement. Date de facture = aujourd'hui à Paris, échéance un mois plus tard.
     *
     * @return array{Invoice, InvoiceDraft, list<PropertyBilling>}
     */
    private function compose(Owner $owner, string $month, ?string $note, int $number, DateTimeImmutable $now): array
    {
        $billings = $this->billings($owner->id, $month);
        $draft = $this->calculator->build($billings, $this->extras->forOwnerMonth($owner->id, $month));

        $first = new DateTimeImmutable($month . '-01', new DateTimeZone('UTC'));
        $today = $this->today($now);
        $invoice = new Invoice(
            id: Uuid::uuid7()->toString(),
            number: $number,
            ownerId: $owner->id,
            month: $month,
            periodStart: $first,
            periodEnd: $first->modify('last day of this month'),
            invoiceDate: $today,
            dueDate: $today->modify('+1 month'),
            amountHt: $draft->totals->ht,
            vat: $draft->totals->vat,
            amountTtc: $draft->totals->ttc,
            note: $note === null || trim($note) === '' ? null : trim($note),
        );

        return [$invoice, $draft, $billings];
    }

    /** Le jour d'aujourd'hui à Paris, à minuit UTC (les dates de facture sont des jours, sans heure). */
    private function today(DateTimeImmutable $now): DateTimeImmutable
    {
        return new DateTimeImmutable($now->setTimezone(new DateTimeZone(self::BILLING_TIMEZONE))->format('Y-m-d'), new DateTimeZone('UTC'));
    }

    /**
     * Réservations du mois (check-out dans le mois, à minuit près à Paris), non archivées, hors séjours propriétaire, par logement (nom) puis par arrivée.
     *
     * @return list<PropertyBilling>
     */
    private function billings(string $ownerId, string $month): array
    {
        [$from, $before] = BillingMonth::bounds($month);
        $byProperty = [];
        foreach ($this->reservations->checkingOutBetween($from, $before) as $reservation) {
            if ($reservation->ownerId === $ownerId && $reservation->platform !== Platform::Owner) {
                $byProperty[$reservation->propertyId][] = $reservation;
            }
        }

        $known = [];
        foreach ($this->properties->all() as $property) {
            $known[$property->id] = $property;
        }
        $billings = [];
        foreach ($byProperty as $propertyId => $reservations) {
            $property = $known[$propertyId] ?? throw new LogicException('Reservation of an unknown property.');
            usort($reservations, static fn(Reservation $a, Reservation $b): int => [$a->checkIn, $a->id] <=> [$b->checkIn, $b->id]);
            $billings[] = new PropertyBilling($property, $reservations);
        }
        usort($billings, static fn(PropertyBilling $a, PropertyBilling $b): int => [mb_strtolower($a->property->name), $a->property->id] <=> [mb_strtolower($b->property->name), $b->property->id]);

        return $billings;
    }
}
