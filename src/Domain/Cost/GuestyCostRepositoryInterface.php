<?php

declare(strict_types=1);

namespace Casamiza\Domain\Cost;

/** Factures Guesty importées du tenant courant, leurs lignes, et les noms d'annonces rattachés à la main à un logement. */
interface GuestyCostRepositoryInterface
{
    public function findInvoice(string $number): ?GuestyCostInvoice;

    /** @return list<GuestyCostInvoice> de la plus récente à la plus ancienne */
    public function invoices(): array;

    /**
     * Enregistre la facture (réécrite si elle existe) et remplace toutes ses lignes. À appeler dans une transaction.
     *
     * @param list<GuestyCostItem> $items
     */
    public function save(GuestyCostInvoice $invoice, array $items): void;

    /**
     * Supprime la facture et ses lignes. Une ligne écartée parce qu'une autre facture la portait (`counted` faux) et dont plus aucune
     * ligne ne compte devient comptée (la plus ancienne facture d'abord), et le total des factures touchées est recalculé : un
     * coût réel ne disparaît pas avec le doublon qui le portait. À appeler dans une transaction.
     */
    public function deleteInvoice(string $invoiceId): void;

    /** @return list<GuestyCostItem> */
    public function items(string $invoiceId): array;

    /**
     * Les lignes déjà comptées par les autres factures, pour ne pas compter deux fois la même.
     *
     * @return array<string, true> « nom de l'annonce|début de période (AAAA-MM-JJ) »
     */
    public function countedLines(?string $exceptInvoiceId): array;

    /** @return array<string, string> clé de nom d'annonce (`GuestyCostLine::matchKey`) => identifiant du logement */
    public function aliases(): array;

    public function saveAlias(string $matchKey, string $propertyId): void;
}
