<?php

declare(strict_types=1);

namespace Casamiza\Domain\Dashboard;

/** Lecture des chiffres figés des factures **non annulées** du tenant courant (jamais l'instantané JSON). */
interface DashboardRepositoryInterface
{
    /**
     * L'activité des réservations facturées, par facture et par logement.
     *
     * @return list<InvoiceActivity>
     */
    public function activity(): array;

    /**
     * Le facturé, par facture, logement (nul pour une prestation) et catégorie.
     *
     * @return list<BilledAmount>
     */
    public function billed(): array;

    /** @return list<DashboardProperty> */
    public function properties(): array;
}
