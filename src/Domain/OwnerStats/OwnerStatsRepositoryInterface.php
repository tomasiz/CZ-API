<?php

declare(strict_types=1);

namespace Casamiza\Domain\OwnerStats;

use Casamiza\Domain\Dashboard\DashboardProperty;

/** Lecture des chiffres figés des factures **non annulées** d'un propriétaire du tenant courant (jamais l'instantané JSON). */
interface OwnerStatsRepositoryInterface
{
    /**
     * Les logements du propriétaire, plus ceux qu'une de ses factures a facturés (un logement a pu changer de propriétaire).
     *
     * @return list<DashboardProperty>
     */
    public function properties(string $ownerId): array;

    /** @return list<StatsActivity> */
    public function activity(string $ownerId): array;

    /**
     * Conciergerie, ménage et linge facturés, sans les rétrocommissions de plateforme (déjà comptées dans les frais de plateforme).
     *
     * @return list<StatsBilled>
     */
    public function billed(string $ownerId): array;

    /**
     * Nuits des séjours propriétaire (non archivés).
     *
     * @return list<OwnerNights>
     */
    public function ownerNights(string $ownerId): array;
}
