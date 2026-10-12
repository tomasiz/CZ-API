<?php

declare(strict_types=1);

namespace Casamiza\Shared;

use InvalidArgumentException;

/** Configuration de l'application, lue une seule fois depuis l'environnement. */
final readonly class Settings
{
    public function __construct(
        public string $appEnv,
        public string $corsOrigin,
        public string $dbHost,
        public int $dbPort,
        public string $dbName,
        public string $dbUser,
        public string $dbPass,
        public AuthSettings $auth,
        public string $encryptionKey,
        public string $logPath,
        public string $logLevel,
        public string $projectRoot,
    ) {}

    /** @param array<string, mixed> $env */
    public static function fromEnv(array $env, string $projectRoot): self
    {
        $get = static function (string $key, ?string $default = null) use ($env): string {
            $value = $env[$key] ?? $default;
            if (!is_string($value) || $value === '') {
                throw new InvalidArgumentException("Variable d'environnement manquante : {$key}");
            }

            return $value;
        };

        $appEnv = $get('APP_ENV', 'prod');
        $sameSite = strtolower($get('COOKIE_SAMESITE', 'strict'));
        if (!in_array($sameSite, ['strict', 'lax', 'none'], true)) {
            throw new InvalidArgumentException('COOKIE_SAMESITE doit valoir strict, lax ou none.');
        }
        $secure = filter_var($get('COOKIE_SECURE', 'true'), FILTER_VALIDATE_BOOLEAN);
        if ($sameSite === 'none' && !$secure) {
            throw new InvalidArgumentException('SameSite=None exige COOKIE_SECURE=true.');
        }

        $jwtSecret = $get('JWT_SECRET');
        if (strlen($jwtSecret) < 32) {
            throw new InvalidArgumentException('JWT_SECRET doit contenir au moins 32 caractères.');
        }
        $refreshDays = self::positiveInt($get('REFRESH_TTL_DAYS', '7'), 'REFRESH_TTL_DAYS');
        $sessionMaxDays = (int) $get('SESSION_MAX_DAYS', '30');
        if ($sessionMaxDays !== 0 && $sessionMaxDays < $refreshDays) {
            throw new InvalidArgumentException('SESSION_MAX_DAYS doit être 0 (aucun plafond) ou >= REFRESH_TTL_DAYS.');
        }

        // 32 octets encodés en base64 : `php -r "echo base64_encode(random_bytes(32));"`
        $encryptionKey = base64_decode($get('APP_ENCRYPTION_KEY'), true);
        if ($encryptionKey === false || strlen($encryptionKey) !== 32) {
            throw new InvalidArgumentException('APP_ENCRYPTION_KEY doit être une clé de 32 octets encodée en base64.');
        }

        return new self(
            appEnv: $appEnv,
            corsOrigin: rtrim($get('CORS_ORIGIN'), '/'),
            dbHost: $get('DB_HOST'),
            dbPort: (int) $get('DB_PORT', '3306'),
            dbName: $get('DB_NAME'),
            dbUser: $get('DB_USER'),
            dbPass: (string) ($env['DB_PASS'] ?? ''),
            auth: new AuthSettings(
                jwtSecret: $jwtSecret,
                issuer: $get('APP_URL', 'casamiza-api'),
                audience: 'casamiza-admin',
                accessTtlMinutes: self::positiveInt($get('ACCESS_TTL_MINUTES', '15'), 'ACCESS_TTL_MINUTES'),
                refreshTtlDays: $refreshDays,
                sessionMaxDays: $sessionMaxDays,
                cookieName: 'refresh_token',
                cookieSameSite: $sameSite,
                cookieSecure: $secure,
            ),
            encryptionKey: $encryptionKey,
            logPath: $get('LOG_PATH', $projectRoot . '/storage/logs/app.log'),
            logLevel: $get('LOG_LEVEL', $appEnv === 'prod' ? 'info' : 'debug'),
            projectRoot: $projectRoot,
        );
    }

    private static function positiveInt(string $value, string $name): int
    {
        $int = (int) $value;
        if ($int < 1) {
            throw new InvalidArgumentException("{$name} doit être un entier positif.");
        }

        return $int;
    }

    public function isProd(): bool
    {
        return $this->appEnv === 'prod';
    }
}
