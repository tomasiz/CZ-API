<?php

declare(strict_types=1);

namespace Casamiza\Console;

use Casamiza\Domain\Import\FacturizaOwnerImporter;
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
 * Reprend le SIRET et la raison sociale des propriétaires depuis un export SQL de Facturiza.
 * **Simulation par défaut** : rien n'est écrit sans `--apply`. L'export n'est jamais exécuté, seulement lu.
 *
 *   bin/console facturiza:import-owners --tenant=<id> --sql=/chemin/database.sql          # simulation
 *   bin/console facturiza:import-owners --tenant=<id> --sql=/chemin/database.sql --apply  # écriture
 *   ... --with-stripe-keys   # reprend aussi les clés Stripe (chiffrées avec APP_ENCRYPTION_KEY, jamais affichées)
 */
#[AsCommand(name: 'facturiza:import-owners', description: 'Reprend le SIRET et la raison sociale des propriétaires depuis un export SQL de Facturiza')]
final class ImportFacturizaOwnersCommand extends Command
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantContext $context,
        private readonly FacturizaOwnerImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Identifiant du tenant qui reçoit les données')
            ->addOption('sql', null, InputOption::VALUE_REQUIRED, 'Export SQL de Facturiza (database_*.sql)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Écrit réellement les données (sans cette option : simulation)')
            ->addOption('with-stripe-keys', null, InputOption::VALUE_NONE, 'Reprend aussi les clés Stripe des propriétaires (chiffrées ; jamais affichées)')
            ->addOption('stripe-only', null, InputOption::VALUE_NONE, 'Reprend seulement les clés Stripe (ni SIRET ni raison sociale : une valeur effacée dans Casamiza ne revient pas)');
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
            $legacy = FacturizaDump::rows((string) file_get_contents($path), 'owners');
            $this->context->reset();
            $this->context->set($tenantId);
            $stripeOnly = $input->getOption('stripe-only') === true;
            $report = $this->importer->import($legacy, $apply, $stripeOnly || $input->getOption('with-stripe-keys') === true, !$stripeOnly);
        } catch (Throwable $e) {
            $output->writeln('<error>Import impossible : ' . $e::class . ' (' . $e->getMessage() . ')</error>');

            return Command::FAILURE;
        } finally {
            $this->context->reset();
        }

        $output->writeln($apply ? '<info>Import appliqué.</info>' : '<comment>SIMULATION : rien n\'a été écrit (ajouter --apply).</comment>');
        $output->writeln(sprintf('%d propriétaire(s) de Facturiza avec un SIRET ou une raison sociale.', $report->legacyWithData));
        foreach ([
            ($apply ? 'Écrit' : 'À écrire') => $report->updated,
            'Déjà à jour' => $report->unchanged,
            'Conflit (valeur de Casamiza conservée)' => $report->conflicts,
            'Valeur non reprise (SIRET ni 9 ni 14 chiffres, clé Stripe de format inattendu)' => $report->invalid,
            'Introuvable dans Casamiza' => $report->notFound,
        ] as $title => $lines) {
            $output->writeln(sprintf("\n%s : %d", $title, count($lines)));
            foreach ($lines as [$name, $detail]) {
                $output->writeln(sprintf('  - %s : %s', $name, $detail));
            }
        }

        return Command::SUCCESS;
    }
}
