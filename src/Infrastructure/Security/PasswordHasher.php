<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Security;

/** Hachage argon2id des mots de passe. */
final class PasswordHasher
{
    private ?string $dummyHash = null;

    /** @param array<string, int> $options paramètres argon2id (par défaut ceux de PHP) ; les tests allègent le coût */
    public function __construct(private readonly array $options = []) {}

    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, $this->options);
    }

    /** Sans hash (utilisateur inconnu), on compare quand même à un hash factice pour égaliser le temps de réponse. */
    public function verify(string $password, ?string $hash): bool
    {
        if ($hash === null) {
            password_verify($password, $this->dummyHash ??= $this->hash(bin2hex(random_bytes(16))));

            return false;
        }

        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, $this->options);
    }
}
