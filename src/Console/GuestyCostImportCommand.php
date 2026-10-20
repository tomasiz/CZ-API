<?php

declare(strict_types=1);

namespace Casamiza\Console;

use Casamiza\Domain\Cost\GuestyCostService;
use Casamiza\Domain\Tenant\TenantRepositoryInterface;
use Casamiza\Shared\Exception\ApiException;
use Casamiza\Shared\TenantContext;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Importe d'un coup les factures Guesty d'un dossier (`invoice_INV00814774.xlsx`, export xlsx de la page Facturation), de la plus
 * ancienne à la plus récente (les numéros croissent avec le temps : en cas de ligne vue deux fois, la première importée compte).
 * Même service que l'écran : mêmes règles, **une facture après l'autre, chacune en tout ou rien**. **Simulation par défaut**,
 * `--apply` pour écrire. Une facture déjà importée est réécrite à l'identique (rejouable).
 *
 * `--alias="Nom de l'annonce=Nom du logement"` (répétable) rattache à un logement une annonce renommée depuis ; enregistré avec
 * `--apply`, et pris en compte dans la simulation.
 *
 *   bin/console guesty:import --tenant=<id> --dir=/chemin/des/xlsx [--alias="RUE714=Santa Lina 714"] [--apply]
 */
#[AsCommand(name: 'guesty:import', description: 'Importe les factures Guesty (xlsx) d\'un dossier (simulation par défaut)')]
final class GuestyCostImportCommand extends Command
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantContext $context,
        private readonly GuestyCostService $costs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Identifiant du tenant qui reçoit les factures')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Dossier des fichiers invoice_INV….xlsx')
            ->addOption('alias', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Nom d\'annonce=nom du logement (annonce renommée depuis)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Écrit réellement (sans cette option : simulation)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tenantId = $input->getOption('tenant');
        $dir = $input->getOption('dir');
        if (!is_string($tenantId) || $tenantId === '' || !is_string($dir) || !is_dir($dir)) {
            $output->writeln('<error>Indiquer --tenant=<id> et --dir=<dossier des factures>.</error>');

            return Command::INVALID;
        }
        if ($this->tenants->find($tenantId) === null) {
            $output->writeln('<error>Tenant inconnu.</error>');

            return Command::FAILURE;
        }

        $files = [];
        foreach (scandir($dir) ?: [] as $name) {
            if (preg_match('/^invoice_INV(\d+)\.xlsx$/i', $name, $m) === 1) {
                $files[(int) $m[1]] = $name;
            }
        }
        ksort($files);
        if ($files === []) {
            $output->writeln('<error>Aucun fichier invoice_INV….xlsx dans ce dossier.</error>');

            return Command::INVALID;
        }

        $apply = (bool) $input->getOption('apply');
        $output->writeln($apply ? '<comment>Import réel.</comment>' : '<comment>Simulation : rien n\'est écrit (--apply pour écrire).</comment>');

        $this->context->reset();
        $this->context->set($tenantId);

        $aliases = [];
        foreach ((array) $input->getOption('alias') as $pair) {
            [$nickname, $property] = array_pad(explode('=', (string) $pair, 2), 2, '');
            try {
                [$key, $propertyId] = $this->costs->resolveAlias(trim($nickname), trim($property));
            } catch (ApiException) {
                $output->writeln(sprintf('<error>Alias refusé : aucun logement (ou plusieurs) pour « %s ».</error>', trim($property)));
                $this->context->reset();

                return Command::INVALID;
            }
            $aliases[$key] = $propertyId;
            if ($apply) {
                $this->costs->saveAlias($key, $propertyId);
            }
        }

        $failed = 0;
        $totalUsd = 0;
        $total = 0;
        $unmatched = [];
        $duplicates = 0;
        foreach ($files as $name) {
            $bytes = file_get_contents($dir . '/' . $name);
            if ($bytes === false) {
                $failed++;
                $output->writeln(sprintf('%s : <error>fichier illisible</error>', $name));
                continue;
            }

            try {
                $report = $this->costs->import($name, $bytes, $apply, $aliases);
                $totalUsd += $report->amountUsdCents;
                $total += $report->amountCents;
                $duplicates += count($report->duplicates());
                foreach ($report->unmatched() as $row) {
                    $unmatched[$row->item->line->nickname] = ($unmatched[$row->item->line->nickname] ?? 0) + $row->item->amountCents;
                }
                $output->writeln(sprintf(
                    '%s : %s, %s, %d ligne(s), %s $ → %s € (cours %s)%s%s',
                    $name,
                    $apply ? ($report->replaced ? 'réimporté' : 'importé') : 'simulé',
                    $report->invoiceDate->format('d/m/Y'),
                    $report->linesRead,
                    number_format($report->amountUsdCents / 100, 2, ',', ' '),
                    number_format($report->amountCents / 100, 2, ',', ' '),
                    $report->rate,
                    $report->unmatched() === [] ? '' : sprintf(' ; %d sans logement', count($report->unmatched())),
                    $report->duplicates() === [] ? '' : sprintf(' ; %d ligne(s) déjà comptée(s) ailleurs', count($report->duplicates())),
                ));
            } catch (ApiException $e) {
                $failed++;
                $output->writeln(sprintf('%s : <error>refusée (%s)</error>', $name, $e->errorCode()));
                foreach ($e->errors() as $field => $codes) {
                    $output->writeln(sprintf('    %s : %s', $field, implode(', ', $codes)));
                }
            } catch (Throwable $e) {
                $failed++;
                $output->writeln(sprintf('%s : <error>échec (%s)</error>', $name, $e::class));
            }
        }
        $this->context->reset();

        $output->writeln(sprintf(
            '%d facture(s) en erreur, total %s $ → %s € comptés, %d ligne(s) écartée(s) car déjà comptées.',
            $failed,
            number_format($totalUsd / 100, 2, ',', ' '),
            number_format($total / 100, 2, ',', ' '),
            $duplicates,
        ));
        if ($unmatched !== []) {
            $output->writeln('Annonces sans logement (comptées dans « sans logement ») :');
            foreach ($unmatched as $nickname => $cents) {
                $output->writeln(sprintf('    %s : %s €', $nickname, number_format($cents / 100, 2, ',', ' ')));
            }
        }

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
