<?php

declare(strict_types=1);

namespace Casamiza\Domain\Cost;

use DateTimeImmutable;

/** Le contenu lu d'une facture Guesty : ses lignes, dans l'ordre du fichier. */
final readonly class GuestyCostFile
{
    /** @param non-empty-list<GuestyCostLine> $lines */
    public function __construct(public array $lines) {}

    /**
     * Le fichier ne porte pas la date de la facture. Guesty facture le mois d'avance, le 1er : c'est le début des lignes de mois
     * entier (la plus ancienne). Une facture de prorata seul (annonces ajoutées le mois précédent) tombe le lendemain de la fin
     * de la dernière période. Le cours en dépend très peu (0,2 % sur toute la période).
     */
    public function invoiceDate(): DateTimeImmutable
    {
        $starts = [];
        foreach ($this->lines as $line) {
            if ($line->kind() === GuestyCostKind::Monthly) {
                $starts[] = $line->from;
            }
        }
        if ($starts !== []) {
            return min($starts);
        }

        return max(array_map(static fn(GuestyCostLine $l): DateTimeImmutable => $l->to, $this->lines))->modify('+1 day');
    }

    public function periodStart(): DateTimeImmutable
    {
        return min(array_map(static fn(GuestyCostLine $l): DateTimeImmutable => $l->from, $this->lines));
    }

    public function periodEnd(): DateTimeImmutable
    {
        return max(array_map(static fn(GuestyCostLine $l): DateTimeImmutable => $l->to, $this->lines));
    }

    public function listingsCount(): int
    {
        return count(array_unique(array_map(static fn(GuestyCostLine $l): string => $l->listingKey(), $this->lines)));
    }

    public function usdCents(): int
    {
        return array_sum(array_map(static fn(GuestyCostLine $l): int => $l->usdCents, $this->lines));
    }
}
