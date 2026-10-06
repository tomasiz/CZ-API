<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Persistence;

use Casamiza\Domain\User\Role;
use Casamiza\Domain\User\User;
use Casamiza\Domain\User\UserRepositoryInterface;
use Casamiza\Shared\Exception\ConflictException;
use PDOException;

final class PdoUserRepository extends BaseRepository implements UserRepositoryInterface
{
    protected function table(): string
    {
        return 'users';
    }

    public function find(string $id): ?User
    {
        $row = $this->findRow($id);

        return $row === null ? null : UserHydrator::fromRow($row);
    }

    public function add(User $user): void
    {
        try {
            $this->insertRow([
                'id' => $user->id,
                'first_name' => $user->firstName,
                'last_name' => $user->lastName,
                'email' => $user->email,
                'password_hash' => $user->passwordHash,
                'role' => $user->role->value,
                'status' => $user->status->value,
            ]);
        } catch (PDOException $e) {
            // 1062 : doublon sur l'index unique des e-mails.
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw new ConflictException('email_already_used');
            }
            throw $e;
        }
    }

    public function updateName(string $id, string $firstName, string $lastName): void
    {
        $this->updateRow($id, ['first_name' => $firstName, 'last_name' => $lastName]);
    }

    public function all(): array
    {
        return array_map(
            UserHydrator::fromRow(...),
            $this->select(suffix: 'ORDER BY first_name, last_name'),
        );
    }

    public function updateRole(string $id, Role $role): void
    {
        $this->updateRow($id, ['role' => $role->value]);
    }

    public function delete(string $id): void
    {
        $this->deleteRow($id);
    }

    public function lockActiveAdminIds(): array
    {
        $rows = $this->select('id', "role = 'admin' AND status = 'active'", [], 'FOR UPDATE');

        return array_map(static fn(array $row): string => (string) $row['id'], $rows);
    }
}
