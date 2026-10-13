<?php

declare(strict_types=1);

namespace Casamiza\Domain\Reservation;

use DateTimeImmutable;

/** Recherche paginée dans les réservations (non archivées) du tenant courant. Tous les filtres se combinent (ET). */
final readonly class ReservationQuery
{
    /**
     * @param ?DateTimeImmutable $checkOutFrom check-out à partir de cette date (incluse), UTC ; null = sans borne
     * @param ?DateTimeImmutable $checkOutBefore check-out avant cette date (exclue), UTC ; null = sans borne
     * @param list<string> $ownerIds identifiants des propriétaires (vide = tous)
     * @param list<string> $propertyIds identifiants des logements (vide = tous)
     * @param list<Platform> $platforms plateformes (vide = toutes)
     * @param ?bool $flagged true = au moins un signalement, false = aucun, null = indifférent
     * @param string $search texte libre : chaque mot doit se retrouver dans au moins un élément de la réservation
     */
    public function __construct(
        public ?DateTimeImmutable $checkOutFrom = null,
        public ?DateTimeImmutable $checkOutBefore = null,
        public array $ownerIds = [],
        public array $propertyIds = [],
        public array $platforms = [],
        public ?bool $flagged = null,
        public string $search = '',
        public ReservationSort $sort = ReservationSort::CheckOut,
        public bool $descending = false,
        public int $page = 1,
        public int $perPage = 50,
    ) {}

    /** @return list<string> mots de la recherche (séparés par des espaces) */
    public function searchTerms(): array
    {
        return array_values(array_filter(
            preg_split('/\s+/u', trim($this->search)) ?: [],
            static fn(string $term): bool => $term !== '',
        ));
    }
}
