<?php

declare(strict_types=1);

namespace Casamiza\Console;

use Casamiza\Domain\Cost\BeyondFeeLine;
use Casamiza\Domain\Cost\BeyondStatementService;
use Casamiza\Domain\Tenant\TenantRepositoryInterface;
use Casamiza\Shared\Exception\ApiException;
use Casamiza\Shared\TenantContext;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Importe d'un coup les relevés Beyond d'un dossier (`transactions-AAAA-MM-JJ_AAAA-MM-JJ.csv`, la période se lit dans le nom),
 * du plus ancien au plus récent. Même service que l'écran : mêmes règles, **un relevé après l'autre, chacun en tout ou rien**
 * (un relevé refusé n'empêche pas les suivants). **Simulation par défaut**, `--apply` pour écrire. Un relevé déjà traité est
 * laissé tel quel, une période pas encore terminée est passée.
 *
 *   bin/console beyond:import --tenant=<id> --dir=/chemin/des/csv [--apply]
 */
#[AsCommand(name: 'beyond:import', description: 'Importe les relevés Beyond Pricing d\'un dossier (simulation par défaut)')]
final class BeyondImportCommand extends Command
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantContext $context,
        private readonly BeyondStatementService $statements,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Identifiant du tenant qui reçoit les relevés')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Dossier des fichiers transactions-AAAA-MM-JJ_AAAA-MM-JJ.csv')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Écrit réellement (sans cette option : simulation)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tenantId = $input->getOption('tenant');
        $dir = $input->getOption('dir');
        if (!is_string($tenantId) || $tenantId === '' || !is_string($dir) || !is_dir($dir)) {
            $output->writeln('<error>Indiquer --tenant=<id> et --dir=<dossier des relevés>.</error>');

            return Command::INVALID;
        }
        if ($this->tenants->find($tenantId) === null) {
            $output->writeln('<error>Tenant inconnu.</error>');

            return Command::FAILURE;
        }

        $files = [];
        foreach (scandir($dir) ?: [] as $name) {
            // Les fichiers « transactions-by-listings-… » ont un autre format : ils ne correspondent pas.
            if (preg_match('/^transactions-(\d{4}-\d{2}-\d{2})_\d{4}-\d{2}-\d{2}\.csv$/', $name, $m) === 1) {
                $files[$m[1]] = $name;
            }
        }
        ksort($files);
        if ($files === []) {
            $output->writeln('<error>Aucun fichier transactions-AAAA-MM-JJ_AAAA-MM-JJ.csv dans ce dossier.</error>');

            return Command::INVALID;
        }

        $apply = (bool) $input->getOption('apply');
        $output->writeln($apply ? '<comment>Import réel.</comment>' : '<comment>Simulation : rien n\'est écrit (--apply pour écrire).</comment>');

        $this->context->reset();
        $this->context->set($tenantId);
        $failed = 0;
        $total = 0;
        $unmatched = 0;
        $unmatchedCents = 0;
        foreach ($files as $startDay => $name) {
            $start = new DateTimeImmutable($startDay, new DateTimeZone('UTC'));
            $csv = file_get_contents($dir . '/' . $name);
            if ($csv === false) {
                $failed++;
                $output->writeln(sprintf('%s : <error>fichier illisible</error>', $name));
                continue;
            }

            try {
                $report = $this->statements->import($start, $csv, $name, $apply);
                $total += $report->amountCents;
                $lost = array_sum(array_map(static fn(BeyondFeeLine $l): int => $l->amountCents, $report->unmatched));
                $unmatched += count($report->unmatched);
                $unmatchedCents += $lost;
                $output->writeln(sprintf(
                    '%s : %s, %d ligne(s) (%d nouvelle(s), %d connue(s)), %d réservation(s) lue(s) chez Guesty, %s €; à part : %d avant 2025 ignorées ; %d sans réservation, comptées au logement et au mois du séjour (%s €)%s',
                    $name,
                    $apply ? 'importé' : 'simulé',
                    $report->linesRead,
                    $report->linesNew,
                    $report->linesKnown,
                    $report->reservationsFetched,
                    number_format($report->amountCents / 100, 2, ',', ' '),
                    $report->linesIgnored,
                    count($report->unmatched),
                    number_format($lost / 100, 2, ',', ' '),
                    $report->warnings === [] ? '' : sprintf(' ; %d avertissement(s)', count($report->warnings)),
                ));
            } catch (ApiException $e) {
                if ($e->errorCode() === 'statement_not_closed') {
                    $output->writeln(sprintf('%s : passé (période pas encore terminée)', $name));
                    continue;
                }
                $failed++;
                $output->writeln(sprintf('%s : <error>refusé (%s)</error>', $name, $e->errorCode()));
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
            '%d relevé(s) en erreur, total %s €, %d ligne(s) sans réservation (%s €).',
            $failed,
            number_format($total / 100, 2, ',', ' '),
            $unmatched,
            number_format($unmatchedCents / 100, 2, ',', ' '),
        ));

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
