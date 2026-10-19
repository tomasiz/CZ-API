<?php

declare(strict_types=1);

namespace Casamiza\Console;

use Casamiza\Domain\Audit\AuditAction;
use Casamiza\Domain\Audit\AuditLogInterface;
use Casamiza\Domain\Import\FacturizaInvoiceImporter;
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
 * Reprend l'historique de facturation de Facturiza : factures (PDF, récap lié, JSON), prestations et « pas de facture ce
 * mois-ci ». **Simulation par défaut** : rien n'est écrit sans `--apply`, et rien non plus si une vérification échoue.
 * L'export SQL n'est jamais exécuté, seulement lu. Les propriétaires et logements doivent déjà être dans Casamiza
 * (synchro Guesty, puis `facturiza:import-directory`).
 *
 *   bin/console facturiza:import --tenant=<id> --sql=/chemin/database.sql --files=/chemin/files/2 [--apply]
 */
#[AsCommand(name: 'facturiza:import', description: 'Reprend les factures, PDF, prestations et statuts de Facturiza')]
final class ImportFacturizaInvoicesCommand extends Command
{
    private const MAX_LISTED = 25;

    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantContext $context,
        private readonly FacturizaInvoiceImporter $importer,
        private readonly AuditLogInterface $audit,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Identifiant du tenant qui reçoit les données')
            ->addOption('sql', null, InputOption::VALUE_REQUIRED, 'Export SQL de Facturiza (database_*.sql)')
            ->addOption('files', null, InputOption::VALUE_REQUIRED, 'Dossier des PDF de Facturiza (files/2)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Écrit réellement les données (sans cette option : simulation)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tenantId = $input->getOption('tenant');
        $path = $input->getOption('sql');
        $files = $input->getOption('files');
        if (!is_string($tenantId) || $tenantId === '' || !is_string($path) || !is_file($path) || !is_readable($path) || !is_string($files) || !is_dir($files)) {
            $output->writeln('<error>Indiquer --tenant=<id>, --sql=<fichier lisible> et --files=<dossier des PDF>.</error>');

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
            $report = $this->importer->import(
                FacturizaDump::rows($sql, 'invoices'),
                FacturizaDump::rows($sql, 'documents'),
                FacturizaDump::rows($sql, 'paid_tasks'),
                FacturizaDump::rows($sql, 'no_invoice_this_month'),
                rtrim($files, '/'),
                $apply,
            );
        } catch (Throwable $e) {
            $output->writeln('<error>Import impossible : ' . $e::class . ' (' . $e->getMessage() . ')</error>');

            return Command::FAILURE;
        } finally {
            $this->context->reset();
        }

        if ($report->errors !== []) {
            $output->writeln('<error>VÉRIFICATIONS EN ÉCHEC : rien n\'a été écrit.</error>');
            $this->list($output, 'Erreurs', $report->errors);
        } else {
            if ($report->applied) {
                $this->audit->recordSystem($tenantId, 'cli', AuditAction::InvoicesImported, 'tenant', $tenantId, null, [
                    'invoices' => $report->invoices,
                    'lines' => $report->lines,
                    'extras' => $report->extras,
                    'nextNumber' => $report->nextNumber,
                ]);
            }
            $output->writeln($report->applied ? '<info>Import appliqué.</info>' : '<comment>SIMULATION : rien n\'a été écrit (ajouter --apply).</comment>');
        }
        $output->writeln(sprintf(
            "%s : %d facture(s), %d ligne(s), %d activité(s) de réservation, %d PDF.\nDéjà reprises (non touchées) : %d facture(s), %d prestation(s).\n%s : %d prestation(s), %d « pas de facture ».\nProchain numéro de facture : %d.",
            $report->applied ? 'Écrit' : 'À écrire',
            $report->invoices,
            $report->lines,
            $report->reservations,
            $report->files,
            $report->invoicesAlreadyImported,
            $report->extrasAlreadyImported,
            $report->applied ? 'Écrit aussi' : 'À écrire aussi',
            $report->extras,
            $report->nothingToInvoice,
            $report->nextNumber,
        ));
        $this->list($output, 'À noter', $report->warnings);

        return $report->errors === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /** @param list<string> $lines */
    private function list(OutputInterface $output, string $title, array $lines): void
    {
        $output->writeln(sprintf("\n%s : %d", $title, count($lines)));
        foreach (array_slice($lines, 0, self::MAX_LISTED) as $line) {
            $output->writeln('  - ' . $line);
        }
        if (count($lines) > self::MAX_LISTED) {
            $output->writeln(sprintf('  … et %d autre(s).', count($lines) - self::MAX_LISTED));
        }
    }
}
