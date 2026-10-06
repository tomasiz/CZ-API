<?php

declare(strict_types=1);

namespace Casamiza\Tests\Http;

use Casamiza\Tests\AppTestCase;

final class MiddlewareTest extends AppTestCase
{
    public function testSecurityHeadersAndRequestId(): void
    {
        $response = $this->request($this->app(), 'GET', '/health');

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $response->getHeaderLine('X-Request-Id'));
        self::assertFalse($response->hasHeader('Strict-Transport-Security'));
    }

    public function testCorsAllowsConfiguredOriginOnly(): void
    {
        $app = $this->app();

        $ok = $this->request($app, 'GET', '/health', ['Origin' => self::ORIGIN]);
        self::assertSame(self::ORIGIN, $ok->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $ok->getHeaderLine('Access-Control-Allow-Credentials'));

        $other = $this->request($app, 'GET', '/health', ['Origin' => 'https://evil.example']);
        self::assertFalse($other->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testPreflight(): void
    {
        $response = $this->request($this->app(), 'OPTIONS', '/api/v1/anything', [
            'Origin' => self::ORIGIN,
            'Access-Control-Request-Method' => 'POST',
        ]);

        self::assertSame(204, $response->getStatusCode());
        self::assertStringContainsString('Authorization', $response->getHeaderLine('Access-Control-Allow-Headers'));
    }

    public function testErrorsUseTheErrorEnvelopeWithCors(): void
    {
        $response = $this->request($this->app(), 'GET', '/nope', ['Origin' => self::ORIGIN]);
        $body = $this->json($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['error' => ['code' => 'endpoint_not_found']], $body);
        self::assertNotSame('', $response->getHeaderLine('X-Request-Id'));
        self::assertSame(self::ORIGIN, $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testMethodNotAllowedExposesAllowHeader(): void
    {
        $response = $this->request($this->app(), 'POST', '/health');

        self::assertSame(405, $response->getStatusCode());
        self::assertSame(['error' => ['code' => 'method_not_allowed']], $this->json($response));
        self::assertStringContainsString('GET', $response->getHeaderLine('Allow'));
    }
}
