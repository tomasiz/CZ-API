<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice;

/** Factures du tenant courant. */
interface InvoiceRepositoryInterface
{
    /**
     * Attribue le prochain numéro de la séquence du tenant (sans trou) et la fait avancer. À appeler **dans la transaction**
     * qui enregistre la facture : la ligne de séquence est verrouillée jusqu'à sa fin, les créations d'un même tenant
     * passent donc l'une après l'autre, et un échec rend le numéro.
     */
    public function nextNumber(): int;

    /**
     * Enregistre la facture, ses lignes et l'activité de ses réservations.
     *
     * @param list<DraftLine> $lines
     * @param list<DraftReservation> $reservations
     */
    public function add(Invoice $invoice, array $lines, array $reservations): void;

    public function find(string $id): ?Invoice;

    /** Réécrit le paiement de la facture (statut, mode, jour) : rien d'autre ne change après l'émission. */
    public function updatePayment(Invoice $invoice): void;

    /**
     * Toutes les factures, de la plus récente à la plus ancienne (numéro décroissant).
     *
     * @return list<Invoice>
     */
    public function all(): array;

    /**
     * Les factures **non annulées**, restreintes à un propriétaire et/ou à un mois si fournis (sans leur instantané).
     *
     * @return list<Invoice>
     */
    public function active(?string $ownerId, ?string $month): array;

    /** Une facture non annulée existe-t-elle pour ce propriétaire et ce mois ? */
    public function hasActive(string $ownerId, string $month): bool;

    /**
     * Numéros d'origine des factures reprises de Facturiza (pour que relancer l'import ne duplique rien).
     *
     * @return array<int, true>
     */
    public function legacyIds(): array;

    /** Le tenant a-t-il déjà émis une facture dans Casamiza ? (L'import de l'historique doit passer avant.) */
    public function hasCasamizaInvoices(): bool;

    /** Fait avancer la séquence pour que le prochain numéro soit au moins `$number` (jamais en arrière). */
    public function ensureNextNumberAtLeast(int $number): void;
}
