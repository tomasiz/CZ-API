<?php

declare(strict_types=1);

use Casamiza\Domain\Auth\AuthUserRepositoryInterface;
use Casamiza\Domain\Auth\LoginAttemptRepositoryInterface;
use Casamiza\Domain\Auth\RefreshTokenRepositoryInterface;
use Casamiza\Domain\Company\CompanySettingsRepositoryInterface;
use Casamiza\Domain\Company\LogoStorageInterface;
use Casamiza\Domain\Dashboard\DashboardRepositoryInterface;
use Casamiza\Domain\Extra\ExtraRepositoryInterface;
use Casamiza\Domain\Guesty\GuestyDirectoryInterface;
use Casamiza\Domain\Guesty\GuestyReservationsInterface;
use Casamiza\Domain\Integration\IntegrationRepositoryInterface;
use Casamiza\Domain\Integration\ProviderRegistry;
use Casamiza\Domain\Invoice\Document\DocumentRendererInterface;
use Casamiza\Domain\Invoice\InvoiceFileStorageInterface;
use Casamiza\Domain\Invoice\InvoiceRepositoryInterface;
use Casamiza\Domain\Invoice\InvoiceRepositoryInvoicedMonths;
use Casamiza\Domain\Owner\OwnerRepositoryInterface;
use Casamiza\Domain\Property\PropertyRepositoryInterface;
use Casamiza\Domain\Reservation\ReservationRepositoryInterface;
use Casamiza\Domain\Reservation\StripeFeesGatewayInterface;
use Casamiza\Domain\Statement\InvoicedMonthsInterface;
use Casamiza\Domain\Statement\StatementRepositoryInterface;
use Casamiza\Domain\Team\InvitationNotifierInterface;
use Casamiza\Domain\Team\UserTokenRepositoryInterface;
use Casamiza\Domain\Tenant\TenantRepositoryInterface;
use Casamiza\Domain\User\UserRepositoryInterface;
use Casamiza\Infrastructure\Integration\GuestyClient;
use Casamiza\Infrastructure\Integration\GuestyProvider;
use Casamiza\Infrastructure\Integration\StripeClient;
use Casamiza\Infrastructure\Mail\LogInvitationNotifier;
use Casamiza\Infrastructure\Pdf\PdfDocumentRenderer;
use Casamiza\Infrastructure\Pdf\PdfFontDirectory;
use Casamiza\Infrastructure\Persistence\PdoAuthUserRepository;
use Casamiza\Infrastructure\Persistence\PdoCompanySettingsRepository;
use Casamiza\Infrastructure\Persistence\PdoDashboardRepository;
use Casamiza\Infrastructure\Persistence\PdoExtraRepository;
use Casamiza\Infrastructure\Persistence\PdoIntegrationRepository;
use Casamiza\Infrastructure\Persistence\PdoInvoiceRepository;
use Casamiza\Infrastructure\Persistence\PdoLoginAttemptRepository;
use Casamiza\Infrastructure\Persistence\PdoOwnerRepository;
use Casamiza\Infrastructure\Persistence\PdoPropertyRepository;
use Casamiza\Infrastructure\Persistence\PdoRefreshTokenRepository;
use Casamiza\Infrastructure\Persistence\PdoReservationRepository;
use Casamiza\Infrastructure\Persistence\PdoStatementRepository;
use Casamiza\Infrastructure\Persistence\PdoTenantRepository;
use Casamiza\Infrastructure\Persistence\PdoTransaction;
use Casamiza\Infrastructure\Persistence\PdoUserRepository;
use Casamiza\Infrastructure\Persistence\PdoUserTokenRepository;
use Casamiza\Infrastructure\Security\Encryptor;
use Casamiza\Infrastructure\Storage\FileInvoiceStorage;
use Casamiza\Infrastructure\Storage\FileLogoStorage;
use Casamiza\Shared\RequestContext;
use Casamiza\Shared\Settings;
use Casamiza\Shared\SystemClock;
use Casamiza\Shared\TenantContext;
use Casamiza\Shared\Transaction;

use function DI\get;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;

return [
    ResponseFactoryInterface::class => get(ResponseFactory::class),
    ClockInterface::class => get(SystemClock::class),

    AuthUserRepositoryInterface::class => get(PdoAuthUserRepository::class),
    RefreshTokenRepositoryInterface::class => get(PdoRefreshTokenRepository::class),
    LoginAttemptRepositoryInterface::class => get(PdoLoginAttemptRepository::class),
    TenantRepositoryInterface::class => get(PdoTenantRepository::class),
    UserRepositoryInterface::class => get(PdoUserRepository::class),
    UserTokenRepositoryInterface::class => get(PdoUserTokenRepository::class),
    InvitationNotifierInterface::class => get(LogInvitationNotifier::class),
    Transaction::class => get(PdoTransaction::class),

    IntegrationRepositoryInterface::class => get(PdoIntegrationRepository::class),
    GuestyDirectoryInterface::class => get(GuestyClient::class),
    GuestyReservationsInterface::class => get(GuestyClient::class),
    StripeFeesGatewayInterface::class => get(StripeClient::class),
    OwnerRepositoryInterface::class => get(PdoOwnerRepository::class),
    PropertyRepositoryInterface::class => get(PdoPropertyRepository::class),
    ReservationRepositoryInterface::class => get(PdoReservationRepository::class),
    ExtraRepositoryInterface::class => get(PdoExtraRepository::class),
    StatementRepositoryInterface::class => get(PdoStatementRepository::class),
    InvoiceRepositoryInterface::class => get(PdoInvoiceRepository::class),
    InvoicedMonthsInterface::class => get(InvoiceRepositoryInvoicedMonths::class),
    DashboardRepositoryInterface::class => get(PdoDashboardRepository::class),
    CompanySettingsRepositoryInterface::class => get(PdoCompanySettingsRepository::class),
    InvoiceFileStorageInterface::class => static fn(ContainerInterface $c): FileInvoiceStorage => new FileInvoiceStorage($c->get(Settings::class)->projectRoot . '/storage', $c->get(TenantContext::class)),
    LogoStorageInterface::class => static fn(ContainerInterface $c): FileLogoStorage => new FileLogoStorage($c->get(Settings::class)->projectRoot . '/storage'),
    DocumentRendererInterface::class => static fn(ContainerInterface $c): PdfDocumentRenderer => new PdfDocumentRenderer(
        new PdfFontDirectory($c->get(Settings::class)->projectRoot . '/resources/pdf/fonts', $c->get(Settings::class)->projectRoot . '/storage/cache'),
    ),
    ClientInterface::class => static fn(): Client => new Client(),
    Encryptor::class => static fn(ContainerInterface $c): Encryptor => new Encryptor($c->get(Settings::class)->encryptionKey),
    // Services tiers pris en charge : ajouter ici chaque nouveau fournisseur (Mailsend, Pennylane…).
    ProviderRegistry::class => static fn(ContainerInterface $c): ProviderRegistry => new ProviderRegistry([
        $c->get(GuestyProvider::class),
    ]),

    LoggerInterface::class => static function (ContainerInterface $c): Logger {
        $settings = $c->get(Settings::class);
        $request = $c->get(RequestContext::class);
        $tenant = $c->get(TenantContext::class);

        $handler = new RotatingFileHandler($settings->logPath, 14, Logger::toMonologLevel($settings->logLevel));
        $handler->setFormatter(new JsonFormatter());

        $logger = new Logger('casamiza', [$handler]);
        // Corrèle chaque ligne de log à la requête et au tenant, sans jamais y mettre de donnée personnelle.
        $logger->pushProcessor(static function (LogRecord $record) use ($request, $tenant): LogRecord {
            return $record->with(extra: $record->extra + [
                'request_id' => $request->requestId(),
                'tenant_id' => $tenant->idOrNull(),
            ]);
        });

        return $logger;
    },
];
