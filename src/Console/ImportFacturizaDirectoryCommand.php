<?php

declare(strict_types=1);

namespace Casamiza\Console;

use Casamiza\Domain\Import\FacturizaDirectoryImporter;
use Casamiza\Domain\Tenant\TenantRepositoryInterface;
use Casamiza\Infrastructure\Import\FacturizaDump;
use Casamiza\Shared\TenantContext;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Reprend les propriétaires et logements de Facturiza absents de Casamiza (archivés, avec toutes leurs données).
 * **Simulation par défaut** : rien n'est écrit sans `--apply`. L'export n'est jamais exécuté, seulement lu.
 *
 *   bin/console facturiza:import-directory --tenant=<id> --sql=/chemin/database.sql [--apply]
 */
#[AsCommand(name: 'facturiza:import-directory', description: 'Reprend les propriétaires et logements de Facturiza absents de Casamiza (archivés)')]
final class ImportFacturizaDirectoryCommand extends Command
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantContext $context,
        private readonly FacturizaDirectoryImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Identifiant du tenant qui reçoit les données')
            ->addOption('sql', null, InputOption::VALUE_REQUIRED, 'Export SQL de Facturiza (database_*.sql)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Écrit réellement les données (sans cette option : simulation)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tenantId = $input->getOption('tenant');
        $path = $input->getOption('sql');
        if (!is_string($tenantId) || $tenantId === '' || !is_string($path) || !is_file($path) || !is_readable($path)) {
            $output->writeln('<error>Indiquer --tenant=<id> et --sql=<fichier lisible>.</error>');

            return Command::INVALID;
        }
        if ($this->tenants->find($tenantId) === null) {
            $output->writeln('<error>Tenant introuvable.</error>');

            return Command::FAILURE;
        }
        $apply = $input->getOption('apply') === true;

        try {
            $sql = (string) file_get_contents($path);
            $this->context->reset();
            $this->context->set($tenantId);
            $report = $this->importer->import(FacturizaDump::rows($sql, 'owners'), FacturizaDump::rows($sql, 'listings'), $apply);
        } catch (Throwable $e) {
            $output->writeln('<error>Import impossible : ' . $e::class . ' (' . $e->getMessage() . ')</error>');

            return Command::FAILURE;
        } finally {
            $this->context->reset();
        }

        $output->writeln($apply ? '<info>Import appliqué.</info>' : '<comment>SIMULATION : rien n\'a été écrit (ajouter --apply).</comment>');
        $output->writeln(sprintf('Déjà dans Casamiza (non touchés) : %d propriétaire(s), %d logement(s).', $report->ownersPresent, $report->propertiesPresent));
        foreach ([
            ($apply ? 'Propriétaires créés (archivés)' : 'Propriétaires à créer (archivés)') => $report->owners,
            ($apply ? 'Logements créés (archivés)' : 'Logements à créer (archivés)') => $report->properties,
            'À noter' => $report->warnings,
        ] as $title => $lines) {
            $output->writeln(sprintf("\n%s : %d", $title, count($lines)));
            foreach ($lines as [$name, $detail]) {
                $output->writeln(sprintf('  - %s : %s', $name, $detail));
            }
        }

        return Command::SUCCESS;
    }
}
