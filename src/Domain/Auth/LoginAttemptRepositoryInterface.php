<?php

declare(strict_types=1);

namespace Casamiza\Domain\Auth;

use DateTimeImmutable;

/** Journal des tentatives de connexion (pré-authentification : pas de tenant, e-mail stocké haché). */
interface LoginAttemptRepositoryInterface
{
    public function record(string $emailHash, string $ip, bool $success, DateTimeImmutable $at): void;

    public function countFailures(string $emailHash, string $ip, DateTimeImmutable $since): int;

    public function countFailuresByIp(string $ip, DateTimeImmutable $since): int;

    public function purgeBefore(DateTimeImmutable $before): void;
}
