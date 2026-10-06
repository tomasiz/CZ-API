<?php

declare(strict_types=1);

namespace Casamiza\Tests\Auth;

use Casamiza\Domain\Auth\AuthUserRepositoryInterface;
use Casamiza\Domain\Auth\LoginAttemptRepositoryInterface;
use Casamiza\Domain\Auth\RefreshTokenRepositoryInterface;
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
use Casamiza\Tests\Support\FixedClock;
use Casamiza\Tests\Support\ImmediateTransaction;
use Casamiza\Tests\Support\InMemoryInvitationNotifier;
use Casamiza\Tests\Support\InMemoryLoginAttempts;
use Casamiza\Tests\Support\InMemoryRefreshTokens;
use Casamiza\Tests\Support\InMemoryTenants;
use Casamiza\Tests\Support\InMemoryUsers;
use Casamiza\Tests\Support\InMemoryUserTokens;
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

    /** @var App<\Psr\Container\ContainerInterface|null> */
    protected App $app;

    protected function setUp(): void
    {
        $this->clock = new FixedClock();
        $context = new TenantContext();
        $this->tenants = new InMemoryTenants();
        $this->users = new InMemoryUsers($context, $this->tenants);
        $this->refreshTokens = new InMemoryRefreshTokens();
        $this->attempts = new InMemoryLoginAttempts();
        $this->userTokens = new InMemoryUserTokens();
        $this->notifier = new InMemoryInvitationNotifier();

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
