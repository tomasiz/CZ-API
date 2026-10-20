<?php

declare(strict_types=1);

namespace Casamiza\Domain\Reservation;

use Casamiza\Domain\Company\CompanySettingsRepositoryInterface;
use Casamiza\Domain\Guesty\GuestyReservation;
use Casamiza\Domain\Guesty\GuestyReservationsInterface;
use Casamiza\Domain\Guesty\SyncCounts;
use Casamiza\Domain\Integration\IntegrationRepositoryInterface;
use Casamiza\Domain\Integration\Provider;
use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerRepositoryInterface;
use Casamiza\Domain\Property\Property;
use Casamiza\Domain\Property\PropertyRepositoryInterface;
use Casamiza\Shared\Exception\NotFoundException;
use Casamiza\Shared\Transaction;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Importe les réservations confirmées de Guesty dont le check-out est postérieur ou égal à `$from` (sans borne haute :
 * tous les mois à venir, sauf si `$before` borne la fenêtre, par exemple à un mois). Les logements doivent déjà avoir été
 * synchronisés.
 *
 * - Guesty est lu en entier **avant** la première écriture : une lecture incomplète n'archive rien.
 * - Une réservation de la fenêtre qui n'est plus renvoyée (annulée, plus confirmée) est archivée, jamais supprimée ;
 *   si elle revient, elle est rétablie.
 * - Les montants bruts sont réécrits, puis les montants calculés et les signalements sont **recalculés** (commission du
 *   propriétaire, ménage du logement et frais Stripe compris) : ils suivent donc Guesty tant que le mois n'est pas facturé.
 * - Les frais de paiement manquants sont lus chez Stripe une seule fois par réservation (mémorisés), la lecture n'étant
 *   retentée que si elle a échoué ou si les paiements ont changé.
 * - Un séjour propriétaire est conservé (`Platform::Owner`).
 *
 * TODO factures : exclure de la fenêtre les mois déjà facturés d'un propriétaire (figés) dès que la table `invoices` existe.
 */
final readonly class ReservationSyncService
{
    public function __construct(
        private GuestyReservationsInterface $guesty,
        private ReservationRepositoryInterface $reservations,
        private PropertyRepositoryInterface $properties,
        private OwnerRepositoryInterface $owners,
        private CompanySettingsRepositoryInterface $company,
        private ReservationCalculator $calculator,
        private PaymentFeeResolver $stripe,
        private IntegrationRepositoryInterface $integrations,
        private Transaction $transaction,
        private ClockInterface $clock,
    ) {}

    /**
     * @throws NotFoundException `integration_not_configured`
     * @throws \Casamiza\Shared\Exception\ApiException l'accès à Guesty a échoué (voir CheckFailure)
     */
    public function sync(DateTimeImmutable $from, ?DateTimeImmutable $before = null): ReservationSyncReport
    {
        if ($this->integrations->find(Provider::Guesty) === null) {
            throw new NotFoundException('integration_not_configured');
        }

        // Guesty filtre au jour près : on ne garde que la fenêtre exacte, pour ne toucher à rien en dehors.
        $guestyReservations = array_values(array_filter(
            $this->guesty->reservations($from, $before),
            static fn(GuestyReservation $r): bool => $r->checkOut >= $from && ($before === null || $r->checkOut < $before),
        ));
        $now = $this->clock->now();

        return $this->transaction->run(function () use ($guestyReservations, $from, $before, $now): ReservationSyncReport {
            // Même verrou que la synchronisation des propriétaires et logements : deux synchronisations ne se chevauchent pas.
            $this->integrations->find(Provider::Guesty, true);

            $properties = [];
            foreach ($this->properties->all() as $property) {
                $properties[$property->guestyId] = $property;
            }
            $existing = [];
            foreach ($this->reservations->allCheckingOutSince($from, $before) as $reservation) {
                $existing[$reservation->guestyId] = $reservation;
            }

            $owners = [];
            foreach ($this->owners->all() as $owner) {
                $owners[$owner->id] = $owner;
            }
            $defaultCommission = $this->company->find()->defaultCommission ?? 0.0;

            $created = $updated = $archived = $unchanged = $skipped = $stripeLookups = 0;
            $seen = [];
            foreach ($guestyReservations as $guesty) {
                $property = $properties[$guesty->listingGuestyId] ?? null;
                if ($property === null) {
                    $skipped++;
                    continue;
                }
                $seen[$guesty->guestyId] = true;
                // Une réservation dont la date a été repoussée dans la fenêtre existe peut-être déjà hors de celle-ci.
                $current = $existing[$guesty->guestyId] ?? $this->reservations->findByGuestyId($guesty->guestyId);
                $reservation = $current === null
                    ? Reservation::fromGuesty(Uuid::uuid7()->toString(), $guesty, $property->ownerId, $property->id)
                    : $current->mergedWith($guesty, $property->ownerId, $property->id);

                $owner = $property->ownerId === null ? null : ($owners[$property->ownerId] ?? null);
                $reservation = $this->calculated($reservation, $property, $owner, $defaultCommission, $stripeLookups);

                if ($current === null) {
                    $this->reservations->add($reservation);
                    $created++;
                    continue;
                }
                if ($reservation->sameAs($current)) {
                    $unchanged++;
                    continue;
                }
                $this->reservations->update($reservation);
                $updated++;
            }

            foreach ($existing as $guestyId => $reservation) {
                if (isset($seen[$guestyId])) {
                    continue;
                }
                if ($reservation->isArchived()) {
                    $unchanged++;
                } else {
                    $this->reservations->update($reservation->archived($now));
                    $archived++;
                }
            }

            return new ReservationSyncReport(new SyncCounts($created, $updated, $archived, $unchanged), $skipped, $stripeLookups, $from, $now, $before);
        });
    }

    /**
     * Lit ces réservations chez Guesty par identifiant, quel que soit leur statut (à appeler **avant** toute transaction).
     *
     * @param list<string> $guestyIds
     * @return list<GuestyReservation>
     * @throws NotFoundException `integration_not_configured`
     * @throws \Casamiza\Shared\Exception\ApiException l'accès à Guesty a échoué
     */
    public function fetch(array $guestyIds): array
    {
        if ($this->integrations->find(Provider::Guesty) === null) {
            throw new NotFoundException('integration_not_configured');
        }

        return $guestyIds === [] ? [] : $this->guesty->reservationsByIds($guestyIds);
    }

    /**
     * Enregistre des réservations lues par identifiant, comme la synchro le ferait (montants bruts, puis calculés). Celles dont
     * le logement est inconnu sont **ignorées** (l'appelant les a écartées avant) ; une réservation annulée est enregistrée
     * archivée, pour qu'on puisse y rattacher un coût. À appeler dans la transaction de l'appelant.
     *
     * @param list<GuestyReservation> $fetched
     * @return array<string, Reservation> par identifiant Guesty
     */
    public function store(array $fetched): array
    {
        $properties = [];
        foreach ($this->properties->all() as $property) {
            $properties[$property->guestyId] = $property;
        }
        $owners = [];
        foreach ($this->owners->all() as $owner) {
            $owners[$owner->id] = $owner;
        }
        $defaultCommission = $this->company->find()->defaultCommission ?? 0.0;
        $now = $this->clock->now();

        $stored = [];
        $lookups = 0;
        foreach ($fetched as $guesty) {
            $property = $properties[$guesty->listingGuestyId] ?? null;
            if ($property === null) {
                continue;
            }
            $current = $this->reservations->findByGuestyId($guesty->guestyId);
            $reservation = $current === null
                ? Reservation::fromGuesty(Uuid::uuid7()->toString(), $guesty, $property->ownerId, $property->id)
                : $current->mergedWith($guesty, $property->ownerId, $property->id);
            $owner = $property->ownerId === null ? null : ($owners[$property->ownerId] ?? null);
            $reservation = $this->calculated($reservation, $property, $owner, $defaultCommission, $lookups);
            if (!$guesty->confirmed && !$reservation->isArchived()) {
                $reservation = $reservation->archived($now);
            }

            if ($current === null) {
                $this->reservations->add($reservation);
            } elseif (!$reservation->sameAs($current)) {
                $this->reservations->update($reservation);
            }
            $stored[$guesty->guestyId] = $reservation;
        }

        return $stored;
    }

    /** Frais Stripe manquants lus (une fois par réservation), puis montants calculés. `$stripeLookups` compte les lectures faites. */
    private function calculated(Reservation $reservation, Property $property, ?Owner $owner, float $defaultCommission, int &$stripeLookups): Reservation
    {
        if ($reservation->stripeFee === null || $reservation->stripeError !== null) {
            $fees = $this->stripe->resolve($reservation, $owner);
            if ($fees !== null) {
                $reservation = $reservation->withStripeFees($fees);
                $stripeLookups++;
            }
        }

        return $reservation->withCalculation($this->calculator->calculate(
            $reservation,
            $owner->commission ?? $defaultCommission,
            $property->cleaningFee,
            $reservation->storedStripeFees(),
        ));
    }
}
