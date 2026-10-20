<?php

declare(strict_types=1);

namespace Casamiza\Domain\Cost;

use Casamiza\Domain\Audit\AuditAction;
use Casamiza\Domain\Audit\AuditLogInterface;
use Casamiza\Domain\Property\Property;
use Casamiza\Domain\Property\PropertyRepositoryInterface;
use Casamiza\Shared\Exception\NotFoundException;
use Casamiza\Shared\Exception\ValidationException;
use Casamiza\Shared\Transaction;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Factures de Guesty (export xlsx, montants en dollars) : le coût de l'abonnement, annonce par annonce et mois par mois.
 *
 * - Guesty facture chaque annonce active d'avance, le mois entier (20 $, puis 21 $ en octobre 2025, 22 $ en octobre 2026), et au
 *   prorata, sur la facture suivante, celles ajoutées en cours de mois. Le coût d'une ligne revient au **mois de son début**.
 * - Le fichier n'a ni date ni numéro : le numéro est lu dans son nom (`invoice_INV00814774.xlsx`), la date se déduit des
 *   périodes (`GuestyCostFile::invoiceDate`). Le cours USD→EUR est celui de la BCE à cette date, **fixé à l'import** ; chaque ligne
 *   est convertie séparément (arrondi au centime), le total d'une facture est la somme de ses lignes.
 * - Une facture est importée **en entier ou pas du tout**. L'importer de nouveau remplace ses lignes (cours recalculé).
 * - Le logement se trouve par le nom de l'annonce (réduit à ses lettres et chiffres : « Bonaparte8 T2 » = « Bonaparte 8 T2 »), après
 *   les noms rattachés à la main (`guesty_listing_aliases`) ; entre deux logements du même nom, le seul actif. Sans logement, le
 *   coût va dans la ligne « sans logement » du tableau de bord.
 * - Une ligne déjà portée par une autre facture (même annonce, même début de période) **ne compte qu'une fois** : la première
 *   importée compte, l'autre est enregistrée mais écartée (`counted` faux).
 *
 * `import(…, apply: false)` ne fait que simuler : même bilan, rien n'est écrit.
 */
final readonly class GuestyCostService
{
    public function __construct(
        private GuestyXlsxParser $parser,
        private GuestyCostRepositoryInterface $costs,
        private GuestyCostFileStorageInterface $files,
        private ExchangeRatesInterface $rates,
        private PropertyRepositoryInterface $properties,
        private Transaction $transaction,
        private ClockInterface $clock,
        private AuditLogInterface $audit,
    ) {}

    /** @return list<GuestyCostInvoice> */
    public function invoices(): array
    {
        return $this->costs->invoices();
    }

    /** @throws NotFoundException `invoice_not_found` */
    public function detail(string $number): GuestyCostReport
    {
        $invoice = $this->costs->findInvoice($number) ?? throw new NotFoundException('invoice_not_found');
        $names = $this->propertyNames();
        $rows = array_map(
            static fn(GuestyCostItem $i): GuestyCostRow => new GuestyCostRow($i, $i->propertyId === null ? null : ($names[$i->propertyId] ?? null)),
            $this->costs->items($invoice->id),
        );

        return new GuestyCostReport(true, $invoice->number, $invoice->invoiceDate, $invoice->rate, $invoice->linesCount, $invoice->amountUsdCents, $invoice->amountCents, false, $rows);
    }

    /**
     * @return array{name: string, contents: string} le fichier conservé, tel qu'il a été importé
     * @throws NotFoundException
     */
    public function file(string $number): array
    {
        $invoice = $this->costs->findInvoice($number) ?? throw new NotFoundException('invoice_not_found');

        return [
            'name' => $invoice->sourceFile,
            'contents' => $this->files->contents($invoice->filePath) ?? throw new NotFoundException('invoice_file_not_found'),
        ];
    }

    /**
     * Lit le fichier, le contrôle, convertit en euros et (si `$apply`) écrit le tout.
     *
     * @param array<string, string> $aliases noms d'annonce (clés `matchKey`) rattachés d'office à un logement, en plus de ceux enregistrés
     * @throws ValidationException le fichier est refusé en entier (voir GuestyXlsxParser, ou `invalid_file_name`)
     * @throws \Casamiza\Shared\Exception\ServiceUnavailableException `exchange_rate_unavailable`
     */
    public function import(string $sourceName, string $bytes, bool $apply, array $aliases = []): GuestyCostReport
    {
        if (preg_match('/INV\d{3,12}/i', $sourceName, $m) !== 1) {
            throw new ValidationException(['file' => ['invalid_file_name']]);
        }
        $number = strtoupper($m[0]);
        $file = $this->parser->parse($bytes);

        $invoiceDate = $file->invoiceDate();
        $rate = $this->rates->usdToEur($invoiceDate);
        $existing = $this->costs->findInvoice($number);
        $invoiceId = $existing->id ?? Uuid::uuid7()->toString();

        $resolve = $this->resolver($aliases);
        $seen = $this->costs->countedLines($existing?->id);
        $items = [];
        foreach ($file->lines as $line) {
            $key = $line->listingKey() . '|' . $line->from->format('Y-m-d');
            $items[] = new GuestyCostItem(Uuid::uuid7()->toString(), $invoiceId, $line, $resolve($line->nickname), self::convert($line->usdCents, $rate), !isset($seen[$key]));
            $seen[$key] = true;
        }
        $amount = array_sum(array_map(static fn(GuestyCostItem $i): int => $i->counted ? $i->amountCents : 0, $items));

        $names = $this->propertyNames();
        $report = fn(bool $applied): GuestyCostReport => new GuestyCostReport(
            $applied,
            $number,
            $invoiceDate,
            $rate,
            count($items),
            $file->usdCents(),
            $amount,
            $existing !== null,
            array_map(static fn(GuestyCostItem $i): GuestyCostRow => new GuestyCostRow($i, $i->propertyId === null ? null : ($names[$i->propertyId] ?? null)), $items),
        );
        if (!$apply) {
            return $report(false);
        }

        $stored = $this->files->store($bytes, $invoiceDate->format('Y'));
        try {
            $result = $this->transaction->run(function () use ($existing, $invoiceId, $number, $sourceName, $stored, $invoiceDate, $rate, $items, $file, $amount, $report): GuestyCostReport {
                $invoice = new GuestyCostInvoice($invoiceId, $number, $sourceName, $stored->path, $stored->sha256, $invoiceDate, $rate, count($items), $file->usdCents(), $amount, $this->clock->now(), $file->periodStart(), $file->periodEnd(), $file->listingsCount());
                $this->costs->save($invoice, $items);
                $this->audit->record(AuditAction::GuestyCostsImported, 'guesty_cost_invoice', $invoiceId, $number, [
                    'lines' => count($items),
                    'amountUsd' => $file->usdCents() / 100,
                    'amount' => $amount / 100,
                    'duplicates' => count(array_filter($items, static fn(GuestyCostItem $i): bool => !$i->counted)),
                    'replaced' => $existing !== null,
                ]);

                return $report(true);
            });
        } catch (\Throwable $e) {
            $this->files->discard($stored->path);

            throw $e;
        }
        if ($existing !== null) {
            $this->files->discard($existing->filePath);
        }

        return $result;
    }

    /**
     * Supprime une facture : ses lignes ne comptent plus dans les coûts (une ligne qu'elle seule faisait compter reste comptée
     * par l'autre facture qui la portait), son fichier est supprimé. La facture peut être importée de nouveau.
     *
     * @throws NotFoundException `invoice_not_found`
     */
    public function delete(string $number): void
    {
        $invoice = $this->costs->findInvoice($number) ?? throw new NotFoundException('invoice_not_found');

        $this->transaction->run(function () use ($invoice): void {
            $this->costs->deleteInvoice($invoice->id);
            $this->audit->record(AuditAction::GuestyCostInvoiceDeleted, 'guesty_cost_invoice', $invoice->id, $invoice->number, [
                'lines' => $invoice->linesCount,
                'amountUsd' => $invoice->amountUsdCents / 100,
                'amount' => $invoice->amountCents / 100,
            ]);
        });
        $this->files->discard($invoice->filePath);
    }

    /**
     * Rattache à un logement (trouvé par son nom) le nom d'une annonce renommée depuis.
     *
     * @return array{0: string, 1: string} la clé du nom d'annonce et l'identifiant du logement
     * @throws NotFoundException `property_not_found` (aucun logement, ou deux, de ce nom)
     */
    public function resolveAlias(string $nickname, string $propertyName): array
    {
        $found = $this->candidates(GuestyCostLine::matchKey($propertyName)) ?? throw new NotFoundException('property_not_found');

        return [GuestyCostLine::matchKey($nickname), $found->id];
    }

    public function saveAlias(string $matchKey, string $propertyId): void
    {
        $this->costs->saveAlias($matchKey, $propertyId);
    }

    /**
     * @param array<string, string> $extra
     * @return \Closure(string): ?string identifiant du logement d'un nom d'annonce
     */
    private function resolver(array $extra): \Closure
    {
        $aliases = $extra + $this->costs->aliases();

        return function (string $nickname) use ($aliases): ?string {
            $key = GuestyCostLine::matchKey($nickname);

            return $aliases[$key] ?? $this->candidates($key)?->id;
        };
    }

    /** Le logement qui porte ce nom : le seul, sinon le seul actif. */
    private function candidates(string $matchKey): ?Property
    {
        $same = array_values(array_filter($this->properties->all(), static fn(Property $p): bool => GuestyCostLine::matchKey($p->name) === $matchKey));
        if (count($same) === 1) {
            return $same[0];
        }
        $active = array_values(array_filter($same, static fn(Property $p): bool => $p->archivedAt === null));

        return count($active) === 1 ? $active[0] : null;
    }

    /** @return array<string, string> */
    private function propertyNames(): array
    {
        $names = [];
        foreach ($this->properties->all() as $property) {
            $names[$property->id] = $property->name;
        }

        return $names;
    }

    /** Dollars → euros, au centime, demi supérieur (le montant d'une ligne, jamais d'un total). */
    private static function convert(int $usdCents, string $rate): int
    {
        $micro = (int) round((float) $rate * 1_000_000);

        return ($usdCents < 0 ? -1 : 1) * intdiv(abs($usdCents) * $micro + 500_000, 1_000_000);
    }
}
