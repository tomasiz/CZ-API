<?php

declare(strict_types=1);

namespace Casamiza\Tests\Http;

use Casamiza\Shared\Exception\ForbiddenException;
use Casamiza\Shared\Exception\NotFoundException;
use Casamiza\Shared\Exception\ValidationException;
use Casamiza\Tests\AppTestCase;
use RuntimeException;

/** Les erreurs de l'API sont des codes snake_case : jamais de texte rédigé. */
final class ErrorResponseTest extends AppTestCase
{
    /** @return array<string, array{\Throwable, int, string}> */
    public static function exceptions(): array
    {
        return [
            'not found' => [new NotFoundException('owner_not_found'), 404, 'owner_not_found'],
            'forbidden' => [new ForbiddenException(), 403, 'forbidden'],
            'unexpected' => [new RuntimeException('secret SQL detail'), 500, 'internal_error'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('exceptions')]
    public function testExceptionsBecomeCodes(\Throwable $exception, int $status, string $code): void
    {
        $app = $this->app('prod');
        $app->get('/boom', new ThrowingAction($exception));

        $response = $this->request($app, 'GET', '/boom');
        $body = $this->json($response);

        self::assertSame($status, $response->getStatusCode());
        self::assertSame(['error' => ['code' => $code]], $body);
        self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $body['error']['code']);
    }

    public function testValidationErrorsListCodesPerField(): void
    {
        $app = $this->app();
        $app->get('/invalid', new ThrowingAction(new ValidationException(['email' => ['invalid_email'], 'name' => ['required']])));

        $response = $this->request($app, 'GET', '/invalid');
        $body = $this->json($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_failed', $body['error']['code']);
        self::assertSame(['email' => ['invalid_email'], 'name' => ['required']], $body['error']['fields']);
    }

    public function testUnexpectedErrorsLeakNothingInProduction(): void
    {
        $app = $this->app('prod');
        $app->get('/boom', new ThrowingAction(new RuntimeException('secret SQL detail')));

        $response = $this->request($app, 'GET', '/boom');

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('secret', (string) $response->getBody());
        self::assertArrayNotHasKey('debug', $this->json($response));
    }

    public function testUnexpectedErrorsExposeDebugInDev(): void
    {
        $app = $this->app();
        $app->get('/boom', new ThrowingAction(new RuntimeException('secret SQL detail')));

        self::assertSame('secret SQL detail', $this->json($this->request($app, 'GET', '/boom'))['debug']['message'] ?? null);
    }
}
