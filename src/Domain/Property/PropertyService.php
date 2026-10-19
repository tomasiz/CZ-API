<?php

declare(strict_types=1);

namespace Casamiza\Domain\Property;

use Casamiza\Domain\Audit\AuditAction;
use Casamiza\Domain\Audit\AuditLogInterface;
use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerRepositoryInterface;
use Casamiza\Domain\Reservation\ReservationRepositoryInterface;
use Casamiza\Shared\Exception\NotFoundException;
use Casamiza\Shared\Transaction;

/** Logements du tenant courant (alimentés par la synchronisation Guesty) et leur affectation à un propriétaire. */
final readonly class PropertyService
{
    public function __construct(
        private PropertyRepositoryInterface $properties,
        private OwnerRepositoryInterface $owners,
        private ReservationRepositoryInterface $reservations,
        private Transaction $transaction,
        private AuditLogInterface $audit,
    ) {}

    /**
     * Tous les logements, inactifs (archivés) compris, avec le nom de leur propriétaire (null si Guesty n'en désigne
     * aucun) et son état.
     *
     * @return list<array{property: Property, ownerName: string|null, ownerActive: bool}>
     */
    public function list(): array
    {
        $owners = $this->ownersById();

        $rows = [];
        foreach ($this->properties->all() as $property) {
            $rows[] = $this->row($property, $owners);
        }

        return $rows;
    }

    /**
     * Affecte le logement à un propriétaire de Casamiza (`null` = aucun). Toutes ses réservations suivent, l'historique
     * compris ; les factures émises restent figées. La synchronisation Guesty ne change plus ce choix ensuite.
     * Les montants des réservations (commission du propriétaire) sont recalculés à la prochaine synchronisation.
     *
     * @return array{row: array{property: Property, ownerName: string|null, ownerActive: bool}, movedReservations: int}
     * @throws NotFoundException `property_not_found`, `owner_not_found`
     */
    public function assignOwner(string $id, ?string $ownerId): array
    {
        $property = $this->properties->find($id) ?? throw new NotFoundException('property_not_found');
        $target = $ownerId === null ? null : ($this->owners->find($ownerId) ?? throw new NotFoundException('owner_not_found'));
        $before = $property->ownerId === null ? null : $this->owners->find($property->ownerId);

        $moved = 0;
        $property = $property->assignedTo($target?->id);
        $this->transaction->run(function () use ($property, $before, $target, &$moved): void {
            $this->properties->update($property);
            $moved = $this->reservations->reassignProperty($property->id, $property->ownerId);
            if ($before?->id !== $target?->id) {
                $this->audit->record(AuditAction::PropertyOwnerChanged, 'property', $property->id, $property->name, [
                    'from' => $before?->name,
                    'to' => $target?->name,
                    'reservations' => $moved,
                ]);
            }
        });

        return ['row' => $this->row($property, $this->ownersById()), 'movedReservations' => $moved];
    }

    /** @return array<string, Owner> */
    private function ownersById(): array
    {
        $owners = [];
        foreach ($this->owners->all() as $owner) {
            $owners[$owner->id] = $owner;
        }

        return $owners;
    }

    /**
     * @param array<string, Owner> $owners
     * @return array{property: Property, ownerName: string|null, ownerActive: bool}
     */
    private function row(Property $property, array $owners): array
    {
        $owner = $property->ownerId === null ? null : ($owners[$property->ownerId] ?? null);

        return ['property' => $property, 'ownerName' => $owner?->name, 'ownerActive' => $owner === null || !$owner->isArchived()];
    }
}
