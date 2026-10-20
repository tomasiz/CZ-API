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

    /**
     * Les frais Beyond : réservations qui en portent (`beyond_fee` non nul, archivées comprises : un coût engagé reste un coût),
     * puis une entrée par ligne de relevé sans réservation.
     *
     * @return list<ReservationCost>
     */
    public function beyondFees(): array;

    /**
     * Le coût de l'abonnement Guesty par logement et par mois : lignes des factures importées, en euros, sans les lignes déjà
     * comptées par une autre facture.
     *
     * @return list<GuestyCost>
     */
    public function guestyCosts(): array;
}
