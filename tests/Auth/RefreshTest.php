<?php

declare(strict_types=1);

namespace Casamiza\Tests\Auth;

final class RefreshTest extends AuthTestCase
{
    public function testRefreshRotatesTheToken(): void
    {
        $first = $this->refreshCookie($this->login());
        $response = $this->refresh($first);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame($first, $this->refreshCookie($response));
        $data = $this->json($response)['data'];
        self::assertIsArray($data);
        self::assertNotEmpty($data['accessToken']);
        self::assertSame('alice@a.test', $data['user']['email']);
    }

    public function testReusingAnOldTokenRevokesTheWholeSession(): void
    {
        $first = $this->refreshCookie($this->login());
        $second = $this->refreshCookie($this->refresh($first));

        $reuse = $this->refresh($first);
        self::assertSame(401, $reuse->getStatusCode());
        self::assertSame('refresh_token_reused', $this->errorCode($reuse));

        // Le jeton légitime de la même session est lui aussi révoqué.
        self::assertSame('invalid_refresh_token', $this->errorCode($this->refresh($second)));
    }

    public function testMissingOrUnknownCookieIsRejected(): void
    {
        $missing = $this->request($this->app, 'POST', '/api/v1/auth/refresh', $this->browser());
        self::assertSame('invalid_refresh_token', $this->errorCode($missing));
        self::assertSame('invalid_refresh_token', $this->errorCode($this->refresh('deadbeef')));
    }

    public function testSessionSlidesForSevenDaysOfInactivity(): void
    {
        $cookie = $this->refreshCookie($this->login());

        $this->clock->advance('+6 days');
        $cookie = $this->refreshCookie($this->refresh($cookie));

        // 6 jours + 6 jours : la session a glissé, donc elle est toujours valable.
        $this->clock->advance('+6 days');
        self::assertSame(200, $this->refresh($cookie)->getStatusCode());
    }

    public function testInactiveSessionExpiresAfterSevenDays(): void
    {
        $cookie = $this->refreshCookie($this->login());

        $this->clock->advance('+7 days +1 minute');

        self::assertSame('invalid_refresh_token', $this->errorCode($this->refresh($cookie)));
    }

    public function testSessionHasAnAbsoluteCapOfThirtyDays(): void
    {
        $cookie = $this->refreshCookie($this->login());

        // Une activité quotidienne prolonge la session… jusqu'au plafond de 30 jours.
        for ($day = 1; $day <= 29; ++$day) {
            $this->clock->advance('+1 day');
            $response = $this->refresh($cookie);
            self::assertSame(200, $response->getStatusCode(), "jour {$day}");
            $cookie = $this->refreshCookie($response);
        }

        $this->clock->advance('+1 day +1 minute');
        self::assertSame('invalid_refresh_token', $this->errorCode($this->refresh($cookie)));
    }

    public function testRefreshIsRejectedForAForeignOrigin(): void
    {
        $cookie = $this->refreshCookie($this->login());
        $response = $this->request($this->app, 'POST', '/api/v1/auth/refresh', ['Origin' => 'https://evil.example'], null, ['refresh_token' => $cookie]);

        self::assertSame('forbidden_origin', $this->errorCode($response));
    }

    public function testLogoutRevokesTheSessionAndClearsTheCookie(): void
    {
        $cookie = $this->refreshCookie($this->login());

        $logout = $this->request($this->app, 'POST', '/api/v1/auth/logout', $this->browser(), null, ['refresh_token' => $cookie]);

        self::assertSame(204, $logout->getStatusCode());
        self::assertStringContainsString('Max-Age=0', $logout->getHeaderLine('Set-Cookie'));
        self::assertSame('invalid_refresh_token', $this->errorCode($this->refresh($cookie)));
    }

    public function testLogoutWithoutCookieIsHarmless(): void
    {
        self::assertSame(204, $this->request($this->app, 'POST', '/api/v1/auth/logout', $this->browser())->getStatusCode());
    }
}
