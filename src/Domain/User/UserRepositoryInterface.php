<?php

declare(strict_types=1);

namespace Casamiza\Domain\User;

/** Accès aux utilisateurs **du tenant courant** (implémentation bornée par BaseRepository). */
interface UserRepositoryInterface
{
    public function find(string $id): ?User;

    /** @return list<User> triés par prénom puis nom */
    public function all(): array;

    /** @throws \Casamiza\Shared\Exception\ConflictException `email_already_used` */
    public function add(User $user): void;

    public function updateName(string $id, string $firstName, string $lastName): void;

    public function updateRole(string $id, Role $role): void;

    public function delete(string $id): void;

    /**
     * Identifiants des administrateurs actifs, verrouillés (`FOR UPDATE`) jusqu'à la fin de la transaction :
     * deux modifications simultanées ne peuvent pas retirer ensemble les derniers administrateurs.
     *
     * @return list<string>
     */
    public function lockActiveAdminIds(): array;
}
