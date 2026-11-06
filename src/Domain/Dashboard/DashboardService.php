<?php

declare(strict_types=1);

namespace Casamiza\Domain\Dashboard;

use Casamiza\Domain\Cost\BeyondStatementRepositoryInterface;
use Casamiza\Domain\Statement\BillingMonth;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Chiffres du tableau de bord : une ligne par (logement, mois facturé), calculée sur les **factures non annulées** à
 * partir des colonnes figées (`invoice_reservations`, `invoice_lines`), jamais de l'instantané JSON.
 *
 * - l'activité (réservations, nuits, volume locatif) vient de `invoice_reservations` ;
 * - le facturé (conciergerie, linge, ménage, HT et TTC) vient de `invoice_lines` ;
 * - une **prestation** n'a pas de logement : son montant est réparti entre les logements de la facture au prorata des
 *   nuits (à défaut, à parts égales entre les logements actifs du propriétaire ; à défaut, ligne « sans logement ») ;
 * - le coût logiciel est la somme de deux coûts réels, détaillés en `guestyCost` et `beyondCost` : l'abonnement Guesty (factures
 *   importées, au mois de la période facturée) et les frais Beyond des réservations du logement dont le check-out tombe dans le mois
 *   (`reservations.beyond_fee`).
 */
final readonly class DashboardService
{
    public function __construct(
        private DashboardRepositoryInterface $dashboard,
        private BeyondStatementRepositoryInterface $beyondStatements,
    ) {}

    public function overview(): DashboardOverview
    {
        $properties = $this->dashboard->properties();

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
            [$targets, $weights] = Allocation::targets($nights[$invoiceId] ?? [], $activeByOwner[$amounts[0]->ownerId] ?? []);
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
            // Un frais sans revenu ce mois-là (annulation, séjour propriétaire) ou sans logement connu (titre d'annonce inconnu)
            // a quand même sa ligne, si le mois est facturé : sinon il serait compté nulle part.
            if (isset($billedMonths[BillingMonth::of($fee->checkOut)])) {
                $cell($fee->propertyId, BillingMonth::of($fee->checkOut));
            }
            $key = ($fee->propertyId ?? '') . '|' . BillingMonth::of($fee->checkOut);
            $beyond[$key] = ($beyond[$key] ?? 0) + $fee->cents;
        }

        // Abonnement Guesty : facturé pour chaque annonce active, avec ou sans réservation. Un logement qui n'a rien rapporté ce mois-là
        // (inactif, vacant) a donc sa ligne, pour que la marge reflète le coût réel ; un mois pas encore facturé n'a pas de ligne.
        $guesty = [];
        $guestyCosts = $this->dashboard->guestyCosts();
        foreach ($guestyCosts as $cost) {
            if (!isset($billedMonths[$cost->month])) {
                continue;
            }
            $cell($cost->propertyId, $cost->month);
            $key = ($cost->propertyId ?? '') . '|' . $cost->month;
            $guesty[$key] = ($guesty[$key] ?? 0) + $cost->cents;
        }

        $costGaps = $this->costGaps(array_keys($billedMonths), $guestyCosts);

        $records = [];
        foreach ($cells as $key => $c) {
            $records[] = $c->toRecord($guesty[$key] ?? 0, $beyond[$key] ?? 0);
        }
        usort($records, static fn(PropertyMonth $a, PropertyMonth $b): int => [$a->month, $a->propertyId ?? ''] <=> [$b->month, $b->propertyId ?? '']);

        return new DashboardOverview($properties, $records, $costGaps);
    }

    /**
     * Mois facturés dont les coûts logiciels sont incomplets :
     * - Guesty facture d'avance le mois entier : aucun coût Guesty sur aucun logement ce mois-là = facture non importée ;
     * - les frais Beyond d'un mois viennent des relevés du 12 au 12 qui le couvrent (celui du 12 du mois précédent et celui
     *   du 12 du mois) : l'un des deux pas traité (à importer, ou période pas encore terminée) = incomplet.
     *
     * @param list<string|int> $months mois facturés (AAAA-MM)
     * @param list<GuestyCost> $guestyCosts
     * @return list<CostGap> du plus ancien au plus récent
     */
    private function costGaps(array $months, array $guestyCosts): array
    {
        $months = array_map('strval', $months);
        sort($months);
        if ($months === []) {
            return [];
        }

        $guestyByMonth = [];
        foreach ($guestyCosts as $cost) {
            $guestyByMonth[$cost->month] = ($guestyByMonth[$cost->month] ?? 0) + $cost->cents;
        }

        $utc = new DateTimeZone('UTC');
        $first = new DateTimeImmutable($months[0] . '-12', $utc);
        $last = new DateTimeImmutable($months[count($months) - 1] . '-12', $utc);
        $processed = [];
        foreach ($this->beyondStatements->startingBetween($first->modify('-1 month'), $last->modify('+1 day')) as $statement) {
            $processed[$statement->period->key()] = true;
        }

        $gaps = [];
        foreach ($months as $month) {
            $day = new DateTimeImmutable($month . '-12', $utc);
            $beyondMissing = !isset($processed[$day->format('Y-m-d')]) || !isset($processed[$day->modify('-1 month')->format('Y-m-d')]);
            $guestyMissing = ($guestyByMonth[$month] ?? 0) <= 0;
            if ($beyondMissing || $guestyMissing) {
                $gaps[] = new CostGap($month, $guestyMissing, $beyondMissing);
            }
        }

        return $gaps;
    }
}
