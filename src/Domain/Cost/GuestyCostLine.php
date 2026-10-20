<?php

declare(strict_types=1);

namespace Casamiza\Domain\Cost;

use DateTimeImmutable;

/** Une ligne de la feuille « Listings » d'une facture Guesty (montant en cents de dollar). */
final readonly class GuestyCostLine
{
    public function __construct(
        /** Numéro de la ligne dans le fichier (l'en-tête est la ligne 1) */
        public int $row,
        public string $nickname,
        /** « Guesty PMS », « Software »… ; vide pour une ligne de prorata */
        public string $item,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public int $usdCents,
    ) {}

    /** Mois entier (du 1er au dernier jour du mois) ou part de mois. */
    public function kind(): GuestyCostKind
    {
        $monthly = $this->from->format('j') === '1'
            && $this->from->format('Y-m') === $this->to->format('Y-m')
            && $this->to->format('j') === $this->to->format('t');

        return $monthly ? GuestyCostKind::Monthly : GuestyCostKind::Prorata;
    }

    /** AAAA-MM du début de la période : le mois où le logement a coûté. */
    public function month(): string
    {
        return $this->from->format('Y-m');
    }

    /** Nom de l'annonce sans casse ni espaces superflus : repère une ligne vue sur deux factures. */
    public function listingKey(): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->nickname)));
    }

    /** Nom d'une annonce réduit à ses lettres et chiffres, en minuscules : « Bonaparte8 T2 » et « Bonaparte 8 T2 » se rejoignent. */
    public static function matchKey(string $name): string
    {
        return mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $name));
    }
}
