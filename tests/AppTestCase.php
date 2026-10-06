<?php

declare(strict_types=1);

namespace Casamiza\Tests;

use Casamiza\Infrastructure\Persistence\Database;
use Casamiza\Kernel;
use Casamiza\Shared\AuthSettings;
use Casamiza\Shared\Settings;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

abstract class AppTestCase extends TestCase
{
    public const ORIGIN = 'http://localhost:5173';

    public const JWT_SECRET = 'test-secret-test-secret-test-secret-0123456789';

    /** @param array<string, mixed> $overrides valeurs de Settings à substituer */
    protected function settings(string $env = 'dev', array $overrides = []): Settings
    {
        $defaults = [
            'appEnv' => $env,
            'corsOrigin' => self::ORIGIN,
            'dbHost' => '127.0.0.1',
            'dbPort' => 3306,
            'dbName' => 'test',
            'dbUser' => 'test',
            'dbPass' => '',
            'auth' => new AuthSettings(
                jwtSecret: self::JWT_SECRET,
                issuer: 'casamiza-test',
                audience: 'casamiza-admin',
                accessTtlMinutes: 15,
                refreshTtlDays: 7,
                sessionMaxDays: 30,
                cookieName: 'refresh_token',
                cookieSameSite: 'none',
                cookieSecure: true,
            ),
            'logPath' => sys_get_temp_dir() . '/casamiza-test.log',
            'logLevel' => 'emergency',
            'projectRoot' => dirname(__DIR__),
        ];

        return new Settings(...array_merge($defaults, $overrides));
    }

    /**
     * Application câblée sur une base SQLite en mémoire.
     *
     * @return App<ContainerInterface|null>
     */
    protected function app(string $env = 'dev'): App
    {
        $settings = $this->settings($env);
        $database = new Database($settings, new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));

        return Kernel::createApp($settings, [Database::class => $database], compileContainer: false);
    }

    /**
     * @param App<ContainerInterface|null> $app
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $json corps JSON
     * @param array<string, string> $cookies
     */
    protected function request(App $app, string $method, string $uri, array $headers = [], ?array $json = null, array $cookies = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri, ['REMOTE_ADDR' => '203.0.113.7']);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($json !== null) {
            $body = (new StreamFactory())->createStream(json_encode($json, JSON_THROW_ON_ERROR));
            $request = $request->withBody($body)->withHeader('Content-Type', 'application/json');
        }

        return $app->handle($request->withCookieParams($cookies));
    }

    /** @return array<string, mixed> */
    protected function json(ResponseInterface $response): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
