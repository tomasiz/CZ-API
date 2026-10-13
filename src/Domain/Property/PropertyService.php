<?php

declare(strict_types=1);

namespace Casamiza\Domain\Property;

use Casamiza\Domain\Owner\OwnerRepositoryInterface;

/** Lecture des logements du tenant courant (alimentés par la synchronisation Guesty). */
final readonly class PropertyService
{
    public function __construct(
        private PropertyRepositoryInterface $properties,
        private OwnerRepositoryInterface $owners,
    ) {}

    /**
     * Tous les logements, inactifs (archivés) compris, avec le nom de leur propriétaire (null si Guesty n'en désigne
     * aucun) et son état.
     *
     * @return list<array{property: Property, ownerName: string|null, ownerActive: bool}>
     */
    public function list(): array
    {
        $owners = [];
        foreach ($this->owners->all() as $owner) {
            $owners[$owner->id] = $owner;
        }

        $rows = [];
        foreach ($this->properties->all() as $property) {
            $owner = $property->ownerId === null ? null : ($owners[$property->ownerId] ?? null);
            $rows[] = ['property' => $property, 'ownerName' => $owner?->name, 'ownerActive' => $owner === null || !$owner->isArchived()];
        }

        return $rows;
    }
}
