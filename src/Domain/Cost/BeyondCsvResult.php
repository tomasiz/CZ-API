<?php

declare(strict_types=1);

namespace Casamiza\Domain\Cost;

/** Contenu valide d'un CSV Beyond : ses lignes et les avertissements (sans gravité : le traitement n'est pas bloqué). */
final readonly class BeyondCsvResult
{
    /**
     * @param list<BeyondFeeLine> $lines
     * @param list<array{row: int, code: string}> $warnings `fee_not_one_percent`, `unknown_product`
     */
    public function __construct(
        public array $lines,
        public array $warnings,
        /** Lignes « Non-Billable » (ajustements à zéro chez Beyond, sans commission), lues et laissées de côté */
        public int $nonBillable = 0,
    ) {}
}
