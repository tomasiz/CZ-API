<?php

declare(strict_types=1);

namespace Casamiza\Domain\Auth;

use Casamiza\Shared\Exception\TooManyRequestsException;
use Psr\Clock\ClockInterface;

/** Limite les essais de connexion : 5 échecs par couple (e-mail, IP) et 30 par IP sur 15 minutes. */
final readonly class LoginThrottle
{
    private const WINDOW = 'PT15M';
    private const MAX_PER_EMAIL_AND_IP = 5;
    private const MAX_PER_IP = 30;

    public function __construct(
        private LoginAttemptRepositoryInterface $attempts,
        private ClockInterface $clock,
    ) {}

    public function assertAllowed(string $email, string $ip): void
    {
        $since = $this->clock->now()->sub(new \DateInterval(self::WINDOW));

        if (
            $this->attempts->countFailures($this->hash($email), $ip, $since) >= self::MAX_PER_EMAIL_AND_IP
            || $this->attempts->countFailuresByIp($ip, $since) >= self::MAX_PER_IP
        ) {
            throw new TooManyRequestsException('too_many_attempts');
        }
    }

    public function record(string $email, string $ip, bool $success): void
    {
        $now = $this->clock->now();
        $this->attempts->record($this->hash($email), $ip, $success, $now);

        // Ménage opportuniste : on ne garde pas l'historique au-delà d'un jour.
        if (random_int(1, 100) === 1) {
            $this->attempts->purgeBefore($now->sub(new \DateInterval('P1D')));
        }
    }

    private function hash(string $email): string
    {
        return hash('sha256', strtolower($email));
    }
}
