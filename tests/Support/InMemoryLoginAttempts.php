<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Auth\LoginAttemptRepositoryInterface;
use DateTimeImmutable;

final class InMemoryLoginAttempts implements LoginAttemptRepositoryInterface
{
    /** @var list<array{string, string, bool, DateTimeImmutable}> */
    public array $attempts = [];

    public function record(string $emailHash, string $ip, bool $success, DateTimeImmutable $at): void
    {
        $this->attempts[] = [$emailHash, $ip, $success, $at];
    }

    public function countFailures(string $emailHash, string $ip, DateTimeImmutable $since): int
    {
        return count(array_filter(
            $this->attempts,
            static fn(array $a): bool => $a[0] === $emailHash && $a[1] === $ip && !$a[2] && $a[3] >= $since,
        ));
    }

    public function countFailuresByIp(string $ip, DateTimeImmutable $since): int
    {
        return count(array_filter($this->attempts, static fn(array $a): bool => $a[1] === $ip && !$a[2] && $a[3] >= $since));
    }

    public function purgeBefore(DateTimeImmutable $before): void
    {
        $this->attempts = array_values(array_filter($this->attempts, static fn(array $a): bool => $a[3] >= $before));
    }
}
