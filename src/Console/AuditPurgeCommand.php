<?php

declare(strict_types=1);

namespace Casamiza\Console;

use Casamiza\Domain\Audit\AuditService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'audit:purge', description: "Supprime les lignes du journal d'audit plus anciennes que la durée de conservation (13 mois par défaut)")]
final class AuditPurgeCommand extends Command
{
    public function __construct(private readonly AuditService $audit)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('months', null, InputOption::VALUE_REQUIRED, 'Durée de conservation en mois', (string) AuditService::RETENTION_MONTHS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $months = filter_var($input->getOption('months'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 120]]);
        if ($months === false) {
            $output->writeln('<error>--months doit être un entier entre 1 et 120.</error>');

            return Command::FAILURE;
        }

        $removed = $this->audit->purge($months);
        $output->writeln(sprintf('%d ligne(s) du journal supprimée(s) (plus de %d mois).', $removed, $months));

        return Command::SUCCESS;
    }
}
