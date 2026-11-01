<?php

declare(strict_types=1);

namespace Casamiza\Domain\OwnerStats;

use Casamiza\Domain\Dashboard\Allocation;
use Casamiza\Domain\Owner\OwnerRepositoryInterface;
use Casamiza\Shared\Exception\NotFoundException;

/**
 * Statistiques d'un propriétaire : une ligne par (logement, mois facturé), sur les **factures non annulées**, à partir des
 * colonnes figées (`invoice_reservations`, `invoice_lines`), jamais de l'instantané.
 *
 * - le total payé, les frais de plateforme (commission + frais de paiement, rétrocommission Abritel comprise) et la taxe de
 *   séjour viennent de `invoice_reservations` ;
 * - le ménage (linge compris) et la conciergerie sont ce que la facture a **réellement demandé** au propriétaire, TTC,
 *   prestations comprises (même répartition des prestations sans logement que le tableau de bord) ;
 * - le net propriétaire est le reste : il est donc net de TVA et de prestations, et la somme des six postes égale le total payé ;
 * - les nuits des séjours propriétaire (absents des factures) sont lues dans les réservations, pour les mois facturés seulement.
 */
final readonly class OwnerStatsService
{
    public function __construct(
        private OwnerStatsRepositoryInterface $stats,
        private OwnerRepositoryInterface $owners,
    ) {}

    /** @throws NotFoundException `owner_not_found` */
    public function forOwner(string $ownerId): OwnerStats
    {
        $this->owners->find($ownerId) ?? throw new NotFoundException('owner_not_found');
        $properties = $this->stats->properties($ownerId);

        /** @var array<string, StatsCell> $cells clé « logement|mois » */
        $cells = [];
        $cell = static function (?string $propertyId, string $month) use (&$cells): StatsCell {
            return $cells[($propertyId ?? '') . '|' . $month] ??= new StatsCell($propertyId, $month);
        };

        /** @var array<string, array<string, int>> $nights nuits de chaque logement, par facture */
        $nights = [];
        /** @var array<string, PlatformMonth> $platforms clé « logement|mois|plateforme » */
        $platforms = [];
        foreach ($this->stats->activity($ownerId) as $a) {
            $cell($a->propertyId, $a->month)->addActivity($a);
            $nights[$a->invoiceId][$a->propertyId] = ($nights[$a->invoiceId][$a->propertyId] ?? 0) + $a->nights;
            $key = $a->propertyId . '|' . $a->month . '|' . $a->platform;
            $before = $platforms[$key] ?? new PlatformMonth($a->propertyId, $a->month, $a->platform, 0, 0, 0);
            $platforms[$key] = new PlatformMonth(
                $a->propertyId,
                $a->month,
                $a->platform,
                $before->reservations + $a->reservations,
                $before->nights + $a->nights,
                $before->payoutCents + $a->payoutCents,
            );
        }

        /** @var array<string, list<StatsBilled>> $extras prestations (sans logement), par facture */
        $extras = [];
        $billedMonths = [];
        foreach ($this->stats->billed($ownerId) as $b) {
            $billedMonths[$b->month] = true;
            if ($b->propertyId === null) {
                $extras[$b->invoiceId][] = $b;
                continue;
            }
            $cell($b->propertyId, $b->month)->addBilled($b->poste, $b->ttcCents);
        }
        $active = array_values(array_filter($properties, static fn($p): bool => $p->active));
        foreach ($extras as $invoiceId => $amounts) {
            [$targets, $weights] = Allocation::targets($nights[$invoiceId] ?? [], $active);
            foreach ($amounts as $amount) {
                $shares = Allocation::split($amount->ttcCents, $weights);
                foreach ($targets as $i => $propertyId) {
                    $cell($propertyId, $amount->month)->addBilled($amount->poste, $shares[$i]);
                }
            }
        }
        foreach ($cells as $c) {
            $billedMonths[$c->month] = true;
        }

        // Séjours propriétaire : seulement les mois facturés (un mois pas encore facturé n'a pas de ligne).
        foreach ($this->stats->ownerNights($ownerId) as $stay) {
            if (isset($billedMonths[$stay->month])) {
                $cell($stay->propertyId, $stay->month)->addOwnerNights($stay->nights);
            }
        }

        $records = array_map(static fn(StatsCell $c): MonthStats => $c->toMonthStats(), array_values($cells));
        usort($records, static fn(MonthStats $a, MonthStats $b): int => [$a->month, $a->propertyId ?? ''] <=> [$b->month, $b->propertyId ?? '']);
        $platformList = array_values($platforms);
        usort($platformList, static fn(PlatformMonth $a, PlatformMonth $b): int => [$a->month, $a->propertyId, $a->platform] <=> [$b->month, $b->propertyId, $b->platform]);

        return new OwnerStats($properties, $records, $platformList);
    }
}
