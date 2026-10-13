<?php

declare(strict_types=1);

namespace Casamiza\Domain\Owner;

use Casamiza\Domain\Property\Property;
use Casamiza\Domain\Property\PropertyRepositoryInterface;

/** Lecture des propriétaires du tenant courant (alimentés par la synchronisation Guesty). */
final readonly class OwnerService
{
    public function __construct(
        private OwnerRepositoryInterface $owners,
        private PropertyRepositoryInterface $properties,
    ) {}

    /**
     * Tous les propriétaires, inactifs (archivés) compris, chacun avec ses logements non archivés.
     *
     * @return list<array{owner: Owner, properties: list<Property>}>
     */
    public function list(): array
    {
        $byOwner = [];
        foreach ($this->properties->all() as $property) {
            if (!$property->isArchived() && $property->ownerId !== null) {
                $byOwner[$property->ownerId][] = $property;
            }
        }

        $rows = [];
        foreach ($this->owners->all() as $owner) {
            $rows[] = ['owner' => $owner, 'properties' => $byOwner[$owner->id] ?? []];
        }

        return $rows;
    }
}
