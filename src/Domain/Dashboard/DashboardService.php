<?php

declare(strict_types=1);

namespace Casamiza\Domain\Dashboard;

use Casamiza\Domain\Company\CompanySettings;
use Casamiza\Domain\Company\CompanySettingsRepositoryInterface;
use Casamiza\Domain\Statement\BillingMonth;

/**
 * Chiffres du tableau de bord : une ligne par (logement, mois facturé), calculée sur les **factures non annulées** à
 * partir des colonnes figées (`invoice_reservations`, `invoice_lines`), jamais de l'instantané JSON.
 *
 * - l'activité (réservations, nuits, volume locatif) vient de `invoice_reservations` ;
 * - le facturé (conciergerie, linge, ménage, HT et TTC) vient de `invoice_lines` ;
 * - une **prestation** n'a pas de logement : son montant est réparti entre les logements de la facture au prorata des
 *   nuits (à défaut, à parts égales entre les logements actifs du propriétaire ; à défaut, ligne « sans logement ») ;
 * - le coût logiciel du réglage de l'entreprise est compté une fois par ligne de logement, **plus** les frais Beyond des
 *   réservations de ce logement dont le check-out tombe dans le mois (coût réel, `reservations.beyond_fee`) : les deux
 *   s'additionnent dans `softwareCost`, détaillé en `guestyCost` (réglage) et `beyondCost`.
 */
final readonly class DashboardService
{
    public function __construct(
        private DashboardRepositoryInterface $dashboard,
        private CompanySettingsRepositoryInterface $company,
    ) {}

    public function overview(): DashboardOverview
    {
        $properties = $this->dashboard->properties();
        $costCents = (int) round(($this->company->find() ?? CompanySettings::blank())->softwareCost * 100);

        /** @var array<string, MonthCell> $cells clé « logement|mois » */
        $cells = [];
        $cell = static function (?string $propertyId, string $month) use (&$cells): MonthCell {
            return $cells[($propertyId ?? '') . '|' . $month] ??= new MonthCell($propertyId, $month);
        };

        /** @var array<string, array<string, int>> $nights nuits de chaque logement, par facture */
        $nights = [];
        foreach ($this->dashboard->activity() as $activity) {
            $cell($activity->propertyId, $activity->month)->addActivity($activity->reservations, $activity->nights, $activity->payoutCents, $activity->ownerNetCents);
            $nights[$activity->invoiceId][$activity->propertyId] = ($nights[$activity->invoiceId][$activity->propertyId] ?? 0) + $activity->nights;
        }

        /** @var array<string, list<BilledAmount>> $extras prestations (sans logement), par facture */
        $extras = [];
        foreach ($this->dashboard->billed() as $billed) {
            if ($billed->propertyId === null) {
                $extras[$billed->invoiceId][] = $billed;
                continue;
            }
            $cell($billed->propertyId, $billed->month)->addBilled($billed->category, $billed->ttcCents, $billed->htCents);
        }

        $activeByOwner = [];
        foreach ($properties as $property) {
            if ($property->active && $property->ownerId !== null) {
                $activeByOwner[$property->ownerId][] = $property;
            }
        }
        foreach ($extras as $invoiceId => $amounts) {
            [$targets, $weights] = $this->targets($nights[$invoiceId] ?? [], $activeByOwner[$amounts[0]->ownerId] ?? []);
            foreach ($amounts as $amount) {
                $ttc = Allocation::split($amount->ttcCents, $weights);
                $ht = Allocation::split($amount->htCents, $weights);
                foreach ($targets as $i => $propertyId) {
                    $cell($propertyId, $amount->month)->addBilled($amount->category, $ttc[$i], $ht[$i]);
                }
            }
        }

        // Frais Beyond : portés par chaque réservation, rattachés au mois de son check-out (heure de Paris, comme sa facture).
        // Une ligne (logement, mois) n'existe que si le mois est facturé : les frais d'un mois pas encore facturé n'apparaissent pas.
        $beyond = [];
        // Les clés des cellules sont « logement|mois » : les mois facturés.
        $billedMonths = array_flip(array_map(static fn(string $key): string => substr($key, (int) strrpos($key, '|') + 1), array_keys($cells)));
        foreach ($this->dashboard->beyondFees() as $fee) {
            // Un coût sans logement connu (titre d'annonce inconnu) a sa propre ligne « sans logement », si le mois est facturé.
            if ($fee->propertyId === null && isset($billedMonths[BillingMonth::of($fee->checkOut)])) {
                $cell(null, BillingMonth::of($fee->checkOut));
            }
            $key = ($fee->propertyId ?? '') . '|' . BillingMonth::of($fee->checkOut);
            $beyond[$key] = ($beyond[$key] ?? 0) + $fee->cents;
        }

        $records = [];
        foreach ($cells as $key => $c) {
            $records[] = $c->toRecord($costCents, $beyond[$key] ?? 0);
        }
        usort($records, static fn(PropertyMonth $a, PropertyMonth $b): int => [$a->month, $a->propertyId ?? ''] <=> [$b->month, $b->propertyId ?? '']);

        return new DashboardOverview($properties, $records);
    }

    /**
     * Les logements qui se partagent les prestations d'une facture, et leurs poids.
     *
     * @param array<string, int> $nights nuits facturées par logement
     * @param list<DashboardProperty> $owned logements actifs du propriétaire
     * @return array{non-empty-list<string|null>, non-empty-list<int>}
     */
    private function targets(array $nights, array $owned): array
    {
        if ($nights !== []) {
            // Ordre stable (l'ordre des lignes SQL ne l'est pas) : à égalité de reste, c'est le même logement qui gagne le centime.
            ksort($nights);

            return [array_map('strval', array_keys($nights)), array_values($nights)];
        }
        usort($owned, static fn(DashboardProperty $a, DashboardProperty $b): int => [$a->name, $a->id] <=> [$b->name, $b->id]);
        if ($owned === []) {
            return [[null], [1]];
        }

        return [array_map(static fn(DashboardProperty $p): string => $p->id, $owned), array_fill(0, count($owned), 1)];
    }
}
