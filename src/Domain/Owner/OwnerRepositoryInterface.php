<?php

declare(strict_types=1);

namespace Casamiza\Domain\Owner;

/** Propriétaires du tenant courant. */
interface OwnerRepositoryInterface
{
    /** @return list<Owner> tous, archivés compris */
    public function all(): array;

    public function add(Owner $owner): void;

    /** Réécrit les champs issus de Guesty, l'archivage et les champs locaux du propriétaire (même id). */
    public function update(Owner $owner): void;
}
