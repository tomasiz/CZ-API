<?php

declare(strict_types=1);

namespace Casamiza\Http\Cookie;

use Casamiza\Shared\Settings;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Cookie `HttpOnly` du refresh token, limité aux routes d'authentification. */
final readonly class RefreshCookie
{
    private const PATH = '/api/v1/auth';

    public function __construct(private Settings $settings) {}

    public function read(ServerRequestInterface $request): ?string
    {
        $value = $request->getCookieParams()[$this->settings->auth->cookieName] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function issue(ResponseInterface $response, string $token, DateTimeImmutable $expiresAt): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', $this->build($token, $expiresAt));
    }

    public function clear(ResponseInterface $response): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', $this->build('', new DateTimeImmutable('@0')));
    }

    private function build(string $value, DateTimeImmutable $expiresAt): string
    {
        $auth = $this->settings->auth;
        $maxAge = max(0, $expiresAt->getTimestamp() - time());

        return sprintf(
            '%s=%s; Path=%s; Expires=%s; Max-Age=%d; HttpOnly;%s SameSite=%s',
            $auth->cookieName,
            $value,
            self::PATH,
            $expiresAt->setTimezone(new DateTimeZone('GMT'))->format('D, d M Y H:i:s \G\M\T'),
            $maxAge,
            $auth->cookieSecure ? ' Secure;' : '',
            ucfirst($auth->cookieSameSite),
        );
    }
}
