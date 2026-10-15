<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice;

use Casamiza\Domain\Extra\ExtraRepositoryInterface;
use Casamiza\Domain\Owner\OwnerRepositoryInterface;
use Casamiza\Domain\Property\PropertyRepositoryInterface;
use Casamiza\Domain\Reservation\Platform;
use Casamiza\Domain\Reservation\Reservation;
use Casamiza\Domain\Reservation\ReservationRepositoryInterface;
use Casamiza\Domain\Statement\BillingMonth;
use Casamiza\Domain\Statement\StatementRepositoryInterface;
use Casamiza\Shared\Exception\ConflictException;
use Casamiza\Shared\Exception\NotFoundException;
use Casamiza\Shared\Exception\UnprocessableException;
use Casamiza\Shared\Exception\ValidationException;
use Casamiza\Shared\Transaction;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Création et lecture des factures. Une facture se calcule **à partir de la base** (réservations synchronisées,
 * frais du logement, prestations du mois), puis tout est figé : lignes, activité des réservations, totaux et instantané.
 */
final readonly class InvoiceService
{
    private const BILLING_TIMEZONE = 'Europe/Paris';

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
        private Transaction $transaction,
        private ClockInterface $clock,
    ) {}

    /**
     * Émet la facture d'un propriétaire pour un mois terminé, à la date du jour (heure de Paris), échéance un mois plus tard.
     *
     * @throws NotFoundException `owner_not_found`
     * @throws UnprocessableException `month_not_billable` (invalide, pas terminé ou antérieur à janvier 2025), `nothing_to_bill` (aucune réservation ni prestation)
     * @throws ConflictException `month_already_invoiced`, `month_marked_nothing_to_invoice`
     */
    public function create(string $ownerId, string $month, ?string $note): Invoice
    {
        $now = $this->clock->now();
        if (!in_array($month, BillingMonth::until($now), true)) {
            throw new UnprocessableException('month_not_billable');
        }
        $owner = $this->owners->find($ownerId) ?? throw new NotFoundException('owner_not_found');

        return $this->transaction->run(function () use ($owner, $month, $note, $now): Invoice {
            // Le numéro d'abord : il verrouille la séquence du tenant, donc deux créations simultanées passent l'une après l'autre.
            $number = $this->invoices->nextNumber();
            if ($this->invoices->hasActive($owner->id, $month)) {
                throw new ConflictException('month_already_invoiced');
            }
            if ($this->statements->find($owner->id, $month)?->nothingToInvoice === true) {
                throw new ConflictException('month_marked_nothing_to_invoice');
            }

            $billings = $this->billings($owner->id, $month);
            $draft = $this->calculator->build($billings, $this->extras->forOwnerMonth($owner->id, $month));
            if ($draft->lines === []) {
                throw new UnprocessableException('nothing_to_bill');
            }

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
            $invoice = $invoice->withSnapshot($this->snapshots->build($invoice, $owner, $billings, $draft));
            $this->invoices->add($invoice, $draft->lines, $draft->reservations);

            return $invoice;
        });
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
     * Le PDF de la facture (`$recap = false`) ou de son récapitulatif lié (`$recap = true`).
     *
     * @throws NotFoundException `invoice_not_found`, `pdf_not_found` (aucun fichier pour cette facture, ou fichier absent du stockage)
     */
    public function pdf(string $id, bool $recap): string
    {
        $invoice = $this->find($id);
        $path = $recap ? $invoice->recapPdfPath : $invoice->pdfPath;

        return ($path === null ? null : $this->files->contents($path)) ?? throw new NotFoundException('pdf_not_found');
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
        $this->invoices->updatePayment($updated);

        return $updated;
    }

    /** Le jour d'aujourd'hui à Paris, à minuit UTC (les dates de facture sont des jours, sans heure). */
    private function today(DateTimeImmutable $now): DateTimeImmutable
    {
        return new DateTimeImmutable($now->setTimezone(new DateTimeZone(self::BILLING_TIMEZONE))->format('Y-m-d'), new DateTimeZone('UTC'));
    }

    /**
     * Réservations du mois (check-out dans le mois, en UTC), non archivées, hors séjours propriétaire, par logement (nom) puis par arrivée.
     *
     * @return list<PropertyBilling>
     */
    private function billings(string $ownerId, string $month): array
    {
        $from = new DateTimeImmutable($month . '-01', new DateTimeZone('UTC'));
        $byProperty = [];
        foreach ($this->reservations->checkingOutBetween($from, $from->modify('first day of next month')) as $reservation) {
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
