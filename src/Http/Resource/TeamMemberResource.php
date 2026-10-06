<?php

declare(strict_types=1);

namespace Casamiza\Http\Resource;

use Casamiza\Domain\User\User;
use DateTimeInterface;

/** Représentation JSON d'un membre de l'équipe (jamais de hash ni de jeton). */
final class TeamMemberResource
{
    /** @return array<string, mixed> */
    public static function toArray(User $user): array
    {
        return [
            'id' => $user->id,
            'firstName' => $user->firstName,
            'lastName' => $user->lastName,
            'email' => $user->email,
            'role' => $user->role->value,
            'status' => $user->status->value,
            'lastLoginAt' => $user->lastLoginAt?->format(DateTimeInterface::ATOM),
        ];
    }
}
