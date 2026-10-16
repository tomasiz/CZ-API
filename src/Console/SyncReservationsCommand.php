<?php

declare(strict_types=1);

namespace Casamiza\Console;

use Casamiza\Domain\Integration\Provider;
use Casamiza\Domain\Reservation\ReservationSyncService;
use Casamiza\Domain\Statement\BillingMonth;
use Casamiza\Domain\Tenant\TenantRepositoryInterface;
use Casamiza\Shared\Exception\ApiException;
use Casamiza\Shared\TenantContext;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Importe les réservations de Guesty pour chaque tenant qui l'a configuré (après `sync:guesty`, qui crée les logements).
 *
 * Par défaut : check-out à partir du premier jour du mois précédent (les factures du mois M-1 sont éditées au début de M).
 * Reprise de l'historique : `--since=2025-01-01`. Un tenant en échec est journalisé et n'empêche pas les suivants.
 * Cron (CloudPanel) : `0 3 * * * bin/php bin/console sync:guesty && bin/php bin/console sync:reservations`.
 */
#[AsCommand(name: 'sync:reservations', description: 'Importe les réservations confirmées depuis Guesty (tous les tenants, ou --tenant)')]
final class SyncReservationsCommand extends Command
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantContext $context,
        private readonly ReservationSyncService $sync,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Check-out à partir du (AAAA-MM-JJ) ; défaut : 1er du mois précédent')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Un seul tenant (identifiant) au lieu de tous');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $since = $input->getOption('since');
        if ($since === null) {
            $from = BillingMonth::startOfPreviousMonth($this->clock->now());
        } else {
            $from = is_string($since) ? DateTimeImmutable::createFromFormat('!Y-m-d', $since, new \DateTimeZone('UTC')) : false;
            if ($from === false) {
                $output->writeln('Usage : sync:reservations [--since=AAAA-MM-JJ] [--tenant=<id>]');

                return Command::INVALID;
            }
        }
        $only = $input->getOption('tenant');

        $failures = 0;
        foreach ($this->tenants->idsWithIntegration(Provider::Guesty) as $tenantId) {
            if (is_string($only) && $only !== $tenantId) {
                continue;
            }
            // Un processus qui traite plusieurs tenants : le contexte est remis à zéro avant chacun.
            $this->context->reset();
            $this->context->set($tenantId);

            try {
                $report = $this->sync->sync($from);
                $output->writeln(sprintf(
                    '%s : réservations +%d ~%d -%d =%d (ignorées : %d, lectures Stripe : %d), check-out depuis le %s',
                    $tenantId,
                    $report->reservations->created,
                    $report->reservations->updated,
                    $report->reservations->archived,
                    $report->reservations->unchanged,
                    $report->skipped,
                    $report->stripeLookups,
                    $from->format('Y-m-d'),
                ));
            } catch (ApiException $e) {
                $failures++;
                $this->logger->error('Synchronisation des réservations échouée', ['code' => $e->errorCode()]);
                $output->writeln(sprintf('%s : échec (%s)', $tenantId, $e->errorCode()));
            } catch (Throwable $e) {
                $failures++;
                $this->logger->error('Synchronisation des réservations échouée', ['exception' => $e::class]);
                $output->writeln(sprintf('%s : échec (internal_error)', $tenantId));
            }
        }
        $this->context->reset();

        return $failures === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
