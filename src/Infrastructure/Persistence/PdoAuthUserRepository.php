<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Persistence;

use Casamiza\Domain\Auth\AuthUserRepositoryInterface;
use Casamiza\Domain\User\User;
use DateTimeImmutable;

/** Requêtes de pré-authentification : chaque requête est bornée par e-mail unique ou par (tenant, utilisateur) vérifiés. */
final readonly class PdoAuthUserRepository implements AuthUserRepositoryInterface
{
    private const COLUMNS = 'u.id, u.tenant_id, u.first_name, u.last_name, u.email, u.password_hash, u.role, u.status, u.last_login_at';

    public function __construct(private Database $database) {}

    public function findByEmail(string $email): ?User
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT ' . self::COLUMNS . " FROM users u JOIN tenants t ON t.id = u.tenant_id
             WHERE u.email = :email AND u.status = 'active' AND t.status = 'active'",
        );
        $statement->execute(['email' => $email]);
        $row = $statement->fetch();

        return is_array($row) ? UserHydrator::fromRow($row) : null;
    }

    public function findInTenant(string $tenantId, string $userId): ?User
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT ' . self::COLUMNS . " FROM users u JOIN tenants t ON t.id = u.tenant_id
             WHERE u.tenant_id = :tenant AND u.id = :id AND t.status = 'active'",
        );
        $statement->execute(['tenant' => $tenantId, 'id' => $userId]);
        $row = $statement->fetch();

        return is_array($row) ? UserHydrator::fromRow($row) : null;
    }

    public function recordLogin(string $tenantId, string $userId, DateTimeImmutable $at): void
    {
        $this->database->pdo()
            ->prepare('UPDATE users SET last_login_at = :at WHERE tenant_id = :tenant AND id = :id')
            ->execute(['at' => DbDate::toDb($at), 'tenant' => $tenantId, 'id' => $userId]);
    }

    public function activate(string $tenantId, string $userId, string $passwordHash): void
    {
        $this->database->pdo()
            ->prepare("UPDATE users SET password_hash = :hash, status = 'active' WHERE tenant_id = :tenant AND id = :id")
            ->execute(['hash' => $passwordHash, 'tenant' => $tenantId, 'id' => $userId]);
    }

    public function updatePasswordHash(string $tenantId, string $userId, string $hash): void
    {
        $this->database->pdo()
            ->prepare('UPDATE users SET password_hash = :hash WHERE tenant_id = :tenant AND id = :id')
            ->execute(['hash' => $hash, 'tenant' => $tenantId, 'id' => $userId]);
    }
}
