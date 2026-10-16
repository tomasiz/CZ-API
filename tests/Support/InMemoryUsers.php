<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Auth\AuthUserRepositoryInterface;
use Casamiza\Domain\User\Role;
use Casamiza\Domain\User\User;
use Casamiza\Domain\User\UserRepositoryInterface;
use Casamiza\Domain\User\UserStatus;
use Casamiza\Shared\Exception\ConflictException;
use Casamiza\Shared\TenantContext;
use DateTimeImmutable;

/** Utilisateurs en mémoire : l'accès `UserRepositoryInterface` est borné au tenant courant, comme en base. */
final class InMemoryUsers implements AuthUserRepositoryInterface, UserRepositoryInterface
{
    /** @var array<string, User> */
    public array $users = [];

    public function __construct(private readonly TenantContext $context, private readonly InMemoryTenants $tenants) {}

    public function findByEmail(string $email): ?User
    {
        foreach ($this->users as $user) {
            if ($user->email === $email && $user->status === UserStatus::Active && $this->tenants->isActive($user->tenantId)) {
                return $user;
            }
        }

        return null;
    }

    public function findInTenant(string $tenantId, string $userId): ?User
    {
        $user = $this->users[$userId] ?? null;

        return $user !== null && $user->tenantId === $tenantId ? $user : null;
    }

    public function recordLogin(string $tenantId, string $userId, DateTimeImmutable $at): void {}

    public function activate(string $tenantId, string $userId, string $passwordHash): void
    {
        $user = $this->findInTenant($tenantId, $userId);
        if ($user !== null) {
            $this->users[$userId] = new User($user->id, $user->tenantId, $user->firstName, $user->lastName, $user->email, $passwordHash, $user->role, $user->lastLoginAt, UserStatus::Active);
        }
    }

    public function updatePasswordHash(string $tenantId, string $userId, string $hash): void
    {
        $user = $this->findInTenant($tenantId, $userId);
        if ($user !== null) {
            $this->users[$userId] = new User($user->id, $user->tenantId, $user->firstName, $user->lastName, $user->email, $hash, $user->role, $user->lastLoginAt, $user->status);
        }
    }

    public function find(string $id): ?User
    {
        return $this->findInTenant($this->context->id(), $id);
    }

    public function all(): array
    {
        $tenantId = $this->context->id();
        $members = array_values(array_filter($this->users, static fn(User $u): bool => $u->tenantId === $tenantId));
        usort($members, static fn(User $a, User $b): int => [$a->firstName, $a->lastName] <=> [$b->firstName, $b->lastName]);

        return $members;
    }

    public function add(User $user): void
    {
        foreach ($this->users as $existing) {
            if ($existing->email === $user->email) {
                throw new ConflictException('email_already_used');
            }
        }
        $this->users[$user->id] = $user;
    }

    public function updateName(string $id, string $firstName, string $lastName): void
    {
        $user = $this->find($id);
        if ($user !== null) {
            $this->users[$id] = $user->withName($firstName, $lastName);
        }
    }

    public function updateRole(string $id, Role $role): void
    {
        $user = $this->find($id);
        if ($user !== null) {
            $this->users[$id] = $user->withRole($role);
        }
    }

    public function delete(string $id): void
    {
        if ($this->find($id) !== null) {
            unset($this->users[$id]);
        }
    }

    public function lockActiveAdminIds(): array
    {
        return array_values(array_map(
            static fn(User $u): string => $u->id,
            array_filter($this->all(), static fn(User $u): bool => $u->role === Role::Admin && $u->status === UserStatus::Active),
        ));
    }
}
