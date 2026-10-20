<?php

declare(strict_types=1);

namespace Casamiza\Domain\Cost;

use Casamiza\Domain\Audit\AuditAction;
use Casamiza\Domain\Audit\AuditLogInterface;
use Casamiza\Domain\Guesty\GuestyReservation;
use Casamiza\Domain\Property\PropertyRepositoryInterface;
use Casamiza\Domain\Reservation\ReservationSyncService;
use Casamiza\Shared\Exception\NotFoundException;
use Casamiza\Shared\Exception\UnprocessableException;
use Casamiza\Shared\Exception\ValidationException;
use Casamiza\Shared\Transaction;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Relevés de Beyond Pricing, du 12 au 12 (CSV « par réservation »).
 *
 * - Beyond facture à la date de prise de réservation, d'annulation ou de modification, pas à celle du séjour : un relevé
 *   contient donc des réservations futures, et des annulations de réservations très anciennes.
 * - Un relevé est traité **en entier ou pas du tout**. Les réservations qui manquent à Casamiza sont lues chez Guesty par leur
 *   identifiant (une annulée est enregistrée archivée) ; un logement absent de Casamiza fait refuser le relevé avec la liste des
 *   logements en cause. Une ligne dont la réservation est introuvable chez Guesty (logement disparu, ancien outil) est enregistrée
 *   sans réservation : son coût compte pour le logement (trouvé par le titre de l'annonce) et le mois du séjour.
 * - Les lignes de séjours terminés avant le 1er janvier 2025 sont ignorées (ni contrôlées, ni enregistrées, ni comptées).
 * - Seule une période **terminée** est importable (le relevé en cours est incomplet).
 * - La date d'une ligne ne désigne pas son relevé (Beyond facture parfois des semaines après la prise de réservation). Un fichier
 *   déposé sur le mauvais relevé est refusé par son nom (`period_mismatch`) ou parce qu'une ligne appartient déjà à un autre relevé.
 * - Une ligne est reconnue par son identifiant Beyond : remplacer le fichier d'un relevé traité n'ajoute que les lignes nouvelles,
 *   et le nouveau fichier doit contenir toutes les lignes déjà connues.
 * - `reservations.beyond_fee` est **recalculé** comme la somme des lignes de la réservation (jamais une addition à l'aveugle).
 * - Les appels à Guesty se font avant la transaction ; le CSV est conservé pour le retélécharger et le retraiter.
 *
 * `import(…, apply: false)` ne fait que simuler : même bilan (Guesty est lu), rien n'est écrit.
 */
final readonly class BeyondStatementService
{
    private const MAX_REPORTED_ERRORS = 10;
    /** Premier check-out pris en compte : les mois facturés par Casamiza commencent en janvier 2025. */
    private const FIRST_CHECK_OUT = '2025-01-01';
    private const MIN_YEAR = 2020;
    private const MAX_YEAR = 2100;

    public function __construct(
        private BeyondCsvParser $parser,
        private BeyondFeeRepositoryInterface $items,
        private BeyondStatementRepositoryInterface $statements,
        private BeyondFileStorageInterface $files,
        private ReservationSyncService $reservations,
        private PropertyRepositoryInterface $properties,
        private Transaction $transaction,
        private ClockInterface $clock,
        private AuditLogInterface $audit,
    ) {}

    /**
     * Les 12 relevés qui commencent dans l'année (du 12 janvier au 12 décembre), traités ou non.
     *
     * @return list<BeyondStatementRow>
     * @throws ValidationException `year` hors des bornes
     */
    public function year(int $year): array
    {
        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            throw new ValidationException(['year' => ['out_of_range']]);
        }
        $now = $this->clock->now();
        $first = BeyondPeriod::of($year, 1);
        $processed = [];
        foreach ($this->statements->startingBetween($first->start, BeyondPeriod::of($year + 1, 1)->start) as $statement) {
            $processed[$statement->period->key()] = $statement;
        }

        $rows = [];
        for ($month = 1; $month <= 12; $month++) {
            $period = BeyondPeriod::of($year, $month);
            $rows[] = new BeyondStatementRow($period, $period->isClosedAt($now), $processed[$period->key()] ?? null);
        }

        return $rows;
    }

    /**
     * Lit le CSV, le contrôle, récupère chez Guesty les réservations qui manquent, puis (si `$apply`) écrit le tout.
     *
     * @throws NotFoundException `statement_not_found` (le jour n'est pas un 12), `integration_not_configured`
     * @throws UnprocessableException `statement_not_closed`
     * @throws ValidationException le relevé est refusé en entier (voir BeyondCsvParser, puis `outside_period`, `item_in_other_statement`,
     *                             `reservation_not_found`, `property_unknown`, `missing_lines`)
     */
    public function import(DateTimeImmutable $start, string $csv, string $sourceName, bool $apply): BeyondImportReport
    {
        $period = $this->closedPeriod($start);
        // Beyond nomme ses fichiers « transactions-AAAA-MM-JJ_AAAA-MM-JJ.csv » : un nom qui porte une autre période est une erreur de relevé.
        if (preg_match('/(\d{4}-\d{2}-\d{2})_(\d{4}-\d{2}-\d{2})/', $sourceName, $m) === 1 && ($m[1] !== $period->key() || $m[2] !== $period->end->format('Y-m-d'))) {
            throw new ValidationException(['file' => ['period_mismatch']]);
        }
        $parsed = $this->parser->parse($csv);

        return $this->process($period, $parsed, $csv, $sourceName, $apply, $this->statements->find($period), storeFile: true);
    }

    /** Rejoue le CSV conservé (recalcul des totaux ; les lignes déjà connues ne changent pas). */
    public function reprocess(DateTimeImmutable $start, bool $apply): BeyondImportReport
    {
        $period = $this->closedPeriod($start);
        $statement = $this->statements->find($period) ?? throw new NotFoundException('statement_not_found');
        $csv = $this->files->contents($statement->filePath) ?? throw new NotFoundException('statement_file_not_found');

        return $this->process($period, $this->parser->parse($csv), $csv, $statement->sourceFile, $apply, $statement, storeFile: false);
    }

    /** Ce que le relevé traité a apporté à chaque réservation (relu dans le CSV conservé). */
    public function detail(DateTimeImmutable $start): BeyondImportReport
    {
        $period = BeyondPeriod::startingOn($start) ?? throw new NotFoundException('statement_not_found');
        $statement = $this->statements->find($period) ?? throw new NotFoundException('statement_not_found');
        $csv = $this->files->contents($statement->filePath) ?? throw new NotFoundException('statement_file_not_found');
        $parsed = $this->parser->parse($csv);
        [$lines, $ignored] = $this->relevant($parsed->lines);
        $ignored += $parsed->nonBillable;
        $links = $this->items->reservationsByGuestyId($this->guestyIds($lines));
        // Les lignes sans réservation en base (introuvables chez Guesty à l'import) comptent pour leur logement et le mois du séjour.
        $kept = array_values(array_filter($lines, static fn(BeyondFeeLine $l): bool => isset($links[$l->pmsReservationId])));
        $unmatched = array_values(array_filter($lines, static fn(BeyondFeeLine $l): bool => !isset($links[$l->pmsReservationId])));
        $byTitle = $this->propertiesByTitle();

        return new BeyondImportReport(
            true,
            $period,
            count($lines),
            0,
            count($lines),
            0,
            $statement->amountCents,
            $this->warningsOf($parsed->warnings, $lines),
            $this->rows($kept, $links, []),
            $ignored,
            $unmatched,
            count(array_filter($unmatched, static fn(BeyondFeeLine $l): bool => !isset($byTitle[self::titleKey($l->listingTitle)]))),
        );
    }

    /**
     * Les logements par titre d'annonce (sans casse ni espaces superflus) : un titre porté par deux logements ne désigne personne.
     *
     * @return array<string, string> titre normalisé => identifiant du logement
     */
    private function propertiesByTitle(): array
    {
        $found = [];
        $ambiguous = [];
        foreach ($this->properties->all() as $property) {
            $key = self::titleKey($property->name);
            if (isset($found[$key])) {
                $ambiguous[$key] = true;
            }
            $found[$key] = $property->id;
        }

        return array_diff_key($found, $ambiguous);
    }

    private static function titleKey(string $title): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $title)));
    }

    /**
     * Écarte les lignes dont le séjour était terminé avant le début de la facturation (Casamiza commence en janvier 2025) : leur
     * coût ne tomberait dans aucun mois facturé, et leurs réservations ne sont pas synchronisées.
     *
     * @param list<BeyondFeeLine> $lines
     * @return array{0: list<BeyondFeeLine>, 1: int} les lignes retenues, le nombre de lignes écartées
     */
    private function relevant(array $lines): array
    {
        $kept = array_values(array_filter(
            $lines,
            static fn(BeyondFeeLine $l): bool => $l->checkIn->modify("+{$l->nights} days")->format('Y-m-d') >= self::FIRST_CHECK_OUT,
        ));

        return [$kept, count($lines) - count($kept)];
    }

    /** @return array{name: string, contents: string} le CSV conservé, tel qu'il a été importé */
    public function file(DateTimeImmutable $start): array
    {
        $period = BeyondPeriod::startingOn($start) ?? throw new NotFoundException('statement_not_found');
        $statement = $this->statements->find($period) ?? throw new NotFoundException('statement_not_found');

        return [
            'name' => $statement->sourceFile,
            'contents' => $this->files->contents($statement->filePath) ?? throw new NotFoundException('statement_file_not_found'),
        ];
    }

    private function closedPeriod(DateTimeImmutable $start): BeyondPeriod
    {
        $period = BeyondPeriod::startingOn($start) ?? throw new NotFoundException('statement_not_found');
        if (!$period->isClosedAt($this->clock->now())) {
            throw new UnprocessableException('statement_not_closed');
        }

        return $period;
    }

    private function process(BeyondPeriod $period, BeyondCsvResult $parsed, string $csv, string $sourceName, bool $apply, ?BeyondStatement $existing, bool $storeFile): BeyondImportReport
    {
        [$lines, $ignored] = $this->relevant($parsed->lines);
        $ignored += $parsed->nonBillable;
        $errors = [];

        // Aucune ligne ne doit déjà appartenir à un autre relevé. La date ne dit rien du relevé : Beyond facture parfois des
        // semaines après la prise de réservation, une ligne figure dans le relevé qui la porte.
        $elsewhere = $this->items->statementIdsOfItems(array_map(static fn(BeyondFeeLine $l): int => $l->statementItemId, $lines));
        foreach ($lines as $line) {
            if (isset($elsewhere[$line->statementItemId]) && $elsewhere[$line->statementItemId] !== $existing?->id) {
                $this->addError($errors, "line_{$line->row}", 'item_in_other_statement');
            }
        }
        // Remplacer un relevé traité : aucune ligne déjà connue ne doit disparaître (sinon, c'est sans doute un autre fichier).
        $knownIds = $existing === null ? [] : $this->items->itemIdsOfStatement($existing->id);
        $known = array_flip($knownIds);
        $fileIds = array_flip(array_map(static fn(BeyondFeeLine $l): int => $l->statementItemId, $lines));
        if (array_diff_key($known, $fileIds) !== []) {
            $this->addError($errors, 'file', 'missing_lines');
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // Les réservations : celles de Casamiza, puis celles que Guesty doit nous donner.
        $links = $this->items->reservationsByGuestyId($this->guestyIds($lines));
        $missing = array_values(array_filter($this->guestyIds($lines), static fn(string $id): bool => !isset($links[$id])));
        $fetched = [];
        // Guesty refuse en bloc (400) un identifiant qui n'est pas un ObjectId : ceux-là ne lui sont jamais envoyés.
        $askable = array_values(array_filter($missing, static fn(string $id): bool => preg_match('/^[0-9a-f]{24}$/', $id) === 1));
        foreach ($askable === [] ? [] : $this->reservations->fetch($askable) as $guesty) {
            $fetched[$guesty->guestyId] = $guesty;
        }
        $propertyIds = [];
        foreach ($this->properties->all() as $property) {
            $propertyIds[$property->guestyId] = true;
        }
        // Une ligne dont la réservation n'existe ni dans Casamiza ni chez Guesty (logement disparu, réservation d'un ancien outil)
        // est enregistrée sans réservation : son coût compte pour le logement (trouvé par le titre) et le mois du séjour.
        $kept = [];
        $unmatched = [];
        foreach ($lines as $line) {
            $id = $line->pmsReservationId;
            if (isset($links[$id])) {
                $kept[] = $line;
            } elseif (!isset($fetched[$id])) {
                $unmatched[] = $line;
            } else {
                if (!isset($propertyIds[$fetched[$id]->listingGuestyId])) {
                    // Un logement inconnu concerne souvent plusieurs réservations : on le nomme une fois.
                    $this->addError($errors, 'listing:' . $line->listingTitle, 'property_unknown');
                }
                $kept[] = $line;
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $byTitle = $this->propertiesByTitle();
        $lines = array_merge($kept, $unmatched);
        $withoutProperty = count(array_filter($unmatched, static fn(BeyondFeeLine $l): bool => !isset($byTitle[self::titleKey($l->listingTitle)])));

        $new = array_values(array_filter($lines, static fn(BeyondFeeLine $l): bool => !isset($known[$l->statementItemId])));
        $amount = array_sum(array_map(static fn(BeyondFeeLine $l): int => $l->amountCents, $lines));
        $report = fn(bool $applied, array $links): BeyondImportReport => new BeyondImportReport(
            $applied,
            $period,
            count($lines),
            count($new),
            count($lines) - count($new),
            count($fetched),
            $amount,
            $this->warningsOf($parsed->warnings, $lines),
            $this->rows($kept, $links, $fetched),
            $ignored,
            $unmatched,
            $withoutProperty,
        );
        if (!$apply) {
            return $report(false, $links + $this->previewLinks($fetched));
        }

        $stored = $storeFile ? $this->files->store($csv, $period) : null;
        try {
            $result = $this->transaction->run(function () use ($period, $existing, $stored, $sourceName, $fetched, $links, $lines, $new, $amount, $report, $byTitle): BeyondImportReport {
                foreach ($this->reservations->store(array_values($fetched)) as $guestyId => $reservation) {
                    $links[$guestyId] = new ReservationLink($reservation->id, $reservation->propertyId, $reservation->guest, $reservation->checkIn, $reservation->checkOut);
                }
                $now = $this->clock->now();
                $statement = new BeyondStatement(
                    $existing === null ? Uuid::uuid7()->toString() : $existing->id,
                    $period,
                    $stored === null ? ($existing->sourceFile ?? $sourceName) : $sourceName,
                    $stored->path ?? ($existing->filePath ?? ''),
                    $stored->sha256 ?? ($existing->fileSha256 ?? ''),
                    count($lines),
                    count($links),
                    $amount,
                    $now,
                );
                $this->statements->save($statement);

                $items = [];
                foreach ($new as $line) {
                    $link = $links[$line->pmsReservationId] ?? null;
                    $items[] = $link === null
                        ? new BeyondFeeItem(Uuid::uuid7()->toString(), $statement->id, $line, null, $byTitle[self::titleKey($line->listingTitle)] ?? null, $now)
                        : new BeyondFeeItem(Uuid::uuid7()->toString(), $statement->id, $line, $link->reservationId, $link->propertyId, $now);
                }
                $this->items->addItems($items);
                $this->items->recomputeReservationFees(array_values(array_map(static fn(ReservationLink $l): string => $l->reservationId, $links)));

                $this->audit->record(AuditAction::BeyondFeesImported, 'beyond_statement', $statement->id, $period->key(), [
                    'lines' => count($lines),
                    'new' => count($new),
                    'known' => count($lines) - count($new),
                    'fetched' => count($fetched),
                    'reservationsCount' => count($links),
                    'fileReplaced' => $existing !== null && $stored !== null,
                ]);

                return $report(true, $links);
            });
        } catch (\Throwable $e) {
            if ($stored !== null) {
                $this->files->discard($stored->path);
            }

            throw $e;
        }
        if ($existing !== null && $stored !== null) {
            $this->files->discard($existing->filePath);
        }

        return $result;
    }

    /**
     * Les avertissements des seules lignes retenues (ceux d'une ligne écartée n'ont plus d'objet).
     *
     * @param list<array{row: int, code: string}> $warnings
     * @param list<BeyondFeeLine> $lines
     * @return list<array{row: int, code: string}>
     */
    private function warningsOf(array $warnings, array $lines): array
    {
        $rows = array_flip(array_map(static fn(BeyondFeeLine $l): int => $l->row, $lines));

        return array_values(array_filter($warnings, static fn(array $w): bool => isset($rows[$w['row']])));
    }

    /** @param array<string, list<string>> $errors */
    private function addError(array &$errors, string $field, string $code): void
    {
        if (count($errors) < self::MAX_REPORTED_ERRORS) {
            $errors[$field] = [$code];
        }
    }

    /**
     * @param list<BeyondFeeLine> $lines
     * @return list<string>
     */
    private function guestyIds(array $lines): array
    {
        return array_values(array_unique(array_map(static fn(BeyondFeeLine $l): string => $l->pmsReservationId, $lines)));
    }

    /**
     * Pour une simulation : les réservations lues chez Guesty, qui n'existent pas encore dans Casamiza.
     *
     * @param array<string, GuestyReservation> $fetched
     * @return array<string, ReservationLink>
     */
    private function previewLinks(array $fetched): array
    {
        return array_map(static fn(GuestyReservation $g): ReservationLink => new ReservationLink('', '', $g->guest, $g->checkIn, $g->checkOut), $fetched);
    }

    /**
     * Une entrée par réservation, dans l'ordre du fichier.
     *
     * @param list<BeyondFeeLine> $lines
     * @param array<string, ReservationLink> $links
     * @param array<string, GuestyReservation> $fetched
     * @return list<BeyondReservationFee>
     */
    private function rows(array $lines, array $links, array $fetched): array
    {
        $grouped = [];
        foreach ($lines as $line) {
            $grouped[$line->pmsReservationId][] = $line;
        }
        $rows = [];
        foreach ($grouped as $guestyId => $group) {
            $link = $links[$guestyId] ?? null;
            if ($link === null) {
                continue;
            }
            $rows[] = new BeyondReservationFee(
                (string) $guestyId,
                $group[0]->listingTitle,
                $link->guest,
                $link->checkIn,
                $link->checkOut,
                isset($fetched[$guestyId]),
                $group,
                array_sum(array_map(static fn(BeyondFeeLine $l): int => $l->amountCents, $group)),
            );
        }

        return $rows;
    }
}
