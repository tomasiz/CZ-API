<?php

declare(strict_types=1);

namespace Casamiza\Http\Controller;

use Casamiza\Http\Response\JsonResponder;
use Casamiza\Infrastructure\Persistence\Database;
use Casamiza\Shared\Exception\ServiceUnavailableException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/** Sonde de disponibilité (publique). Ne révèle ni version ni détail d'infrastructure. */
final readonly class HealthController
{
    public function __construct(
        private JsonResponder $json,
        private LoggerInterface $logger,
        private Database $database,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $this->database->pdo()->query('SELECT 1');
        } catch (Throwable $e) {
            $this->logger->error('Health check failed: database unavailable.', ['exception' => $e]);

            throw new ServiceUnavailableException('database_unavailable');
        }

        return $this->json->data($response, ['status' => 'ok']);
    }
}
