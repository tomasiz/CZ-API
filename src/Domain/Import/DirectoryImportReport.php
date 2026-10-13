<?php

declare(strict_types=1);

namespace Casamiza\Domain\Import;

/**
 * Bilan de la reprise des propriétaires et logements de Facturiza absents de Casamiza. Chaque ligne est
 * `[nom, détail]` ; ce bilan ne s'affiche qu'à l'écran (jamais journalisé), et ne contient aucune clé Stripe.
 */
final readonly class DirectoryImportReport
{
    /**
     * @param list<array{string, string}> $owners propriétaires créés (archivés) ou à créer en simulation
     * @param list<array{string, string}> $properties logements créés (archivés) ou à créer en simulation
     * @param list<array{string, string}> $warnings valeurs non reprises ou rattachements impossibles
     * @param int $ownersPresent propriétaires de Facturiza déjà dans Casamiza (non touchés)
     * @param int $propertiesPresent logements de Facturiza déjà dans Casamiza (non touchés)
     */
    public function __construct(
        public array $owners,
        public array $properties,
        public array $warnings,
        public int $ownersPresent,
        public int $propertiesPresent,
        public bool $applied,
    ) {}
}
