<?php

declare(strict_types=1);

namespace Casamiza\Domain\Search;

use Casamiza\Domain\Invoice\Invoice;
use Casamiza\Domain\Invoice\InvoiceRow;
use Casamiza\Domain\Invoice\InvoiceService;
use Casamiza\Domain\Owner\OwnerRepositoryInterface;
use Casamiza\Domain\Property\PropertyRepositoryInterface;
use Casamiza\Domain\Reservation\ReservationQuery;
use Casamiza\Domain\Reservation\ReservationRepositoryInterface;
use Casamiza\Domain\Reservation\ReservationSort;

/**
 * Recherche globale (barre du haut du back-office) dans les propriétaires, logements, réservations et factures du tenant
 * courant. Chaque dépôt est borné au tenant : rien ne traverse les tenants. Propriétaires et logements (quelques dizaines)
 * sont filtrés en mémoire ; les réservations réutilisent la recherche SQL de `GET /reservations`.
 *
 * Les saisies d'administrateur (raison sociale, SIRET) ne sont cherchées que pour un administrateur, et les factures ne
 * sont lues que par lui.
 */
final readonly class SearchService
{
    public const MIN_LENGTH = 2;

    public function __construct(
        private OwnerRepositoryInterface $owners,
        private PropertyRepositoryInterface $properties,
        private ReservationRepositoryInterface $reservations,
        private InvoiceService $invoices,
    ) {}

    public function search(string $query, int $limit, bool $admin): SearchResults
    {
        $terms = SearchText::terms($query);

        return new SearchResults(
            $this->owners($terms, $limit, $admin),
            $this->properties($terms, $limit),
            $this->reservations($query, $limit),
            $admin ? $this->invoices($terms, $limit) : null,
        );
    }

    /**
     * @param list<string> $terms
     * @return SearchGroup<array{owner: \Casamiza\Domain\Owner\Owner, propertyCount: int}>
     */
    private function owners(array $terms, int $limit, bool $admin): SearchGroup
    {
        $counts = [];
        foreach ($this->properties->all() as $property) {
            if ($property->ownerId !== null) {
                $counts[$property->ownerId] = ($counts[$property->ownerId] ?? 0) + 1;
            }
        }

        $hits = [];
        foreach ($this->owners->all() as $owner) {
            $fields = [$owner->name, $owner->firstName, $owner->lastName, $owner->email, $owner->phone, $owner->address, $owner->guestyId];
            if ($admin) {
                $fields = [...$fields, $owner->companyName, $owner->siret];
            }
            if (SearchText::matches($terms, $fields)) {
                $hits[] = ['owner' => $owner, 'propertyCount' => $counts[$owner->id] ?? 0];
            }
        }
        // Actifs d'abord, puis par nom.
        usort($hits, static fn(array $a, array $b): int => [$a['owner']->isArchived(), mb_strtolower($a['owner']->name)] <=> [$b['owner']->isArchived(), mb_strtolower($b['owner']->name)]);

        return new SearchGroup(array_slice($hits, 0, $limit), count($hits));
    }

    /**
     * @param list<string> $terms
     * @return SearchGroup<array{property: \Casamiza\Domain\Property\Property, ownerName: ?string}>
     */
    private function properties(array $terms, int $limit): SearchGroup
    {
        $names = [];
        foreach ($this->owners->all() as $owner) {
            $names[$owner->id] = $owner->name;
        }

        $hits = [];
        foreach ($this->properties->all() as $property) {
            $ownerName = $property->ownerId === null ? null : ($names[$property->ownerId] ?? null);
            if (SearchText::matches($terms, [$property->name, $property->address, $property->guestyId, $ownerName ?? ''])) {
                $hits[] = ['property' => $property, 'ownerName' => $ownerName];
            }
        }
        usort($hits, static fn(array $a, array $b): int => [$a['property']->isArchived(), mb_strtolower($a['property']->name)] <=> [$b['property']->isArchived(), mb_strtolower($b['property']->name)]);

        return new SearchGroup(array_slice($hits, 0, $limit), count($hits));
    }

    /** @return SearchGroup<\Casamiza\Domain\Reservation\ReservationRow> les plus récentes d'abord (check-out décroissant) */
    private function reservations(string $query, int $limit): SearchGroup
    {
        $page = $this->reservations->search(new ReservationQuery(
            search: $query,
            sort: ReservationSort::CheckOut,
            descending: true,
            page: 1,
            perPage: $limit,
        ));

        return new SearchGroup($page->items, $page->total);
    }

    /**
     * Numéro (`FZ0000412`, `412`), propriétaire, logements facturés, mois (`2026-09`), note, référence Pennylane. Le numéro
     * exact passe en premier, puis les plus récentes.
     *
     * @param list<string> $terms
     * @return SearchGroup<InvoiceRow>
     */
    private function invoices(array $terms, int $limit): SearchGroup
    {
        // « FZ0000412 » et « fz412 » se cherchent comme « 412 ».
        $terms = array_map(static fn(string $term): string => preg_match('/^fz0*(\d+)$/', $term, $m) === 1 ? $m[1] : $term, $terms);

        $hits = [];
        foreach ($this->invoices->list() as $row) {
            $invoice = $row->invoice;
            if (SearchText::matches($terms, [$invoice->displayNumber(), (string) $invoice->number, $row->ownerName, $invoice->month, $invoice->note ?? '', $invoice->pennylaneId ?? '', ...$row->propertyNames])) {
                $hits[] = $row;
            }
        }
        $exact = static fn(InvoiceRow $row): int => self::isExactNumber($row->invoice, $terms) ? 0 : 1;
        // `list()` est déjà du numéro le plus grand au plus petit ; le tri de PHP est stable.
        usort($hits, static fn(InvoiceRow $a, InvoiceRow $b): int => $exact($a) <=> $exact($b));

        return new SearchGroup(array_slice($hits, 0, $limit), count($hits));
    }

    /** @param list<string> $terms */
    private static function isExactNumber(Invoice $invoice, array $terms): bool
    {
        foreach ($terms as $term) {
            $digits = ltrim(preg_replace('/^fz/', '', $term) ?? $term, '0');
            if ($digits !== '' && ctype_digit($digits) && (int) $digits === $invoice->number) {
                return true;
            }
        }

        return false;
    }
}
