<?php

declare(strict_types=1);

namespace Casamiza\Domain\Tenant;

use Casamiza\Domain\User\PasswordPolicy;
use Casamiza\Domain\User\Role;
use Casamiza\Domain\User\User;
use Casamiza\Domain\User\UserRepositoryInterface;
use Casamiza\Infrastructure\Security\PasswordHasher;
use Casamiza\Shared\TenantContext;
use Casamiza\Shared\Transaction;
use Ramsey\Uuid\Uuid;

/** Création d'un tenant et de son premier administrateur (CLI : pas d'inscription publique). */
final readonly class TenantService
{
    public function __construct(
        private TenantRepositoryInterface $tenants,
        private UserRepositoryInterface $users,
        private PasswordHasher $hasher,
        private TenantContext $context,
        private Transaction $transaction,
    ) {}

    public function createWithAdmin(
        string $tenantName,
        string $firstName,
        string $lastName,
        string $email,
        string $password,
    ): Tenant {
        PasswordPolicy::assert($password);

        $tenant = new Tenant(Uuid::uuid7()->toString(), $tenantName);
        $admin = new User(
            Uuid::uuid7()->toString(),
            $tenant->id,
            $firstName,
            $lastName,
            strtolower($email),
            $this->hasher->hash($password),
            Role::Admin,
        );

        $this->transaction->run(function () use ($tenant, $admin): void {
            $this->tenants->add($tenant);
            $this->context->set($tenant->id);
            $this->users->add($admin);
        });

        return $tenant;
    }
}
