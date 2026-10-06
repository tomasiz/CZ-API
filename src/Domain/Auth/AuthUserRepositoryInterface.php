<?php

declare(strict_types=1);

namespace Casamiza\Domain\Auth;

use Casamiza\Domain\User\User;
use DateTimeImmutable;

/**
 * Accès aux utilisateurs **avant** que le tenant soit connu (login par e-mail, refresh).
 * Exception assumée à BaseRepository : les e-mails sont uniques globalement, et chaque méthode
 * qui reçoit un tenant le fait venir d'une donnée déjà vérifiée (refresh token, JWT).
 */
interface AuthUserRepositoryInterface
{
    /** Utilisateur actif d'un tenant actif (un membre invité ne peut pas se connecter), ou null. */
    public function findByEmail(string $email): ?User;

    public function findInTenant(string $tenantId, string $userId): ?User;

    public function recordLogin(string $tenantId, string $userId, DateTimeImmutable $at): void;

    /** Définit le mot de passe d'un membre invité et active son compte. */
    public function activate(string $tenantId, string $userId, string $passwordHash): void;

    public function updatePasswordHash(string $tenantId, string $userId, string $hash): void;
}
