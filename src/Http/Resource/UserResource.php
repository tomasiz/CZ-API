<?php

declare(strict_types=1);

namespace Casamiza\Http\Resource;

use Casamiza\Domain\Tenant\Tenant;
use Casamiza\Domain\User\User;

/** Représentation JSON publique d'un utilisateur (jamais de hash de mot de passe). Clés en camelCase. */
final class UserResource
{
    /** @return array<string, mixed> */
    public static function toArray(User $user, Tenant $tenant): array
    {
        return [
            'id' => $user->id,
            'firstName' => $user->firstName,
            'lastName' => $user->lastName,
            'email' => $user->email,
            'role' => $user->role->value,
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
        ];
    }
}
