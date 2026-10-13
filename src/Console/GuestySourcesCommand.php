<?php

declare(strict_types=1);

namespace Casamiza\Console;

use Casamiza\Domain\Guesty\GuestyReservationsInterface;
use Casamiza\Shared\Exception\ApiException;
use Casamiza\Shared\TenantContext;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Diagnostic en lecture seule : liste, par valeur exacte de `source`, les réservations confirmées de Guesty (nombre,
 * clés de `integration`, exemple). Sert à figer l'énumération des plateformes (FACTURIZA.md §11.B.1). N'écrit rien.
 */
#[AsCommand(name: 'guesty:sources', description: 'Liste les valeurs de « source » des réservations Guesty (diagnostic, lecture seule)')]
final class GuestySourcesCommand extends Command
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly GuestyReservationsInterface $guesty,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Identifiant du tenant')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Check-out à partir du (AAAA-MM-JJ)', '2025-01-01');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tenant = $input->getOption('tenant');
        $since = $input->getOption('since');
        $from = is_string($since) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $since) : false;
        if (!is_string($tenant) || $tenant === '' || $from === false) {
            $output->writeln('Usage : guesty:sources --tenant=<id> [--since=AAAA-MM-JJ]');

            return Command::INVALID;
        }

        $this->context->reset();
        $this->context->set($tenant);

        try {
            $rows = $this->guesty->reservations($from);
        } catch (ApiException $e) {
            $output->writeln('Échec : ' . $e->errorCode());

            return Command::FAILURE;
        } finally {
            $this->context->reset();
        }

        /** @var array<string, array{count: int, keys: array<string, int>, example: string}> $groups */
        $groups = [];
        foreach ($rows as $row) {
            $group = $groups[$row->source] ?? ['count' => 0, 'keys' => [], 'example' => $row->guestyId];
            $group['count']++;
            foreach ($row->integrationKeys as $key) {
                $group['keys'][$key] = ($group['keys'][$key] ?? 0) + 1;
            }
            $groups[$row->source] = $group;
        }
        uasort($groups, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

        $output->writeln(sprintf('%d réservations confirmées (check-out depuis le %s)', count($rows), $from->format('Y-m-d')));
        foreach ($groups as $source => $group) {
            $keys = $group['keys'];
            arsort($keys);
            $output->writeln('');
            $output->writeln(sprintf('source = "%s" : %d', $source, $group['count']));
            $output->writeln('  clés de integration : ' . ($keys === [] ? '(aucune)' : implode(', ', array_map(
                static fn(string|int $name, int $n): string => sprintf('%s (%d)', $name, $n),
                array_keys($keys),
                $keys,
            ))));
            $output->writeln('  exemple : ' . $group['example']);
        }

        return Command::SUCCESS;
    }
}
