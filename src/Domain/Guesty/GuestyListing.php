<?php

declare(strict_types=1);

namespace Casamiza\Domain\Guesty;

/** Logement tel que Guesty le décrit (lecture seule). */
final readonly class GuestyListing
{
    public function __construct(
        public string $guestyId,
        public string $name,
        public string $address,
        public bool $active,
        /** Identifiant Guesty du propriétaire (le premier si Guesty en désigne plusieurs). */
        public ?string $ownerGuestyId,
        /** Coût du ménage (€), champ personnalisé `cout_ménage` ; 0 s'il n'est pas renseigné. */
        public float $cleaningFee,
        /** Coût du linge (€), champ personnalisé `cout_linge` ; 0 s'il n'est pas renseigné. */
        public float $linenFee,
    ) {}
}
