<?php

declare(strict_types=1);

namespace Casamiza\Domain\Import;

/**
 * Bilan de la reprise des SIRET et raisons sociales de Facturiza. Chaque ligne est `[nom du propriétaire, détail]`.
 * Les propriétaires sont désignés par leur nom : ce bilan ne s'affiche qu'à l'écran, il n'est ni journalisé ni stocké.
 */
final readonly class OwnerImportReport
{
    /**
     * @param int $legacyWithData propriétaires de Facturiza ayant un SIRET ou une raison sociale
     * @param list<array{string, string}> $updated valeurs écrites (ou à écrire en simulation)
     * @param list<array{string, string}> $unchanged valeurs déjà identiques dans Casamiza
     * @param list<array{string, string}> $conflicts valeur locale différente : conservée, jamais écrasée
     * @param list<array{string, string}> $invalid SIRET de Facturiza inutilisable (pas 14 chiffres) : non repris
     * @param list<array{string, string}> $notFound propriétaire de Facturiza absent de Casamiza (synchro Guesty à refaire ?)
     */
    public function __construct(
        public int $legacyWithData,
        public array $updated,
        public array $unchanged,
        public array $conflicts,
        public array $invalid,
        public array $notFound,
        public bool $applied,
    ) {}
}
