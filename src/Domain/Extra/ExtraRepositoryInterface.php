<?php

declare(strict_types=1);

namespace Casamiza\Domain\Extra;

/** Prestations supplémentaires du tenant courant (les prestations supprimées n'apparaissent plus). */
interface ExtraRepositoryInterface
{
    /**
     * Prestations d'un propriétaire pour un mois, dans l'ordre de saisie.
     *
     * @return list<Extra>
     */
    public function forOwnerMonth(string $ownerId, string $month): array;

    public function find(string $id): ?Extra;

    public function add(Extra $extra): void;

    /** Réécrit type, descriptif, prix et TVA (le propriétaire et le mois ne changent jamais). */
    public function update(Extra $extra): void;

    /** Suppression logique. */
    public function remove(string $id): void;
}
