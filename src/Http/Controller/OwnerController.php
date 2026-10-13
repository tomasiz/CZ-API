<?php

declare(strict_types=1);

namespace Casamiza\Http\Controller;

use Casamiza\Domain\Owner\OwnerService;
use Casamiza\Http\Resource\OwnerResource;
use Casamiza\Http\Response\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class OwnerController
{
    public function __construct(
        private OwnerService $owners,
        private JsonResponder $json,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json->data($response, array_map(
            static fn(array $row): array => OwnerResource::toArray($row['owner'], $row['properties']),
            $this->owners->list(),
        ));
    }
}
