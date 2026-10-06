<?php

declare(strict_types=1);

namespace Casamiza\Domain\Team;

use Casamiza\Domain\User\Role;
use Casamiza\Domain\User\User;
use Casamiza\Domain\User\UserRepositoryInterface;
use Casamiza\Domain\User\UserStatus;
use Casamiza\Shared\Exception\ConflictException;
use Casamiza\Shared\Exception\NotFoundException;
use Casamiza\Shared\Exception\UnprocessableException;
use Casamiza\Shared\Transaction;

/** Membres du tenant courant. Il reste toujours au moins un administrateur actif. */
final readonly class TeamService
{
    public function __construct(
        private UserRepositoryInterface $users,
        private Transaction $transaction,
    ) {}

    /** @return list<User> */
    public function list(): array
    {
        return $this->users->all();
    }

    public function update(string $id, ?string $firstName, ?string $lastName, ?Role $role): User
    {
        return $this->transaction->run(function () use ($id, $firstName, $lastName, $role): User {
            $user = $this->users->find($id) ?? throw new NotFoundException('user_not_found');

            if ($role !== null && $role !== $user->role) {
                if ($user->role === Role::Admin) {
                    $this->assertAnotherActiveAdminExists($user);
                }
                $this->users->updateRole($id, $role);
                $user = $user->withRole($role);
            }
            if ($firstName !== null || $lastName !== null) {
                $user = $user->withName($firstName ?? $user->firstName, $lastName ?? $user->lastName);
                $this->users->updateName($id, $user->firstName, $user->lastName);
            }

            return $user;
        });
    }

    public function remove(string $actorId, string $id): void
    {
        if ($actorId === $id) {
            throw new UnprocessableException('cannot_delete_self');
        }

        $this->transaction->run(function () use ($id): void {
            $user = $this->users->find($id) ?? throw new NotFoundException('user_not_found');
            if ($user->role === Role::Admin) {
                $this->assertAnotherActiveAdminExists($user);
            }
            $this->users->delete($id);
        });
    }

    /** Appelé dans une transaction : le verrou posé sur les administrateurs évite que deux requêtes les retirent ensemble. */
    private function assertAnotherActiveAdminExists(User $user): void
    {
        $adminIds = $this->users->lockActiveAdminIds();
        $others = array_filter($adminIds, static fn(string $adminId): bool => $adminId !== $user->id);

        // Un membre invité qui n'a pas encore activé son compte ne compte pas comme administrateur.
        if ($user->status === UserStatus::Active && $others === []) {
            throw new ConflictException('last_admin_required');
        }
    }
}
