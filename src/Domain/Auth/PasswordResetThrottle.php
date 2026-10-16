<?php

declare(strict_types=1);

namespace Casamiza\Domain\Auth;

use Casamiza\Shared\Exception\TooManyRequestsException;
use DateInterval;
use Psr\Clock\ClockInterface;

/**
 * Limite les demandes de réinitialisation : 3 par couple (e-mail, IP) et 30 échecs ou demandes par IP sur 15 minutes
 * (même table que les tentatives de connexion, avec une clé d'e-mail distincte). Le refus ne dépend pas de l'existence du compte.
 */
final readonly class PasswordResetThrottle
{
    private const WINDOW = 'PT15M';
    private const MAX_PER_EMAIL_AND_IP = 3;
    private const MAX_PER_IP = 30;

    public function __construct(
        private LoginAttemptRepositoryInterface $attempts,
        private ClockInterface $clock,
    ) {}

    /** Vérifie la limite puis compte cette demande. @throws TooManyRequestsException `too_many_attempts` */
    public function hit(string $email, string $ip): void
    {
        $now = $this->clock->now();
        $since = $now->sub(new DateInterval(self::WINDOW));
        $key = hash('sha256', 'password-reset:' . strtolower($email));

        if (
            $this->attempts->countFailures($key, $ip, $since) >= self::MAX_PER_EMAIL_AND_IP
            || $this->attempts->countFailuresByIp($ip, $since) >= self::MAX_PER_IP
        ) {
            throw new TooManyRequestsException('too_many_attempts');
        }
        $this->attempts->record($key, $ip, false, $now);
    }
}
