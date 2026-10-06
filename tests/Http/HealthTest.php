<?php

declare(strict_types=1);

namespace Casamiza\Tests\Http;

use Casamiza\Infrastructure\Persistence\Database;
use Casamiza\Kernel;
use Casamiza\Tests\AppTestCase;

final class HealthTest extends AppTestCase
{
    public function testHealthIsOk(): void
    {
        $response = $this->request($this->app(), 'GET', '/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['data' => ['status' => 'ok']], $this->json($response));
    }

    public function testHealthIsDegradedWhenDatabaseIsDown(): void
    {
        // Connexion MySQL impossible (aucun serveur sur ce port) : 503, sans détail technique.
        $settings = $this->settings(overrides: ['dbPort' => 1]);
        $broken = new Database($settings);
        $app = Kernel::createApp($settings, [Database::class => $broken]);

        $response = $this->request($app, 'GET', '/health');

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(['error' => ['code' => 'database_unavailable']], $this->json($response));
    }
}
