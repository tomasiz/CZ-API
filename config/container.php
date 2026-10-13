<?php

declare(strict_types=1);

use Casamiza\Domain\Auth\AuthUserRepositoryInterface;
use Casamiza\Domain\Auth\LoginAttemptRepositoryInterface;
use Casamiza\Domain\Auth\RefreshTokenRepositoryInterface;
use Casamiza\Domain\Guesty\GuestyDirectoryInterface;
use Casamiza\Domain\Integration\IntegrationRepositoryInterface;
use Casamiza\Domain\Integration\ProviderRegistry;
use Casamiza\Domain\Owner\OwnerRepositoryInterface;
use Casamiza\Domain\Property\PropertyRepositoryInterface;
use Casamiza\Domain\Team\InvitationNotifierInterface;
use Casamiza\Domain\Team\UserTokenRepositoryInterface;
use Casamiza\Domain\Tenant\TenantRepositoryInterface;
use Casamiza\Domain\User\UserRepositoryInterface;
use Casamiza\Infrastructure\Integration\GuestyClient;
use Casamiza\Infrastructure\Integration\GuestyProvider;
use Casamiza\Infrastructure\Mail\LogInvitationNotifier;
use Casamiza\Infrastructure\Persistence\PdoAuthUserRepository;
use Casamiza\Infrastructure\Persistence\PdoIntegrationRepository;
use Casamiza\Infrastructure\Persistence\PdoLoginAttemptRepository;
use Casamiza\Infrastructure\Persistence\PdoOwnerRepository;
use Casamiza\Infrastructure\Persistence\PdoPropertyRepository;
use Casamiza\Infrastructure\Persistence\PdoRefreshTokenRepository;
use Casamiza\Infrastructure\Persistence\PdoTenantRepository;
use Casamiza\Infrastructure\Persistence\PdoTransaction;
use Casamiza\Infrastructure\Persistence\PdoUserRepository;
use Casamiza\Infrastructure\Persistence\PdoUserTokenRepository;
use Casamiza\Infrastructure\Security\Encryptor;
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
    OwnerRepositoryInterface::class => get(PdoOwnerRepository::class),
    PropertyRepositoryInterface::class => get(PdoPropertyRepository::class),
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
