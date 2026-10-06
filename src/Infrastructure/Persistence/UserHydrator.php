<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Persistence;

use Casamiza\Domain\User\Role;
use Casamiza\Domain\User\User;
use Casamiza\Domain\User\UserStatus;

final class UserHydrator
{
    /** @param array<array-key, mixed> $row */
    public static function fromRow(array $row): User
    {
        $id = (string) $row['id'];
        if ($id === '') {
            throw new \UnexpectedValueException('Utilisateur sans identifiant.');
        }

        return new User(
            id: $id,
            tenantId: (string) $row['tenant_id'],
            firstName: (string) $row['first_name'],
            lastName: (string) $row['last_name'],
            email: (string) $row['email'],
            passwordHash: isset($row['password_hash']) ? (string) $row['password_hash'] : null,
            role: Role::from((string) $row['role']),
            lastLoginAt: DbDate::fromDbOrNull($row['last_login_at'] ?? null),
            status: UserStatus::from((string) ($row['status'] ?? 'active')),
        );
    }
}
