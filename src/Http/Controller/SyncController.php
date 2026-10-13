<?php

declare(strict_types=1);

namespace Casamiza\Http\Controller;

use Casamiza\Domain\Guesty\GuestySyncService;
use Casamiza\Http\Resource\SyncReportResource;
use Casamiza\Http\Response\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class SyncController
{
    public function __construct(
        private GuestySyncService $sync,
        private JsonResponder $json,
    ) {}

    /** Importe propriétaires et logements depuis Guesty ; la requête attend la fin et renvoie le bilan. */
    public function guesty(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json->data($response, SyncReportResource::toArray($this->sync->sync()));
    }
}
