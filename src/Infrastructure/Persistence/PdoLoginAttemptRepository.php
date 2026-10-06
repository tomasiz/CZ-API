<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Persistence;

use Casamiza\Domain\Auth\LoginAttemptRepositoryInterface;
use DateTimeImmutable;

final readonly class PdoLoginAttemptRepository implements LoginAttemptRepositoryInterface
{
    public function __construct(private Database $database) {}

    public function record(string $emailHash, string $ip, bool $success, DateTimeImmutable $at): void
    {
        $this->database->pdo()
            ->prepare('INSERT INTO login_attempts (email_hash, ip, success, attempted_at) VALUES (:email, :ip, :success, :at)')
            ->execute(['email' => $emailHash, 'ip' => $ip, 'success' => $success ? 1 : 0, 'at' => DbDate::toDb($at)]);
    }

    public function countFailures(string $emailHash, string $ip, DateTimeImmutable $since): int
    {
        return $this->count(
            'email_hash = :email AND ip = :ip AND success = 0 AND attempted_at >= :since',
            ['email' => $emailHash, 'ip' => $ip, 'since' => DbDate::toDb($since)],
        );
    }

    public function countFailuresByIp(string $ip, DateTimeImmutable $since): int
    {
        return $this->count('ip = :ip AND success = 0 AND attempted_at >= :since', ['ip' => $ip, 'since' => DbDate::toDb($since)]);
    }

    public function purgeBefore(DateTimeImmutable $before): void
    {
        $this->database->pdo()
            ->prepare('DELETE FROM login_attempts WHERE attempted_at < :before')
            ->execute(['before' => DbDate::toDb($before)]);
    }

    /** @param array<string, string> $params */
    private function count(string $where, array $params): int
    {
        $statement = $this->database->pdo()->prepare("SELECT COUNT(*) FROM login_attempts WHERE {$where}");
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }
}
