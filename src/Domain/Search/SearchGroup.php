<?php

declare(strict_types=1);

namespace Casamiza\Domain\Search;

/**
 * Les premiers résultats d'un type d'élément et le nombre total de correspondances.
 *
 * @template T
 */
final readonly class SearchGroup
{
    /** @param list<T> $items */
    public function __construct(
        public array $items,
        public int $total,
    ) {}
}
