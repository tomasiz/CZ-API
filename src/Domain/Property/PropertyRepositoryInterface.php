<?php

declare(strict_types=1);

namespace Casamiza\Domain\Property;

/** Logements du tenant courant. */
interface PropertyRepositoryInterface
{
    /** @return list<Property> tous, archivés compris */
    public function all(): array;

    public function add(Property $property): void;

    public function update(Property $property): void;
}
