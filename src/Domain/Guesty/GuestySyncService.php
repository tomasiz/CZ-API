<?php

declare(strict_types=1);

namespace Casamiza\Domain\Guesty;

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
 * Importe les propriétaires et les logements de Guesty pour le tenant courant (Guesty est la source de vérité).
 *
 * - Guesty est lu en entier **avant** la première écriture : si un appel échoue, rien n'est modifié, et surtout
 *   rien n'est archivé sur la foi d'une liste incomplète.
 * - Ce qui n'est plus dans Guesty (ou y est inactif) est archivé, jamais supprimé ; s'il revient, il est rétabli.
 * - Les champs locaux (SIRET, raison sociale des propriétaires) ne sont jamais écrasés.
 */
final readonly class GuestySyncService
{
    public function __construct(
        private GuestyDirectoryInterface $guesty,
        private OwnerRepositoryInterface $owners,
        private PropertyRepositoryInterface $properties,
        private IntegrationRepositoryInterface $integrations,
        private Transaction $transaction,
        private ClockInterface $clock,
    ) {}

    /**
     * @throws NotFoundException `integration_not_configured`
     * @throws \Casamiza\Shared\Exception\ApiException l'accès à Guesty a échoué (voir CheckFailure)
     */
    public function sync(): SyncReport
    {
        if ($this->integrations->find(Provider::Guesty) === null) {
            throw new NotFoundException('integration_not_configured');
        }

        $guestyOwners = $this->guesty->owners();
        $guestyListings = $this->guesty->listings();
        $now = $this->clock->now();

        return $this->transaction->run(function () use ($guestyOwners, $guestyListings, $now): SyncReport {
            // Le verrou sur la configuration sérialise deux synchronisations simultanées (bouton + cron) :
            // la seconde relit l'état écrit par la première avant de comparer.
            $this->integrations->find(Provider::Guesty, true);

            [$ownerCounts, $ownerIds] = $this->syncOwners($guestyOwners, $now);

            return new SyncReport($ownerCounts, $this->syncProperties($guestyListings, $ownerIds, $now), $now);
        });
    }

    /**
     * @param list<GuestyOwner> $guestyOwners
     * @return array{SyncCounts, array<string, string>} bilan et identifiant local de chaque propriétaire par id Guesty
     */
    private function syncOwners(array $guestyOwners, DateTimeImmutable $now): array
    {
        $existing = [];
        foreach ($this->owners->all() as $owner) {
            $existing[$owner->guestyId] = $owner;
        }

        $created = $updated = $archived = $unchanged = 0;
        $ids = [];
        $seen = [];
        foreach ($guestyOwners as $guesty) {
            $seen[$guesty->guestyId] = true;
            $current = $existing[$guesty->guestyId] ?? null;
            if ($current === null) {
                $owner = Owner::fromGuesty(Uuid::uuid7()->toString(), $guesty, $now);
                $this->owners->add($owner);
                // Nouveau mais inactif dans Guesty : ajouté archivé, il ne paraît pas dans la liste.
                if ($owner->isArchived()) {
                    $archived++;
                } else {
                    $created++;
                }
            } else {
                $owner = $current->mergedWith($guesty, $now);
                if ($owner->sameAs($current)) {
                    $unchanged++;
                } else {
                    $this->owners->update($owner);
                    if ($owner->isArchived() && !$current->isArchived()) {
                        $archived++;
                    } else {
                        $updated++;
                    }
                }
            }
            $ids[$guesty->guestyId] = $owner->id;
        }

        foreach ($existing as $guestyId => $owner) {
            if (isset($seen[$guestyId])) {
                continue;
            }
            // Absent de Guesty : archivé, mais son identifiant reste valable pour relier d'éventuels logements.
            $ids[$guestyId] = $owner->id;
            if ($owner->isArchived()) {
                $unchanged++;
            } else {
                $this->owners->update($owner->archived($now));
                $archived++;
            }
        }

        return [new SyncCounts($created, $updated, $archived, $unchanged), $ids];
    }

    /**
     * @param list<GuestyListing> $guestyListings
     * @param array<string, string> $ownerIds identifiant local de chaque propriétaire par id Guesty
     */
    private function syncProperties(array $guestyListings, array $ownerIds, DateTimeImmutable $now): SyncCounts
    {
        $existing = [];
        foreach ($this->properties->all() as $property) {
            $existing[$property->guestyId] = $property;
        }

        $created = $updated = $archived = $unchanged = 0;
        $seen = [];
        foreach ($guestyListings as $guesty) {
            $seen[$guesty->guestyId] = true;
            $ownerId = $guesty->ownerGuestyId === null ? null : ($ownerIds[$guesty->ownerGuestyId] ?? null);
            $current = $existing[$guesty->guestyId] ?? null;
            if ($current === null) {
                $property = Property::fromGuesty(Uuid::uuid7()->toString(), $guesty, $ownerId, $now);
                $this->properties->add($property);
                if ($property->isArchived()) {
                    $archived++;
                } else {
                    $created++;
                }
                continue;
            }
            $property = $current->mergedWith($guesty, $ownerId, $now);
            if ($property->sameAs($current)) {
                $unchanged++;
                continue;
            }
            $this->properties->update($property);
            if ($property->isArchived() && !$current->isArchived()) {
                $archived++;
            } else {
                $updated++;
            }
        }

        foreach ($existing as $guestyId => $property) {
            if (isset($seen[$guestyId])) {
                continue;
            }
            if ($property->isArchived()) {
                $unchanged++;
            } else {
                $this->properties->update($property->archived($now));
                $archived++;
            }
        }

        return new SyncCounts($created, $updated, $archived, $unchanged);
    }
}
