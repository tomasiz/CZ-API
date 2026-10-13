<?php

declare(strict_types=1);

namespace Casamiza\Console;

use Casamiza\Domain\Guesty\GuestySyncService;
use Casamiza\Domain\Integration\Provider;
use Casamiza\Domain\Tenant\TenantRepositoryInterface;
use Casamiza\Shared\Exception\ApiException;
use Casamiza\Shared\TenantContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Synchronisation nocturne : importe propriétaires et logements de Guesty pour chaque tenant qui l'a configuré.
 * Un tenant en échec est journalisé et n'empêche pas les suivants ; le code de sortie est alors non nul.
 * Cron (CloudPanel) : `0 3 * * * bin/php bin/console sync:guesty`.
 */
#[AsCommand(name: 'sync:guesty', description: 'Synchronise les propriétaires et les logements depuis Guesty (tous les tenants)')]
final class SyncGuestyCommand extends Command
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantContext $context,
        private readonly GuestySyncService $sync,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $failures = 0;
        foreach ($this->tenants->idsWithIntegration(Provider::Guesty) as $tenantId) {
            // Un processus qui traite plusieurs tenants : le contexte est remis à zéro avant chacun.
            $this->context->reset();
            $this->context->set($tenantId);

            try {
                $report = $this->sync->sync();
                $output->writeln(sprintf(
                    '%s : propriétaires +%d ~%d -%d, logements +%d ~%d -%d',
                    $tenantId,
                    $report->owners->created,
                    $report->owners->updated,
                    $report->owners->archived,
                    $report->properties->created,
                    $report->properties->updated,
                    $report->properties->archived,
                ));
            } catch (ApiException $e) {
                $failures++;
                $this->logger->error('Synchronisation Guesty échouée', ['code' => $e->errorCode()]);
                $output->writeln(sprintf('%s : échec (%s)', $tenantId, $e->errorCode()));
            } catch (Throwable $e) {
                $failures++;
                $this->logger->error('Synchronisation Guesty échouée', ['exception' => $e::class]);
                $output->writeln(sprintf('%s : échec (internal_error)', $tenantId));
            }
        }
        $this->context->reset();

        return $failures === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
