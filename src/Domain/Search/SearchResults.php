<?php

declare(strict_types=1);

namespace Casamiza\Domain\Search;

use Casamiza\Domain\Invoice\InvoiceRow;
use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Property\Property;
use Casamiza\Domain\Reservation\ReservationRow;

/** Résultat de la recherche globale. `invoices` est nul pour un gestionnaire (les factures sont réservées aux administrateurs). */
final readonly class SearchResults
{
    /**
     * @param SearchGroup<array{owner: Owner, propertyCount: int}> $owners
     * @param SearchGroup<array{property: Property, ownerName: ?string}> $properties
     * @param SearchGroup<ReservationRow> $reservations
     * @param ?SearchGroup<InvoiceRow> $invoices
     */
    public function __construct(
        public SearchGroup $owners,
        public SearchGroup $properties,
        public SearchGroup $reservations,
        public ?SearchGroup $invoices,
    ) {}
}
