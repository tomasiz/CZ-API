<?php

declare(strict_types=1);

namespace Casamiza\Shared;

/** Paramètres d'authentification : jetons, durées de session, cookie du refresh token. */
final readonly class AuthSettings
{
    /**
     * @param non-empty-string $jwtSecret
     * @param non-empty-string $issuer
     * @param non-empty-string $audience
     */
    public function __construct(
        public string $jwtSecret,
        public string $issuer,
        public string $audience,
        public int $accessTtlMinutes,
        public int $refreshTtlDays,
        /** Durée de vie maximale d'une session, quelle que soit l'activité (0 = pas de plafond). */
        public int $sessionMaxDays,
        public string $cookieName,
        public string $cookieSameSite,
        public bool $cookieSecure,
    ) {}
}
