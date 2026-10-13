<?php

declare(strict_types=1);

namespace Casamiza\Domain\Owner;

/** Propriétaires du tenant courant. */
interface OwnerRepositoryInterface
{
    /** @return list<Owner> tous, archivés compris */
    public function all(): array;

    public function find(string $id): ?Owner;

    public function add(Owner $owner): void;

    /**
     * Réécrit **uniquement** les champs issus de Guesty et l'archivage (même id). Les champs locaux (raison sociale,
     * SIRET, clé Stripe) ne sont jamais touchés : une synchronisation ne peut pas écraser une saisie concurrente.
     */
    public function update(Owner $owner): void;

    /** Réécrit **uniquement** les champs locaux : raison sociale, SIRET et clé Stripe chiffrée. */
    public function updateLocal(Owner $owner): void;
}
