<?php

declare(strict_types=1);

namespace Casamiza\Tests\Auth;

use Casamiza\Domain\Auth\AuthUserRepositoryInterface;
use Casamiza\Domain\Auth\LoginAttemptRepositoryInterface;
use Casamiza\Domain\Auth\RefreshTokenRepositoryInterface;
use Casamiza\Domain\Company\CompanySettingsRepositoryInterface;
use Casamiza\Domain\Company\LogoStorageInterface;
use Casamiza\Domain\Dashboard\DashboardRepositoryInterface;
use Casamiza\Domain\Extra\ExtraRepositoryInterface;
use Casamiza\Domain\Integration\IntegrationRepositoryInterface;
use Casamiza\Domain\Invoice\Document\DocumentRendererInterface;
use Casamiza\Domain\Invoice\InvoiceFileStorageInterface;
use Casamiza\Domain\Invoice\InvoiceRepositoryInterface;
use Casamiza\Domain\Owner\OwnerRepositoryInterface;
use Casamiza\Domain\Property\PropertyRepositoryInterface;
use Casamiza\Domain\Reservation\ReservationRepositoryInterface;
use Casamiza\Domain\Statement\InvoicedMonthsInterface;
use Casamiza\Domain\Statement\StatementRepositoryInterface;
use Casamiza\Domain\Team\InvitationNotifierInterface;
use Casamiza\Domain\Team\UserTokenRepositoryInterface;
use Casamiza\Domain\Tenant\Tenant;
use Casamiza\Domain\Tenant\TenantRepositoryInterface;
use Casamiza\Domain\User\Role;
use Casamiza\Domain\User\User;
use Casamiza\Domain\User\UserRepositoryInterface;
use Casamiza\Infrastructure\Persistence\Database;
use Casamiza\Infrastructure\Security\PasswordHasher;
use Casamiza\Kernel;
use Casamiza\Shared\TenantContext;
use Casamiza\Shared\Transaction;
use Casamiza\Tests\AppTestCase;
use Casamiza\Tests\Support\FakeDocumentRenderer;
use Casamiza\Tests\Support\FixedClock;
use Casamiza\Tests\Support\ImmediateTransaction;
use Casamiza\Tests\Support\InMemoryCompanySettings;
use Casamiza\Tests\Support\InMemoryDashboard;
use Casamiza\Tests\Support\InMemoryExtras;
use Casamiza\Tests\Support\InMemoryIntegrations;
use Casamiza\Tests\Support\InMemoryInvitationNotifier;
use Casamiza\Tests\Support\InMemoryInvoiceFiles;
use Casamiza\Tests\Support\InMemoryInvoices;
use Casamiza\Tests\Support\InMemoryLoginAttempts;
use Casamiza\Tests\Support\InMemoryLogoStorage;
use Casamiza\Tests\Support\InMemoryOwners;
use Casamiza\Tests\Support\InMemoryProperties;
use Casamiza\Tests\Support\InMemoryRefreshTokens;
use Casamiza\Tests\Support\InMemoryReservations;
use Casamiza\Tests\Support\InMemoryStatements;
use Casamiza\Tests\Support\InMemoryTenants;
use Casamiza\Tests\Support\InMemoryUsers;
use Casamiza\Tests\Support\InMemoryUserTokens;
use Casamiza\Tests\Support\InvoicedMonths;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;

/** Application câblée sur des dépôts en mémoire, avec deux tenants et une horloge figée. */
abstract class AuthTestCase extends AppTestCase
{
    protected const PASSWORD = 'a-long-test-password';

    protected FixedClock $clock;
    protected InMemoryUsers $users;
    protected InMemoryTenants $tenants;
    protected InMemoryRefreshTokens $refreshTokens;
    protected InMemoryLoginAttempts $attempts;
    protected InMemoryUserTokens $userTokens;
    protected InMemoryInvitationNotifier $notifier;
    protected InMemoryIntegrations $integrations;
    protected InMemoryOwners $owners;
    protected InMemoryProperties $properties;
    protected InMemoryReservations $reservations;
    protected InMemoryExtras $extras;
    protected InMemoryStatements $statements;
    protected InvoicedMonths $invoiced;
    protected InMemoryInvoices $invoices;
    protected InMemoryInvoiceFiles $invoiceFiles;
    protected InMemoryDashboard $dashboard;
    protected InMemoryCompanySettings $company;
    protected InMemoryLogoStorage $logos;
    protected FakeDocumentRenderer $renderer;
    protected TenantContext $context;

    /** Réponses de l'API des services tiers (aucun appel réseau réel dans les tests). */
    protected MockHandler $thirdParty;

    /** @var \ArrayObject<int, array{request: \Psr\Http\Message\RequestInterface}> appels envoyés aux services tiers */
    protected \ArrayObject $thirdPartyCalls;

    /** @return list<string> chemins appelés sur les services tiers, dans l'ordre */
    protected function thirdPartyPaths(): array
    {
        return array_values(array_map(
            static fn(array $call): string => $call['request']->getUri()->getPath(),
            $this->thirdPartyCalls->getArrayCopy(),
        ));
    }

    /** @var App<\Psr\Container\ContainerInterface|null> */
    protected App $app;

    protected function setUp(): void
    {
        $this->clock = new FixedClock();
        $context = new TenantContext();
        $this->context = $context;
        $this->tenants = new InMemoryTenants();
        $this->users = new InMemoryUsers($context, $this->tenants);
        $this->refreshTokens = new InMemoryRefreshTokens();
        $this->attempts = new InMemoryLoginAttempts();
        $this->userTokens = new InMemoryUserTokens();
        $this->notifier = new InMemoryInvitationNotifier();
        $this->integrations = new InMemoryIntegrations($context);
        $this->owners = new InMemoryOwners($context);
        $this->properties = new InMemoryProperties($context);
        $this->reservations = new InMemoryReservations($context, $this->owners, $this->properties);
        $this->extras = new InMemoryExtras($context);
        $this->statements = new InMemoryStatements($context);
        $this->invoices = new InMemoryInvoices($context, $this->properties);
        $this->invoiceFiles = new InMemoryInvoiceFiles();
        $this->dashboard = new InMemoryDashboard($context, $this->invoices, $this->properties, $this->owners);
        $this->invoiced = new InvoicedMonths($this->invoices, $context);
        $this->company = new InMemoryCompanySettings($context);
        $this->logos = new InMemoryLogoStorage();
        $this->renderer = new FakeDocumentRenderer();
        $this->thirdParty = new MockHandler();
        $calls = new \ArrayObject();
        $this->thirdPartyCalls = $calls;
        $stack = HandlerStack::create($this->thirdParty);
        $stack->push(Middleware::history($calls));

        $this->tenants->add(new Tenant('tenant-a', 'Conciergerie A'));
        $this->tenants->add(new Tenant('tenant-b', 'Conciergerie B'));
        $hasher = new PasswordHasher(['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]);
        $hash = $hasher->hash(self::PASSWORD);
        $this->users->add(new User('user-admin', 'tenant-a', 'Alice', 'Admin', 'alice@a.test', $hash, Role::Admin));
        $this->users->add(new User('user-manager', 'tenant-a', 'Marc', 'Manager', 'marc@a.test', $hash, Role::Manager));
        $this->users->add(new User('user-b', 'tenant-b', 'Bob', 'Autre', 'bob@b.test', $hash, Role::Admin));
        $this->users->add(new User('user-admin2', 'tenant-a', 'Zoé', 'Admin', 'zoe@a.test', $hash, Role::Admin));

        $settings = $this->settings();
        $this->app = Kernel::createApp($settings, [
            Database::class => new Database($settings, new PDO('sqlite::memory:')),
            ClockInterface::class => $this->clock,
            TenantContext::class => $context,
            PasswordHasher::class => $hasher,
            AuthUserRepositoryInterface::class => $this->users,
            UserRepositoryInterface::class => $this->users,
            TenantRepositoryInterface::class => $this->tenants,
            RefreshTokenRepositoryInterface::class => $this->refreshTokens,
            LoginAttemptRepositoryInterface::class => $this->attempts,
            UserTokenRepositoryInterface::class => $this->userTokens,
            InvitationNotifierInterface::class => $this->notifier,
            IntegrationRepositoryInterface::class => $this->integrations,
            OwnerRepositoryInterface::class => $this->owners,
            PropertyRepositoryInterface::class => $this->properties,
            ReservationRepositoryInterface::class => $this->reservations,
            ExtraRepositoryInterface::class => $this->extras,
            StatementRepositoryInterface::class => $this->statements,
            InvoiceRepositoryInterface::class => $this->invoices,
            InvoiceFileStorageInterface::class => $this->invoiceFiles,
            DashboardRepositoryInterface::class => $this->dashboard,
            InvoicedMonthsInterface::class => $this->invoiced,
            CompanySettingsRepositoryInterface::class => $this->company,
            LogoStorageInterface::class => $this->logos,
            DocumentRendererInterface::class => $this->renderer,
            ClientInterface::class => new Client(['handler' => $stack]),
            Transaction::class => new ImmediateTransaction(),
        ], compileContainer: false);
    }

    /** @return array<string, string> */
    protected function browser(): array
    {
        return ['Origin' => self::ORIGIN];
    }

    protected function login(string $email = 'alice@a.test', string $password = self::PASSWORD): ResponseInterface
    {
        return $this->request($this->app, 'POST', '/api/v1/auth/login', $this->browser(), ['email' => $email, 'password' => $password]);
    }

    protected function accessToken(string $email = 'alice@a.test'): string
    {
        $data = $this->json($this->login($email))['data'];
        self::assertIsArray($data);

        return (string) $data['accessToken'];
    }

    /** @return array<string, string> */
    protected function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    protected function refreshCookie(ResponseInterface $response): string
    {
        foreach ($response->getHeader('Set-Cookie') as $line) {
            if (preg_match('/^refresh_token=([^;]*);/', $line, $m)) {
                return $m[1];
            }
        }
        self::fail('Cookie refresh_token absent.');
    }

    protected function refresh(string $cookie): ResponseInterface
    {
        return $this->request($this->app, 'POST', '/api/v1/auth/refresh', $this->browser(), null, ['refresh_token' => $cookie]);
    }

    protected function errorCode(ResponseInterface $response): string
    {
        $error = $this->json($response)['error'];
        self::assertIsArray($error);

        return (string) $error['code'];
    }
}
